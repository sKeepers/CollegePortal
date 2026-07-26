<?php

namespace App\Console\Commands;

use App\Models\ApplicantApplication;
use App\Services\ApplicantDocumentRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncApplicantDocumentRegistryCommand extends Command
{
    protected $signature = 'admissions:sync-document-registry {--dry-run : Show planned changes without writing} {--apply : Create missing registry records}';

    protected $description = 'Synchronize applicant document registry records from Reference Data.';

    public function handle(ApplicantDocumentRegistryService $registry): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! $this->option('apply');
        $types = $registry->documentTypes();
        $applications = ApplicantApplication::query()->legacy()->with('documents')->get();
        $planned = 0;
        $legacyLinked = 0;

        foreach ($applications as $application) {
            $existingTypeIds = $application->documents->pluck('document_type_id')->filter()->all();
            $existingCodes = $application->documents->pluck('type')->map(fn ($type) => $type === 'consent' ? 'personal_data_consent' : $type)->all();

            foreach ($types as $type) {
                if (! in_array($type->id, $existingTypeIds, true) && ! in_array($type->code, $existingCodes, true)) {
                    $planned++;
                }
            }

            $legacyLinked += $application->documents->whereNull('document_type_id')->count();
        }

        $this->info('Applications: '.$applications->count());
        $this->info('Document types: '.$types->count());
        $this->info('Missing registry records: '.$planned);
        $this->info('Legacy records to link: '.$legacyLinked);
        $this->info('Mode: '.($dryRun ? 'dry-run' : 'apply'));

        if ($dryRun) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($applications, $registry): void {
            foreach ($applications as $application) {
                $registry->syncLegacyDocumentTypes($application);
                $registry->ensureRegistry($application, 'sync_command');
            }
        });

        $this->info('Registry synchronized. No files were created.');

        return self::SUCCESS;
    }
}
