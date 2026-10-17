<?php

use Illuminate\Support\Facades\DB;
use Modules\Arca\Infrastructure\Persistence\ArcaMigration;
use MongoDB\Laravel\Connection;

return new class extends ArcaMigration
{
    public function up(): void
    {
        $database = $this->mongoConnection()->getDatabase();
        $prefix = (string) config('arca.table_prefix', 'arca_');

        foreach (['points_of_sale', 'sequences', 'vouchers', 'operations'] as $name) {
            if (iterator_count($database->listCollections(['filter' => ['name' => $prefix.$name]])) === 0) {
                $database->createCollection($prefix.$name);
            }
        }

        $database->selectCollection($prefix.'points_of_sale')->createIndex(
            ['cuit' => 1, 'environment' => 1, 'number' => 1], ['unique' => true, 'name' => 'account_number_unique'],
        );
        $database->selectCollection($prefix.'sequences')->createIndex(
            ['point_of_sale_id' => 1, 'voucher_type' => 1], ['unique' => true, 'name' => 'point_type_unique'],
        );
        $database->selectCollection($prefix.'vouchers')->createIndexes([
            [
                'key' => ['point_of_sale_id' => 1, 'voucher_type' => 1, 'number' => 1],
                'unique' => true,
                'name' => 'active_number_unique',
                'partialFilterExpression' => ['number' => ['$type' => 'number']],
            ],
            [
                'key' => ['cuit' => 1, 'environment' => 1, 'idempotency_key' => 1],
                'unique' => true,
                'name' => 'account_idempotency_unique',
                'partialFilterExpression' => ['idempotency_key' => ['$type' => 'string']],
            ],
            ['key' => ['cuit' => 1, 'environment' => 1, 'status' => 1], 'name' => 'account_status'],
        ]);
        $database->selectCollection($prefix.'operations')->createIndex(
            ['voucher_id' => 1, 'method' => 1, 'created_at' => -1, '_id' => -1], ['name' => 'voucher_method_latest'],
        );
    }

    public function down(): void
    {
        $database = $this->mongoConnection()->getDatabase();

        foreach (['operations', 'vouchers', 'sequences', 'points_of_sale'] as $name) {
            $database->dropCollection((string) config('arca.table_prefix', 'arca_').$name);
        }
    }

    private function mongoConnection(): Connection
    {
        $connection = DB::connection($this->getConnection());

        if (! $connection instanceof Connection) {
            throw new RuntimeException('ARCA MongoDB migrations require a MongoDB connection.');
        }

        return $connection;
    }
};
