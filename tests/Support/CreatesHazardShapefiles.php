<?php

namespace Tests\Support;

use App\Support\ResidentHazardCatalog;
use Illuminate\Support\Facades\File;

trait CreatesHazardShapefiles
{
    private function createHazardShapefiles(string $root): void
    {
        foreach (ResidentHazardCatalog::criticalPoints() as $definition) {
            $name = match ($definition['id']) {
                'evacuation' => 'Barangay Dagocdoc Covered Court',
                'health-station' => 'Brgy Dagocdoc Health Station',
                default => 'Test public facility',
            };
            $attrs = [$definition['name_field'] => $name];
            foreach ([$definition['barangay_field'], $definition['subtype_field']] as $field) {
                if ($field !== null) {
                    $attrs[$field] = 'Test value';
                }
            }
            $attrs += ['Population' => '99', 'HHCBMS' => 'private-id', 'contact' => 'private-phone'];
            // Exercise both geographic points and projected school points.
            $projected = $definition['id'] === 'school';
            $content = pack('V', 1).pack('e2', ...($projected ? [850000.0, 1000000.0] : [126.2, 9.07]));
            $this->writeHazardShapefile($root.'/'.substr($definition['source'], 0, -4), 1, $content, $attrs, $projected);
        }

        $ring = [[850000.0, 1000000.0], [850100.0, 1000000.0],
            [850100.0, 1000100.0], [850000.0, 1000100.0], [850000.0, 1000000.0]];
        $polygon = pack('V', 5).pack('e4', 850000.0, 1000000.0, 850100.0, 1000100.0)
            .pack('V3', 1, count($ring), 0);
        foreach ($ring as $point) {
            $polygon .= pack('e2', ...$point);
        }
        $this->writeHazardShapefile($root.'/geospatial_data/CDRA Shapefiles/Boundary/Tandag_brgy_Bndy_utm',
            5, $polygon, ['Brgy_' => 'Dagocdoc', 'PSGC' => '166819003', 'HHCBMS' => 'private-id'], true);
        $this->writeHazardShapefile($root.'/geospatial_data/CDRA Shapefiles/River Creek/River_utm',
            5, $polygon, ['Name' => 'Test river', 'HHCBMS' => 'private-id'], true);
    }

    /** @param array<string, string> $attrs */
    private function writeHazardShapefile(string $base, int $type, string $content, array $attrs, bool $projected): void
    {
        File::ensureDirectoryExists(dirname($base));
        $header = pack('N7', 9994, 0, 0, 0, 0, 0, (108 + strlen($content)) / 2)
            .pack('V2', 1000, $type).str_repeat("\0", 64);
        file_put_contents($base.'.shp', $header.pack('N2', 1, strlen($content) / 2).$content);
        file_put_contents($base.'.prj', $projected ? 'PROJCS["WGS 84 / UTM zone 51N"]' : 'GEOGCS["WGS 84"]');

        $fields = '';
        $record = ' ';
        foreach ($attrs as $name => $value) {
            $length = strlen($value);
            $fields .= str_pad($name, 11, "\0").'C'.str_repeat("\0", 4)
                .pack('C', $length).str_repeat("\0", 15);
            $record .= $value;
        }
        $dbfHeader = pack('C4', 3, 126, 1, 1).pack('Vvv', 1, 33 + strlen($fields), strlen($record))
            .str_repeat("\0", 20);
        file_put_contents($base.'.dbf', $dbfHeader.$fields."\r".$record."\x1a");
    }
}
