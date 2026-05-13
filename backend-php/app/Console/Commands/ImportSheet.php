<?php

namespace App\Console\Commands;

use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Damage;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Import boat inventory from the club Google Sheet into the `resources` table.
 *
 * Mirrors the original NestJS port (`backend/prisma/import-sheet.ts`):
 *   - Section headers in the sheet map to `ResourceType` (Morské kajaky →
 *     SEA_KAYAK, Ostatné kajaky → WW_KAYAK, Kánoe → CANOE, Nafukovačky →
 *     INFLATABLE_BOAT, Pramice → ROWING_BOAT, Prívesy → TRAILER).
 *   - Missing `Číslo` (or literal "nema") is synthesized with a section
 *     prefix and a running counter so we keep referential integrity.
 *   - Duplicate identifiers across sections get a `-2` (or higher) suffix.
 *   - Sheet quirk: K78 has its model in the `cm` column — handled.
 *
 * Destructive by design: wipes damages, reservations and resources first
 * (boathouse spaces are recreated unconditionally), then re-imports.
 * Confirm prompt requires `--force` to bypass for non-interactive runs.
 *
 * Usage:
 *   php artisan lodenica:import-sheet              # interactive
 *   php artisan lodenica:import-sheet --force      # CI/cron-friendly
 *   php artisan lodenica:import-sheet --no-sample-reservations
 *   php artisan lodenica:import-sheet --csv-file=/path/to/local.csv
 */
class ImportSheet extends Command
{
    protected $signature = 'lodenica:import-sheet
        {--sheet-id=1PfWpT2bBr37j2W8G_JBrMCXskL-906kyDQ17oQocFCY : Google Sheet ID}
        {--gid=2002765643 : Sheet tab GID}
        {--csv-file= : Read CSV from a local file instead of fetching}
        {--force : Skip the destructive-action confirmation prompt}
        {--no-sample-reservations : Do not insert demo reservations after import}';

    protected $description = 'Import boat inventory from the club Google Sheet (destructive).';

    private const SECTION_MAP = [
        'Morské kajaky'  => [ResourceType::SEA_KAYAK,       'morský'],
        'Ostatné kajaky' => [ResourceType::WW_KAYAK,        'riečny'],
        'Kánoe'          => [ResourceType::CANOE,           'kanoe'],
        'Nafukovačky'    => [ResourceType::INFLATABLE_BOAT, 'nafukovačka'],
        'Pramice'        => [ResourceType::ROWING_BOAT,     'pramica'],
        'Prívesy'        => [ResourceType::TRAILER,         'príves'],
    ];

    public function handle(): int
    {
        $csv = $this->loadCsv();
        $this->info(sprintf('Got %d bytes of CSV.', strlen($csv)));

        $inventory = $this->parseInventory($csv);
        $this->info(sprintf('Parsed %d inventory rows.', count($inventory)));

        if ($inventory === []) {
            $this->error('No inventory rows parsed — refusing to wipe DB with empty payload.');

            return self::FAILURE;
        }

        if (!$this->option('force')) {
            $existing = Resource::query()->count();
            $msg = "This will WIPE damages, events, reservations and the {$existing} existing resources, "
                 . "then import {$inventory[0]['identifier']} … and ".count($inventory)." rows.\n"
                 . 'Continue?';
            if (!$this->confirm($msg, false)) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($inventory) {
            // FK order: damages → reservations → resources, plus events tree.
            Damage::query()->delete();
            Reservation::query()->delete();
            EventParticipant::query()->delete();
            Event::query()->delete();
            Resource::query()->delete();

            // Boathouse spaces are not in the sheet — recreate unconditionally.
            Resource::create([
                'identifier' => 'SPACE-NOVA',
                'type' => ResourceType::BOATHOUSE_SPACE,
                'name' => 'Nová lodenica',
                'note' => 'Hlavná lodenica — moderná hala.',
            ]);
            Resource::create([
                'identifier' => 'SPACE-STARA',
                'type' => ResourceType::BOATHOUSE_SPACE,
                'name' => 'Stará lodenica',
                'note' => 'Pôvodná lodenica.',
            ]);

            foreach ($inventory as $row) {
                Resource::create($row);
            }
        });

        $this->info(sprintf('Inserted %d resources from sheet (plus 2 boathouse spaces).', count($inventory)));

        if (!$this->option('no-sample-reservations')) {
            $this->seedSampleReservations();
        }

        $this->printTotals();

        return self::SUCCESS;
    }

