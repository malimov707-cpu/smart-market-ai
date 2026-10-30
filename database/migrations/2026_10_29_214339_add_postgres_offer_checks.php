<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_positive_values CHECK (price > 0 AND package_quantity > 0 AND minimum_packages > 0 AND (delivery_fee IS NULL OR delivery_fee >= 0))');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_valid_expiry CHECK (expires_at IS NULL OR expires_at > observed_at)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE offers DROP CONSTRAINT offers_valid_expiry');
        DB::statement('ALTER TABLE offers DROP CONSTRAINT offers_positive_values');
    }
};
