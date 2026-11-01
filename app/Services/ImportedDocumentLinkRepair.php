<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportedDocumentLinkRepair
{
    public function repair(): array
    {
        if (! Schema::hasTable('imported_report_documents')) {
            return ['linked' => 0, 'ambiguous' => 0];
        }

        $linked = 0;
        $ambiguous = 0;
        $rows = DB::table('imported_report_documents')->get();
        foreach ($rows as $row) {
            $updates = [];
            $unresolved = json_decode((string) ($row->unresolved_identifiers ?? '[]'), true);
            if (! is_array($unresolved)) {
                $unresolved = [];
            }
            foreach ([
                ['column' => 'resolved_resident_id', 'source' => 'source_resident_reference', 'table' => 'residents', 'match' => 'registry_reference', 'token' => 'resident'],
                ['column' => 'resolved_building_id', 'source' => 'source_building_reference', 'table' => 'buildings', 'match' => 'building_reference', 'token' => 'building'],
                ['column' => 'resolved_household_id', 'source' => 'source_household_reference', 'table' => 'households', 'match' => 'household_reference', 'token' => 'household'],
            ] as $link) {
                if (! empty($row->{$link['column']}) || empty($row->{$link['source']}) || ! Schema::hasTable($link['table'])) {
                    continue;
                }
                $matches = DB::table($link['table'])->where($link['match'], $row->{$link['source']})->pluck('id');
                $token = $link['token'].':'.$row->{$link['source']};
                if ($matches->count() === 1) {
                    $updates[$link['column']] = (int) $matches->first();
                    $unresolved = array_values(array_filter($unresolved, fn ($item) => $item !== $token));
                    $linked++;
                } elseif ($matches->count() > 1) {
                    if (! in_array($token, $unresolved, true)) {
                        $unresolved[] = $token;
                    }
                    $ambiguous++;
                }
            }
            if ($updates !== [] || $unresolved !== json_decode((string) ($row->unresolved_identifiers ?? '[]'), true)) {
                $updates['unresolved_identifiers'] = json_encode(array_values($unresolved));
                $updates['updated_at'] = now();
                DB::table('imported_report_documents')->where('id', $row->id)->update($updates);
            }
        }

        return ['linked' => $linked, 'ambiguous' => $ambiguous];
    }
}