    private function loadCsv(): string
    {
        $file = $this->option('csv-file');
        if ($file) {
            $this->info("Reading CSV from {$file}…");
            $content = @file_get_contents($file);
            if ($content === false) {
                throw new \RuntimeException("Could not read CSV file: {$file}");
            }

            return $content;
        }

        $sheetId = $this->option('sheet-id');
        $gid = $this->option('gid');
        $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
        $this->info("Fetching {$url}…");
        $response = Http::withOptions(['allow_redirects' => true])->timeout(30)->get($url);
        if (!$response->successful()) {
            throw new \RuntimeException("Failed to fetch sheet: HTTP {$response->status()}");
        }

        return $response->body();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseInventory(string $csv): array
    {
        $rows = $this->parseCsvRows($csv);
        $out = [];
        $currentSection = null;
        $sectionCounter = 0;
        $usedIds = [];

        foreach ($rows as $idx => $rawRow) {
            $cells = array_map(
                static fn ($c) => trim((string) ($c ?? '')),
                array_slice($rawRow, 0, 7),
            );
            $cells = array_pad($cells, 7, '');
            [$rawId, $rawTyp, $rawColor, $rawSeats, $rawCm, $rawKg, $rawNote] = $cells;

            // Skip fully empty rows
            if (!array_filter($cells, fn ($c) => $c !== '')) {
                continue;
            }
            // Skip header row
            if ($idx === 0 && $rawId === 'Číslo') {
                continue;
            }

            // Section header → switch context
            $sectionName = $this->detectSectionHeader($cells);
            if ($sectionName !== null) {
                $currentSection = self::SECTION_MAP[$sectionName];
                $sectionCounter = 0;
                continue;
            }

            if ($currentSection === null) {
                continue;
            }
            if ($rawTyp === '' && $rawId === '') {
                continue;
            }

            [$sectionType, $sectionTag] = $currentSection;

            $identifier = $rawId;
            $lengthCm = $this->parsePositiveInt($rawCm);
            $model = $rawTyp !== '' ? $rawTyp : null;

            // Sheet quirk: K78 has model written in the `cm` column.
            if ($model === null
                && $rawCm !== ''
                && !preg_match('/^\d+$/', preg_replace('/\s+/', '', $rawCm))) {
                $model = $rawCm;
                $lengthCm = null;
            }

            // Synthesize identifier when the sheet has none ("nema" or empty).
            if ($identifier === '' || mb_strtolower($identifier) === 'nema') {
                $sectionCounter++;
                $prefix = match ($sectionType) {
                    ResourceType::ROWING_BOAT => 'P',
                    ResourceType::TRAILER => 'T',
                    ResourceType::INFLATABLE_BOAT => 'N',
                    ResourceType::CANOE => 'C-X',
                    default => 'K-X',
                };
                $identifier = $prefix . str_pad((string) $sectionCounter, 2, '0', STR_PAD_LEFT);
            }

            // Disambiguate duplicate identifiers across sections (sheet has K91 twice).
            $unique = $identifier;
            $suffix = 1;
            while (isset($usedIds[$unique])) {
                $suffix++;
                $unique = "{$identifier}-{$suffix}";
            }
            $usedIds[$unique] = true;

            $noteParts = [];
            if ($rawNote !== '') {
                $noteParts[] = $rawNote;
            }
            if ($sectionTag !== 'kanoe') {
                array_unshift($noteParts, "({$sectionTag})");
            }

            $name = $model !== null
                ? mb_convert_case($sectionTag, MB_CASE_TITLE, 'UTF-8') . ' ' . $model
                : $sectionTag . ' ' . $unique;

            $out[] = [
                'identifier' => $unique,
                'type' => $sectionType,
                'name' => $name,
                'model' => $model,
                'color' => $rawColor !== '' ? $rawColor : null,
                'seats' => $this->parsePositiveInt($rawSeats),
                'lengthCm' => $lengthCm,
                'weightKg' => $this->parsePositiveInt($rawKg),
                'note' => $noteParts !== [] ? implode(' ', $noteParts) : null,
                'isActive' => true,
            ];
        }

        return $out;
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open temp stream for CSV parsing.');
        }
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    /** @param list<string> $cells */
    private function detectSectionHeader(array $cells): ?string
    {
        if ($cells === []) {
            return null;
        }
        $first = $cells[0];
        if ($first === '') {
            return null;
        }
        for ($i = 1, $n = count($cells); $i < $n; $i++) {
            if ($cells[$i] !== '') {
                return null;
            }
        }

        return isset(self::SECTION_MAP[$first]) ? $first : null;
    }

    private function parsePositiveInt(string $v): ?int
    {
        $trimmed = preg_replace('/\s+/', '', $v);
        if ($trimmed === '' || $trimmed === null) {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $trimmed)) {
            return null;
        }
        $n = (int) $trimmed;

        return $n > 0 ? $n : null;
    }

