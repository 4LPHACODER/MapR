<?php

namespace App\Console\Commands;

use App\Services\AssessorStudyBarangayAssignment;
use Illuminate\Console\Command;

class AssignAssessorBarangaysCommand extends Command
{
    protected $signature = 'mapr:assign-assessor-barangays';

    protected $description = 'Assign the four study barangays to every active Assessor without changing accounts or report history.';

    public function handle(AssessorStudyBarangayAssignment $assignment): int
    {
        $result = $assignment->assignActiveAssessors();
        $this->info('Assessors updated: '.$result['assessors'].'. New assignments: '.$result['assigned'].'. Already assigned: '.$result['already_assigned'].'.');
        if ($result['missing_barangays'] !== []) {
            $this->warn('Barangay codes not found, so no row was created for: '.implode(', ', $result['missing_barangays']).'.');
        }

        return self::SUCCESS;
    }
}
