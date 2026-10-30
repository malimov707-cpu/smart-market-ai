<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5: tenant-scoped alert rules and in-app notifications (additive only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 120);
            $table->enum('event_type', ['price_decrease', 'price_increase', 'new_offer', 'lowest_price_changed', 'price_verification_approved', 'price_verification_rejected', 'price_dispute', 'supplier_verification_changed', 'import_completed', 'import_completed_with_errors', 'import_failed']);
            $table->enum('scope_type', ['product', 'supplier', 'category', 'global'])->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->enum('threshold_kind', ['none', 'percent', 'absolute'])->default('none');
            $table->decimal('threshold_value', 14, 4)->nullable();
            $table->enum('frequency', ['instant', 'daily'])->default('instant');
            $table->json('channels')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['organization_id', 'event_type', 'enabled']);
            $table->index(['organization_id', 'scope_type', 'scope_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_threshold_check CHECK (threshold_kind = \'none\' OR threshold_value IS NOT NULL)');
        }

        Schema::create('app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('event_type', 60);
            $table->unsignedBigInteger('alert_rule_id')->nullable();
            $table->string('dedupe_key', 120);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->string('url', 300)->nullable();
            $table->enum('email_status', ['not_requested', 'sent', 'unavailable', 'failed'])->default('not_requested');
            $table->timestamp('read_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['user_id', 'dedupe_key']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['organization_id', 'user_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('alert_rules');
    }
};