    private function seedSampleReservations(): void
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $atTime = static fn (int $dayOffset, int $hour): CarbonImmutable
            => $today->addDays($dayOffset)->setTime($hour, 0, 0);

        $kayaks = Resource::query()
            ->whereIn('type', [ResourceType::SEA_KAYAK->value, ResourceType::WW_KAYAK->value])
            ->where('isActive', true)
            ->orderBy('identifier')
            ->take(5)
            ->get();

        $canoes = Resource::query()
            ->where('type', ResourceType::CANOE->value)
            ->where('isActive', true)
            ->orderBy('identifier')
            ->take(2)
            ->get();

        $novaLodenica = Resource::query()
            ->where('type', ResourceType::BOATHOUSE_SPACE->value)
            ->where('identifier', 'SPACE-NOVA')
            ->first();

        $samples = [];
        if ($kayaks->get(0)) {
            $samples[] = ['resourceId' => $kayaks[0]->id, 'customerName' => 'Ján Novák',
                'startsAt' => $atTime(0, 9), 'endsAt' => $atTime(0, 12),
                'note' => 'Tréning na Dunaji.'];
            $samples[] = ['resourceId' => $kayaks[0]->id, 'customerName' => 'Peter Slovák',
                'startsAt' => $atTime(0, 14), 'endsAt' => $atTime(0, 17),
                'note' => 'Popoludňajšia jazda.'];
        }
        if ($kayaks->get(1)) {
            $samples[] = ['resourceId' => $kayaks[1]->id, 'customerName' => 'Eva Kováčová',
                'startsAt' => $atTime(0, 10), 'endsAt' => $atTime(0, 13)];
        }
        if ($kayaks->get(2)) {
            $samples[] = ['resourceId' => $kayaks[2]->id, 'customerName' => 'Martin R.',
                'startsAt' => $atTime(1, 0), 'endsAt' => $atTime(2, 0)];
        }
        if ($kayaks->get(3)) {
            $samples[] = ['resourceId' => $kayaks[3]->id, 'customerName' => 'Klubová akcia',
                'startsAt' => $atTime(2, 8), 'endsAt' => $atTime(2, 18),
                'note' => 'Celodenné podujatie.'];
        }
        if ($canoes->get(0)) {
            $samples[] = ['resourceId' => $canoes[0]->id, 'customerName' => 'Skupina víkend',
                'startsAt' => $atTime(1, 0), 'endsAt' => $atTime(4, 0),
                'note' => 'Víkendová tura.'];
        }
        if ($novaLodenica) {
            $samples[] = ['resourceId' => $novaLodenica->id, 'customerName' => 'Otvorený deň',
                'startsAt' => $atTime(5, 9), 'endsAt' => $atTime(5, 14)];
        }

        if ($samples === []) {
            $this->warn('No sample reservations seeded (no kayaks/canoes/spaces found).');

            return;
        }

        foreach ($samples as $s) {
            Reservation::create($s + ['status' => ReservationStatus::CONFIRMED]);
        }
        $this->info(sprintf('Inserted %d sample reservations.', count($samples)));
    }

    private function printTotals(): void
    {
        $rows = DB::table('resources')
            ->selectRaw('type, count(*) as c')
            ->groupBy('type')
            ->orderBy('type')
            ->get();

        $this->line('');
        $this->line('Final inventory by type:');
        foreach ($rows as $r) {
            $this->line(sprintf('  %-20s  %d', $r->type, $r->c));
        }
    }
}
