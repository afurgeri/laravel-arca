<?php

namespace Modules\Arca\Infrastructure\Persistence;

use Illuminate\Database\Migrations\Migration;
use RuntimeException;

abstract class ArcaMigration extends Migration
{
    public function getConnection(): ?string
    {
        $name = config('arca.connection') ?? parent::getConnection();
        $resolvedName = $name ?? config('database.default');
        $mongoConnection = config("database.connections.{$resolvedName}.driver") === 'mongodb';
        $mongoStorage = config('arca.storage_driver', 'eloquent') === 'mongodb';

        if ($mongoConnection !== $mongoStorage) {
            throw new RuntimeException('ARCA migrations require matching ARCA_STORAGE_DRIVER and ARCA_DB_CONNECTION settings.');
        }

        return $name;
    }
}
