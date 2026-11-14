<?php

namespace App\Console\Commands;

use App\Services\AssessorStudyBarangayAssignment;
use App\Services\ReportWorkflow;
use Illuminate\Console\Command;

class AssignAssessorBarangaysCommand extends Command
{
    protected $signature = 'mapr:assign-assessor-barangays {--backfill : Also link orphaned handed-off barangay reports to an assigned assessor}';

    protected $description = 'Assign the four study barangays to every active Assessor without changing accounts or report history.';

    public function handle(AssessorStudyBarangayAssignment $assignment, ReportWorkflow $workflow): int
    {
        $result = $assignment->assignActiveAssessors();
        $this->info('Assessors updated: '.$result['assessors'].'. New assignments: '.$result['assigned'].'. Already assigned: '.$result['already_assigned'].'.');
        if ($result['missing_barangays'] !== []) {
            $this->warn('Barangay codes not found, so no row was created for: '.implode(', ', $result['missing_barangays']).'.');
        }

        if ($this->option('backfill')) {
            $backfill = $workflow->backfillAssessorAssignmentsForReviewQueue();
            $this->info(
                'Review queue backfill — scanned: '.$backfill['scanned']
                .', linked: '.$backfill['linked']
                .', already linked: '.$backfill['skipped']
                .', missing assessor: '.$backfill['missing_assessor'].'.'
            );
        }

        return self::SUCCESS;
    }
}
