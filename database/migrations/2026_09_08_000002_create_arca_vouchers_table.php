<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Arca\Infrastructure\Persistence\ArcaMigration as Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('arca.table_prefix', 'arca_').'vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('point_of_sale_id')->constrained((string) config('arca.table_prefix', 'arca_').'points_of_sale');
            $table->unsignedSmallInteger('voucher_type');
            $table->string('letter', 1);
            $table->string('operation', 20);
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 12, 6);
            $table->unsignedInteger('number');
            $table->string('status', 20)->index();
            $table->json('customer');
            $table->json('amounts');
            $table->json('vat_rates')->nullable();
            $table->json('associated_voucher')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('cae')->nullable()->index();
            $table->date('cae_expires_at')->nullable();
            $table->json('arca_response')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['point_of_sale_id', 'voucher_type', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('arca.table_prefix', 'arca_').'vouchers');
    }
};
