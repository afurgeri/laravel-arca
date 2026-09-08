<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('arca.table_prefix', 'arca_').'sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('point_of_sale_id')->constrained((string) config('arca.table_prefix', 'arca_').'points_of_sale')->cascadeOnDelete();
            $table->unsignedSmallInteger('voucher_type');
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedInteger('last_synced_number')->default(0);
            $table->timestamps();

            $table->unique(['point_of_sale_id', 'voucher_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('arca.table_prefix', 'arca_').'sequences');
    }
};
