<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Public market watches are user-scoped, so organization_id must be nullable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('app_notifications', function (Blueprint $table): void {
                $table->dropForeign(['organization_id']);
                $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });
    }
};
