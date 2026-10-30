<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') { return; }
        Schema::table('offers', function (Blueprint $table): void {
            $table->text('price_exact')->nullable();
            $table->text('delivery_fee_exact')->nullable();
        });
        Schema::table('exchange_rates', function (Blueprint $table): void { $table->text('rate_exact')->nullable(); });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: exact monetary evidence must be preserved.');
    }
};
