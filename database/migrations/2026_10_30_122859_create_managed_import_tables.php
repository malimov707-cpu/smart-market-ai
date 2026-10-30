<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['products', 'suppliers', 'offers']);
            $table->enum('format', ['csv', 'xlsx', 'pdf', 'png', 'jpg']);
            $table->string('file_name', 180);
            $table->string('file_path');
            $table->string('file_mime', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64);
            $table->string('idempotency_key', 64);
            $table->string('scan_status', 30)->default('unavailable');
            $table->enum('status', ['uploaded', 'validating', 'preview_ready', 'awaiting_confirmation', 'queued', 'processing', 'completed', 'completed_with_errors', 'failed', 'cancelled'])->default('uploaded');
            $table->enum('stage', ['prepare', 'commit'])->default('prepare');
            $table->json('sheets')->nullable();
            $table->unsignedInteger('sheet')->default(0);
            $table->unsignedInteger('header_row')->default(1);
            $table->string('timezone', 60)->default('Asia/Tashkent');
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->enum('duplicate_policy', ['skip', 'update', 'immutable'])->default('skip');
            $table->enum('error_policy', ['atomic', 'valid_rows'])->default('atomic');
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->json('summary')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at', 6)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('generation')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at', 6)->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamp('cancelled_at', 6)->nullable();
            $table->timestamp('expires_at', 6);
            $table->timestamp('purged_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'created_at', 'id']);
            $table->index(['status', 'lease_expires_at']);
        });
        Schema::create('import_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->json('data')->nullable();
            $table->json('overrides')->nullable();
            $table->json('errors')->nullable();
            $table->json('extraction')->nullable();
            $table->json('match')->nullable();
            $table->enum('status', ['pending', 'valid', 'invalid', 'duplicate', 'created', 'updated', 'skipped', 'failed'])->default('pending');
            $table->string('result_type', 30)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamps(6);
            $table->unique(['import_batch_id', 'row_number']);
            $table->foreign(['organization_id', 'import_batch_id'])->references(['organization_id', 'id'])->on('import_batches')->restrictOnDelete();
            $table->index(['organization_id', 'import_batch_id', 'status']);
        });
        Schema::create('import_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('import_batch_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->foreign(['organization_id', 'import_batch_id'])->references(['organization_id', 'id'])->on('import_batches')->restrictOnDelete();
            $table->index(['organization_id', 'import_batch_id', 'id']);
        });
        Schema::create('extraction_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('import_batch_id');
            $table->string('request_key', 64)->unique();
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            $table->string('status', 30);
            $table->unsignedInteger('transport_attempts')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost', 18, 8)->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps(6);
            $table->foreign(['organization_id', 'import_batch_id'])->references(['organization_id', 'id'])->on('import_batches')->restrictOnDelete();
        });
        Schema::table('offers', function (Blueprint $table): void {
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->foreign(['organization_id', 'import_batch_id'])->references(['organization_id', 'id'])->on('import_batches')->restrictOnDelete();
        });
        Schema::table('catalog_files', function (Blueprint $table): void {
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->foreign(['organization_id', 'import_batch_id'])->references(['organization_id', 'id'])->on('import_batches')->restrictOnDelete();
            $table->unique(['import_batch_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retained import evidence and review history must be preserved.');
    }
};
