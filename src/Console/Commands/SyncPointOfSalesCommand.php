<?php

namespace Modules\Arca\Console\Commands;

use Illuminate\Console\Command;
use Modules\Arca\ArcaManager;
use Throwable;

class SyncPointOfSalesCommand extends Command
{
    protected $signature = 'arca:sync-points-of-sale {--dry-run : Show ARCA points and counters without writing to the database}';

    protected $description = 'Synchronize local points of sale and voucher counters with ARCA.';

    public function handle(ArcaManager $arca): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $salesPoints = $dryRun
                ? $arca->pointOfSales()->fromArca(recordOperations: false)
                : $arca->pointOfSales()->syncFromArca();
        } catch (Throwable $exception) {
            $this->error('Could not retrieve ARCA points of sale: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $rows = [];

            foreach ($salesPoints as $salesPoint) {
                foreach ($salesPoint['counters'] as $counter) {
                    $rows[] = [
                        $salesPoint['number'],
                        $salesPoint['name'],
                        $salesPoint['active'] ? 'Yes' : 'No',
                        $counter['label'],
                        $counter['last_number'],
                        $counter['next_number'],
                    ];
                }
            }

            if ($rows !== []) {
                $this->table(['Point', 'Name', 'Active', 'Voucher', 'Last number', 'Next number'], $rows);
            }

            $this->info(count($salesPoints).' ARCA point(s) of sale found. No local changes were made.');

            return self::SUCCESS;
        }

        $this->info(count($salesPoints).' point(s) of sale synchronized with ARCA.');

        return self::SUCCESS;
    }
}
