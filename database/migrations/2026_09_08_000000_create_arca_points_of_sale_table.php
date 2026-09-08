<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('arca.table_prefix', 'arca_').'points_of_sale', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->string('name');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('arca.table_prefix', 'arca_').'points_of_sale');
    }
};
