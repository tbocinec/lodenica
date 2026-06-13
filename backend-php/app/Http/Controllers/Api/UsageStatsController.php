<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Models\Damage;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/admin/usage-stats — admin-only dashboard data.
 *
 * Returns five small datasets the frontend renders as charts/tables:
 *
 *   - `topResources` (10 most-booked active resources in the last 90 days)
 *   - `coldResources` (10 active resources with zero or near-zero bookings)
 *   - `monthlyTrend` (count of reservations per month for the last 6 months)
 *   - `peakHours` (24x7 heat map of CONFIRMED bookings)
 *   - `damagesByType` (open damage counts per resource type)
 *
 * All counts are CONFIRMED-only (cancelled bookings don't reflect real use).
 * Time window is rolling, anchored at "now in UTC" because reservation
 * timestamps are stored as wall-clock UTC.
 */
class UsageStatsController extends Controller
{
    public function show(): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->subDays(90);
        $sixMonthsAgo = $now->subMonths(6)->startOfMonth();

        return new JsonResponse([
            'generatedAt' => $now->toIso8601String(),
            'window' => [
                'fromIso' => $cutoff->toIso8601String(),
                'toIso' => $now->toIso8601String(),
                'days' => 90,
            ],
            'totals' => $this->totals(),
            'topResources' => $this->topResources($cutoff),
            'coldResources' => $this->coldResources($cutoff),
            'monthlyTrend' => $this->monthlyTrend($sixMonthsAgo),
            'peakHours' => $this->peakHours($cutoff),
            'damagesByType' => $this->damagesByType(),
        ]);
    }

    /** @return array<string, int> */
    private function totals(): array
    {
        return [
            'reservationsAllTime' => Reservation::query()
                ->where('status', ReservationStatus::CONFIRMED->value)
                ->count(),
            'activeResources' => Resource::query()->where('isActive', true)->count(),
            'openDamages' => Damage::query()->where('status', '!=', 'FIXED')->count(),
        ];
    }

    /**
     * Top 10 resources by CONFIRMED-reservation count in the window.
     *
     * @return list<array{resourceId:string,identifier:string,name:string,type:string,count:int,totalHours:float}>
     */
    private function topResources(CarbonImmutable $cutoff): array
    {
        $rows = DB::table('reservations as r')
            ->join('resources as res', 'r.resourceId', '=', 'res.id')
            ->select(
                'r.resourceId',
                'res.identifier',
                'res.name',
                'res.type',
                DB::raw('COUNT(*) as cnt'),
                DB::raw($this->durationSumExpression()),
            )
            ->where('r.status', ReservationStatus::CONFIRMED->value)
            ->where('r.startsAt', '>=', $cutoff)
            ->groupBy('r.resourceId', 'res.identifier', 'res.name', 'res.type')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();

        return $rows->map(fn ($r) => [
            'resourceId' => $r->resourceId,
            'identifier' => $r->identifier,
            'name' => $r->name,
            'type' => (string) $r->type,
            'count' => (int) $r->cnt,
            'totalHours' => round((float) ($r->total_hours ?? 0), 1),
        ])->all();
    }

    /**
     * Cold storage — active resources with the FEWEST bookings in window.
     * Useful for spotting under-used boats before the club buys more.
     *
     * @return list<array{resourceId:string,identifier:string,name:string,type:string,count:int}>
     */
    private function coldResources(CarbonImmutable $cutoff): array
    {
        $rows = DB::table('resources as res')
            ->leftJoin('reservations as r', function ($join) use ($cutoff) {
                $join->on('res.id', '=', 'r.resourceId')
                    ->where('r.status', '=', ReservationStatus::CONFIRMED->value)
                    ->where('r.startsAt', '>=', $cutoff);
            })
            ->select(
                'res.id as resourceId',
                'res.identifier',
                'res.name',
                'res.type',
                DB::raw('COUNT(r.id) as cnt'),
            )
            ->where('res.isActive', true)
            ->whereNotIn('res.type', ['BOATHOUSE_SPACE'])
            ->groupBy('res.id', 'res.identifier', 'res.name', 'res.type')
            ->orderBy('cnt')
            ->orderBy('res.identifier')
            ->limit(10)
            ->get();

        return $rows->map(fn ($r) => [
            'resourceId' => $r->resourceId,
            'identifier' => $r->identifier,
            'name' => $r->name,
            'type' => (string) $r->type,
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * Reservations per calendar month for the last 6 months.
     *
     * @return list<array{monthIso:string,count:int}>
     */
    private function monthlyTrend(CarbonImmutable $from): array
    {
        $rows = Reservation::query()
            ->where('status', ReservationStatus::CONFIRMED->value)
            ->where('startsAt', '>=', $from)
            ->get(['startsAt']);

        $buckets = [];
        for ($d = $from; $d <= CarbonImmutable::now('UTC')->startOfMonth(); $d = $d->addMonth()) {
            $buckets[$d->format('Y-m')] = 0;
        }
        foreach ($rows as $r) {
            $key = ($r->startsAt instanceof \DateTimeInterface ? $r->startsAt : new \DateTimeImmutable((string) $r->startsAt))
                ->format('Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]++;
            }
        }

        $out = [];
        foreach ($buckets as $month => $count) {
            $out[] = ['monthIso' => $month.'-01', 'count' => $count];
        }

        return $out;
    }

    /**
     * 24×7 heat map: how many CONFIRMED reservations were *active* at each
     * hour-of-day × day-of-week combination, summed over the window. We
     * iterate each reservation row and tag every hour it covered.
     *
     * Cheap enough for a typical small club (a few hundred reservations
     * over 90 days); skips the heavy SQL window functions that the
     * SQLite test driver doesn't have.
     *
     * @return array{counts: array<int, array<int, int>>, max: int}
     */
    private function peakHours(CarbonImmutable $cutoff): array
    {
        // counts[dayOfWeek 0-6 (Mon-Sun)][hour 0-23] = int
        $counts = array_fill(0, 7, array_fill(0, 24, 0));
        $max = 0;

        $rows = Reservation::query()
            ->where('status', ReservationStatus::CONFIRMED->value)
            ->where('startsAt', '>=', $cutoff)
            ->get(['startsAt', 'endsAt']);

        foreach ($rows as $r) {
            $start = $r->startsAt instanceof \DateTimeInterface
                ? CarbonImmutable::instance($r->startsAt)->utc()
                : CarbonImmutable::parse((string) $r->startsAt)->utc();
            $end = $r->endsAt instanceof \DateTimeInterface
                ? CarbonImmutable::instance($r->endsAt)->utc()
                : CarbonImmutable::parse((string) $r->endsAt)->utc();

            // Iterate the [start, end) half-open range in 1-hour steps,
            // bucketing each hour into its (dow, hour) cell. We bound the
            // walk to a sane ceiling so a pathological 30-day booking
            // doesn't run forever.
            $cursor = $start->minute(0)->second(0);
            $steps = 0;
            while ($cursor < $end && $steps < 24 * 35) {
                // Carbon's dayOfWeekIso: Mon=1 … Sun=7. Convert to 0..6.
                $dow = $cursor->dayOfWeekIso - 1;
                $hour = $cursor->hour;
                $counts[$dow][$hour]++;
                if ($counts[$dow][$hour] > $max) {
                    $max = $counts[$dow][$hour];
                }
                $cursor = $cursor->addHour();
                $steps++;
            }
        }

        return ['counts' => $counts, 'max' => $max];
    }

    /**
     * Open-damages (status != FIXED) grouped by resource type so admins
     * see at a glance which type of boat is breaking most.
     *
     * @return list<array{type:string,count:int}>
     */
    private function damagesByType(): array
    {
        $rows = DB::table('damages as d')
            ->join('resources as r', 'd.resourceId', '=', 'r.id')
            ->select('r.type', DB::raw('COUNT(*) as cnt'))
            ->where('d.status', '!=', 'FIXED')
            ->groupBy('r.type')
            ->orderByDesc('cnt')
            ->get();

        return $rows->map(fn ($r) => [
            'type' => (string) $r->type,
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * Driver-specific SQL for summing reservation durations in HOURS.
     * Postgres has EXTRACT(EPOCH FROM ...); SQLite has julianday().
     */
    private function durationSumExpression(): string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            return 'COALESCE(SUM(EXTRACT(EPOCH FROM ("endsAt" - "startsAt")))/3600.0, 0) as total_hours';
        }
        // SQLite path (tests). `julianday` returns days as a float.
        return 'COALESCE(SUM((julianday("endsAt") - julianday("startsAt")) * 24.0), 0) as total_hours';
    }
}
