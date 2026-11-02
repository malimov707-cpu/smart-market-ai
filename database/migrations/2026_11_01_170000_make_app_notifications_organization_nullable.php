<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Public market watchers are personal, not tenant scoped: allow organization_id null. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->change();
        });
    }
};
