<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = (string) config('arca.table_prefix', 'arca_');

        Schema::table($prefix.'points_of_sale', function (Blueprint $table) use ($prefix): void {
            $table->string('cuit', 11)->nullable()->after('id');
            $table->string('environment', 20)->nullable()->after('cuit');
            $table->dropUnique($prefix.'points_of_sale_number_unique');
            $table->unique(['cuit', 'environment', 'number'], $prefix.'pos_account_number_unique');
        });

        Schema::table($prefix.'vouchers', function (Blueprint $table) use ($prefix): void {
            $table->string('cuit', 11)->nullable()->after('id');
            $table->string('environment', 20)->nullable()->after('cuit');
            $table->dropUnique($prefix.'vouchers_idempotency_key_unique');
            $table->unique(['cuit', 'environment', 'idempotency_key'], $prefix.'voucher_account_idempotency_unique');
        });

        Schema::table($prefix.'operations', function (Blueprint $table): void {
            $table->string('cuit', 11)->nullable()->after('id');
            $table->string('environment', 20)->nullable()->after('cuit');
        });
    }

    public function down(): void
    {
        $prefix = (string) config('arca.table_prefix', 'arca_');

        Schema::table($prefix.'operations', function (Blueprint $table): void {
            $table->dropColumn(['cuit', 'environment']);
        });
        Schema::table($prefix.'vouchers', function (Blueprint $table) use ($prefix): void {
            $table->dropUnique($prefix.'voucher_account_idempotency_unique');
            $table->dropColumn(['cuit', 'environment']);
            $table->unique('idempotency_key', $prefix.'vouchers_idempotency_key_unique');
        });
        Schema::table($prefix.'points_of_sale', function (Blueprint $table) use ($prefix): void {
            $table->dropUnique($prefix.'pos_account_number_unique');
            $table->dropColumn(['cuit', 'environment']);
            $table->unique('number', $prefix.'points_of_sale_number_unique');
        });
    }
};
