<?php

namespace Modules\Arca\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Arca\Models\ArcaOperation;
use Modules\Arca\Models\PointOfSale;
use Modules\Arca\Models\Voucher;
use RuntimeException;

class AssignLegacyAccountCommand extends Command
{
    protected $signature = 'arca:assign-legacy {--cuit=} {--environment=} {--dry-run}';

    protected $description = 'Assign existing unscoped ARCA records to one CUIT and environment.';

    public function handle(): int
    {
        $cuit = (string) $this->option('cuit');
        $environment = (string) $this->option('environment');

        if (preg_match('/^\d{11}$/D', $cuit) !== 1 || ! in_array($environment, ['testing', 'production'], true)) {
            $this->error('Provide an 11-digit --cuit and --environment=testing or production.');

            return self::FAILURE;
        }

        $models = [PointOfSale::class, Voucher::class, ArcaOperation::class];
        $counts = [];

        foreach ($models as $model) {
            $query = $model::query();
            $conflicting = (clone $query)->where(function ($query) use ($cuit, $environment): void {
                $query->where(function ($query): void {
                    $query->whereNull('cuit')->whereNotNull('environment')
                        ->orWhereNotNull('cuit')->whereNull('environment');
                })->orWhere(function ($query) use ($cuit, $environment): void {
                    $query->whereNotNull('cuit')->whereNotNull('environment')
                        ->where(function ($query) use ($cuit, $environment): void {
                            $query->where('cuit', '!=', $cuit)->orWhere('environment', '!=', $environment);
                        });
                });
            })->count();

            if ($conflicting > 0) {
                $this->error("{$model} contains partially assigned or differently assigned records; no changes made.");

                return self::FAILURE;
            }

            $counts[$model] = (clone $query)->whereNull('cuit')->whereNull('environment')->count();
        }

        foreach ($counts as $model => $count) {
            $this->line(class_basename($model).": {$count} record(s)");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run only; no records were changed.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($models, $cuit, $environment): void {
                foreach ($models as $model) {
                    $model::query()->whereNull('cuit')->whereNull('environment')->update([
                        'cuit' => $cuit,
                        'environment' => $environment,
                    ]);
                }
            });
        } catch (RuntimeException $exception) {
            $this->error('Legacy assignment failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Unscoped ARCA records assigned successfully.');

        return self::SUCCESS;
    }
}
