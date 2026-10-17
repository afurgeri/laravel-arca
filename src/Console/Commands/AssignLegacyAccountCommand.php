<?php

namespace Modules\Arca\Console\Commands;

use Illuminate\Console\Command;
use Modules\Arca\ArcaScope;
use Modules\Arca\Contracts\ArcaStorage;
use RuntimeException;

class AssignLegacyAccountCommand extends Command
{
    protected $signature = 'arca:assign-legacy {--cuit=} {--environment=} {--dry-run}';

    protected $description = 'Assign existing unscoped ARCA records to one CUIT and environment.';

    public function handle(ArcaStorage $storage): int
    {
        $cuit = (string) $this->option('cuit');
        $environment = (string) $this->option('environment');

        if (preg_match('/^\d{11}$/D', $cuit) !== 1 || ! in_array($environment, ['testing', 'production'], true)) {
            $this->error('Provide an 11-digit --cuit and --environment=testing or production.');

            return self::FAILURE;
        }

        $scope = new ArcaScope($cuit, $environment);
        $counts = $storage->legacySummary($scope);

        foreach ($counts as $model => $count) {
            if ($count['conflicting'] > 0) {
                $this->error("{$model} contains partially assigned or differently assigned records; no changes made.");

                return self::FAILURE;
            }

        }

        foreach ($counts as $model => $count) {
            $this->line($model.": {$count['unassigned']} record(s)");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run only; no records were changed.');

            return self::SUCCESS;
        }

        try {
            $storage->assignLegacy($scope);
        } catch (RuntimeException $exception) {
            $this->error('Legacy assignment failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Unscoped ARCA records assigned successfully.');

        return self::SUCCESS;
    }
}
