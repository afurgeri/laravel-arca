<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Arca\Infrastructure\Persistence\ArcaMigration as Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('arca.table_prefix', 'arca_').'operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voucher_id')->nullable()->constrained((string) config('arca.table_prefix', 'arca_').'vouchers')->nullOnDelete();
            $table->uuid('correlation_id')->index();
            $table->string('method');
            $table->string('status', 20)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('arca.table_prefix', 'arca_').'operations');
    }
};
