<?php

namespace App\Console\Commands;

use App\Services\ImportedDocumentLinkRepair;
use Illuminate\Console\Command;

class RepairImportedDocumentLinksCommand extends Command
{
    protected $signature = 'mapr:repair-imported-document-links';

    protected $description = 'Link imported report documents to registry records by exact reference, without creating evidence or changing submitted reports.';

    public function handle(ImportedDocumentLinkRepair $repair): int
    {
        $result = $repair->repair();
        $this->info('Linked '.$result['linked'].' reference(s). Ambiguous matches left unresolved: '.$result['ambiguous'].'.');

        return self::SUCCESS;
    }
}
