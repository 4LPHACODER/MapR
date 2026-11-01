<?php

namespace App\Console\Commands;

use App\Support\ShapefileReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class BackfillHouseholdCbmsAttrsCommand extends Command
{
    protected $signature = 'mapr:backfill-household-cbms-attrs';

    protected $description = 'Backfill CBMS household size and family fields from shapefile without truncating records.';

    public function handle(): int
    {
        if (! Schema::hasColumn('households', 'source_household_size')) {
            $this->error('Run migrations first (source_household_size column missing).');

            return self::FAILURE;
        }

        $preferred = base_path('geospatial_data/CDRA Shapefiles/HOUSEHOLD 2022/CBMS2022HOUSEHOLD');
        $fallback = base_path('geospatial_data/CBMS22/CBMS22');
        $base = File::exists($preferred.'.dbf') ? $preferred : $fallback;
        $reader = new ShapefileReader($base);
        $updated = 0;

        foreach ($reader->records() as $record) {
            $attrs = $record['attrs'];
            $id = $attrs['ID'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }

            $ref = 'CBMS22-HH-'.$id;
            $payload = $this->attrsPayload($attrs);

            $affected = DB::table('households')->where('household_reference', $ref)->update([
                ...$payload,
                'updated_at' => now(),
            ]);

            if ($affected) {
                $updated++;
            }
        }

        $this->info("Household CBMS attributes updated: {$updated}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $attrs
     * @return array<string, int|string|null>
     */
    public function attrsPayload(array $attrs): array
    {
        return [
            'source_household_size' => $this->intOrNull($attrs['FAMILYSIZE'] ?? null),
            'source_nuclear_families' => $this->intOrNull($attrs['NO_NUCFAM'] ?? null),
            'source_no_males' => $this->intOrNull($attrs['NO_MALES'] ?? null),
            'source_no_females' => $this->intOrNull($attrs['NO_FEMALES'] ?? null),
            'source_line_number' => isset($attrs['LINENO_01']) ? trim((string) $attrs['LINENO_01']) : null,
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $n = (int) $value;

        return $n >= 0 ? $n : null;
    }
}
