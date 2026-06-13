<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Damage;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Setting;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-only data-management endpoints — full DB backup/restore, CSV
 * exports of the two business-critical tables, and the two destructive
 * "clean house" operations the club asked for (purge ALL reservations
 * and purge reservations older than 1 year).
 *
 * All destructive operations require the body to include
 *   { "confirmation": "VYMAZAŤ" }
 * so an accidental POST from a stale browser tab can't wipe production.
 * Audit entries are written for every destructive call.
 */
class AdminDataController extends Controller
{
    /** Tag visible in the export JSON for forward-compat. */
    private const EXPORT_VERSION = 1;

    public function __construct(private readonly AuditLogger $audit) {}

    /* ─────────────────────────────  EXPORTS  ─────────────────────────── */

    /**
     * GET /api/v1/admin/export/database.json
     *
     * Full JSON dump of business tables. `users` and
     * `personal_access_tokens` are intentionally excluded — they hold
     * hashed passwords that don't belong in a backup the operator
     * email-attaches.
     */
    public function exportDatabase(): JsonResponse
    {
        $payload = [
            'exportedAt' => CarbonImmutable::now('UTC')->toIso8601String(),
            'version' => self::EXPORT_VERSION,
            'tables' => [
                'resources' => Resource::query()->orderBy('identifier')->get()->toArray(),
                'events' => Event::query()->orderBy('startsAt')->get()->toArray(),
                'reservations' => Reservation::query()->orderBy('startsAt')->get()->toArray(),
                'event_participants' => EventParticipant::query()->orderBy('createdAt')->get()->toArray(),
                'damages' => Damage::query()->orderBy('reportedAt')->get()->toArray(),
                'audit_logs' => AuditLog::query()->orderBy('createdAt')->get()->toArray(),
                'settings' => Setting::query()->get()->toArray(),
            ],
        ];

        $filename = 'lodenica-backup-'.date('Y-m-d').'.json';

        return new JsonResponse($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * GET /api/v1/admin/export/reservations.csv
     */
    public function exportReservationsCsv(): StreamedResponse
    {
        return $this->streamCsv('lodenica-rezervacie-'.date('Y-m-d').'.csv', function ($out) {
            fputcsv($out, [
                'id', 'resourceId', 'resourceIdentifier', 'resourceName',
                'customerName', 'customerContact', 'startsAt', 'endsAt',
                'status', 'note', 'eventId', 'createdAt',
            ]);
            Reservation::with('resource')->orderBy('startsAt')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->id,
                        $r->resourceId,
                        $r->resource?->identifier ?? '',
                        $r->resource?->name ?? '',
                        $r->customerName,
                        $r->customerContact ?? '',
                        $this->iso($r->startsAt),
                        $this->iso($r->endsAt),
                        $r->status?->value,
                        $r->note ?? '',
                        $r->eventId ?? '',
                        $this->iso($r->createdAt),
                    ]);
                }
            });
        });
    }

    /**
     * GET /api/v1/admin/export/resources.csv
     */
    public function exportResourcesCsv(): StreamedResponse
    {
        return $this->streamCsv('lodenica-lode-'.date('Y-m-d').'.csv', function ($out) {
            fputcsv($out, [
                'id', 'identifier', 'type', 'name', 'model', 'color',
                'seats', 'lengthCm', 'weightKg', 'note', 'imageUrl',
                'isActive', 'createdAt',
            ]);
            Resource::query()->orderBy('type')->orderBy('identifier')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->id,
                        $r->identifier,
                        $r->type?->value,
                        $r->name,
                        $r->model ?? '',
                        $r->color ?? '',
                        $r->seats ?? '',
                        $r->lengthCm ?? '',
                        $r->weightKg ?? '',
                        $r->note ?? '',
                        $r->imageUrl ?? '',
                        $r->isActive ? '1' : '0',
                        $this->iso($r->createdAt),
                    ]);
                }
            });
        });
    }

    /* ─────────────────────────────  IMPORT  ──────────────────────────── */

    /**
     * POST /api/v1/admin/import/database
     *
     * Body: full export JSON from `exportDatabase()` + a typed
     * `confirmation: "VYMAZAŤ A OBNOVIŤ"` field. Wipes the seven
     * business tables and re-inserts from the payload inside a
     * transaction — so any failure mid-import rolls back to the
     * pre-import state.
     */
    public function importDatabase(Request $request): JsonResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:VYMAZAŤ A OBNOVIŤ'],
            'tables' => ['required', 'array'],
            'tables.resources' => ['sometimes', 'array'],
            'tables.events' => ['sometimes', 'array'],
            'tables.reservations' => ['sometimes', 'array'],
            'tables.event_participants' => ['sometimes', 'array'],
            'tables.damages' => ['sometimes', 'array'],
            'tables.audit_logs' => ['sometimes', 'array'],
            'tables.settings' => ['sometimes', 'array'],
        ]);

        $tables = (array) $request->input('tables');
        $counts = [];

        DB::transaction(function () use ($tables, &$counts) {
            // Delete order (reverse dependency): things that reference
            // others go first.
            DB::table('audit_logs')->delete();
            DB::table('event_participants')->delete();
            DB::table('damages')->delete();
            DB::table('reservations')->delete();
            DB::table('events')->delete();
            DB::table('resources')->delete();
            DB::table('settings')->delete();

            // Insert order (forward dependency).
            $counts['resources'] = $this->bulkInsert('resources', $tables['resources'] ?? []);
            $counts['events'] = $this->bulkInsert('events', $tables['events'] ?? []);
            $counts['settings'] = $this->bulkInsert('settings', $tables['settings'] ?? []);
            $counts['reservations'] = $this->bulkInsert('reservations', $tables['reservations'] ?? []);
            $counts['damages'] = $this->bulkInsert('damages', $tables['damages'] ?? []);
            $counts['event_participants'] = $this->bulkInsert('event_participants', $tables['event_participants'] ?? []);
            $counts['audit_logs'] = $this->bulkInsert('audit_logs', $tables['audit_logs'] ?? []);
        });

        $this->audit->logAction(
            AuditEntityType::SETTING,
            'database-restore',
            AuditAction::UPDATE,
            'Databáza obnovená z importu — '.array_sum($counts).' záznamov.',
            ['after' => ['counts' => $counts]],
        );

        return new JsonResponse(['ok' => true, 'inserted' => $counts]);
    }

    /* ─────────────────────────  PURGE OPERATIONS  ────────────────────── */

    /**
     * POST /api/v1/admin/reservations/purge
     *
     * Body:
     *   confirmation  — must equal "VYMAZAŤ"
     *   olderThanDays — optional integer; when set, only reservations
     *                   whose endsAt is strictly before (now − days)
     *                   are deleted. Use 365 for "older than a year".
     *
     * Cancellations are deleted along with confirmed bookings — the
     * goal is housekeeping, not an audit-trail purge.
     */
    public function purgeReservations(Request $request): JsonResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:VYMAZAŤ'],
            'olderThanDays' => ['nullable', 'integer', 'min:1', 'max:36500'],
        ]);

        $olderThanDays = $request->input('olderThanDays');
        $query = Reservation::query();
        $summary = '';

        if ($olderThanDays !== null) {
            $cutoff = CarbonImmutable::now('UTC')->subDays((int) $olderThanDays);
            $query->where('endsAt', '<', $cutoff);
            $summary = "Vymazané rezervácie staršie ako {$olderThanDays} dní";
        } else {
            $summary = 'Vymazané VŠETKY rezervácie';
        }

        $count = (clone $query)->count();
        $query->delete();

        $this->audit->logAction(
            AuditEntityType::RESERVATION,
            'bulk-purge',
            AuditAction::DELETE,
            "{$summary} ({$count}).",
            ['after' => ['deleted' => $count, 'olderThanDays' => $olderThanDays]],
        );

        return new JsonResponse(['ok' => true, 'deleted' => $count]);
    }

    /* ─────────────────────────────  HELPERS  ─────────────────────────── */

    /** @param  callable(resource): void  $writer */
    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->stream(
            function () use ($writer) {
                $out = fopen('php://output', 'w');
                // Excel-friendly UTF-8 BOM so Slovak diacritics render
                // correctly when the user double-clicks the file.
                fwrite($out, "\xEF\xBB\xBF");
                $writer($out);
                fclose($out);
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    /**
     * Eloquent's toArray cast already turns enums + dates into scalars,
     * but the JSON keys come straight from column names so we can
     * shove them back into the same table with DB::insert.
     */
    private function bulkInsert(string $table, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }
        // Insert in batches of 200 to keep memory + statement size in check.
        $count = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            // Coerce nested objects (cast results) back to scalars
            // since DB::table->insert() expects scalar columns.
            $coerced = array_map(fn ($row) => $this->coerceRow($row), $chunk);
            DB::table($table)->insert($coerced);
            $count += count($coerced);
        }

        return $count;
    }

    private function coerceRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $out[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $out[$key] = $value ? 1 : 0;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function iso(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return (string) $value;
    }
}
