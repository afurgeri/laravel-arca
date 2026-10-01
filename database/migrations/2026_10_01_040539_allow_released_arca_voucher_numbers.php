<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table((string) config('arca.table_prefix', 'arca_').'vouchers', function (Blueprint $table): void {
            $table->unsignedInteger('number')->nullable()->change();
            $table->unsignedInteger('attempted_number')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table((string) config('arca.table_prefix', 'arca_').'vouchers')->whereNull('number')->exists()) {
            throw new RuntimeException('Cannot roll back while released ARCA voucher numbers exist.');
        }

        Schema::table((string) config('arca.table_prefix', 'arca_').'vouchers', function (Blueprint $table): void {
            $table->dropColumn('attempted_number');
            $table->unsignedInteger('number')->nullable(false)->change();
        });
    }
};
