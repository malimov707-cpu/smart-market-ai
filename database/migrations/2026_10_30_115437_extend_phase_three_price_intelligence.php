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
        Schema::table('offers', function (Blueprint $table): void {
            $table->timestamp('recorded_at', 6)->nullable();
            $table->string('source_type', 30)->nullable();
            $table->string('source_identifier')->nullable();
            $table->string('task_reference')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('evidence_file_id')->nullable();
            $table->unsignedBigInteger('correction_of_id')->nullable();
            $table->text('correction_reason')->nullable();
            $table->foreign(['organization_id', 'correction_of_id'])->references(['organization_id', 'id'])->on('offers')->restrictOnDelete();

        });
        Schema::table('catalog_files', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id']);
        });
        Schema::table('offers', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'evidence_file_id'])->references(['organization_id', 'id'])->on('catalog_files')->restrictOnDelete();
        });
        $recorded = DB::getDriverName() === 'sqlite' ? "COALESCE(recorded_at, created_at || CASE WHEN length(created_at) = 19 THEN '.000000' ELSE '' END)" : 'COALESCE(recorded_at, created_at)';
        DB::statement('CREATE INDEX offers_latest_lookup ON offers (organization_id, product_id, supplier_id, observed_at DESC, ('.$recorded.') DESC, id DESC)');
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->timestamp('created_at', 6)->useCurrent()->change();
            $table->index(['organization_id', 'subject_type', 'subject_id', 'created_at', 'id'], 'audit_subject_temporal');
        });
        Schema::table('supplier_verifications', function (Blueprint $table): void {
            $table->timestamp('created_at', 6)->nullable()->change();
            $table->index(['organization_id', 'supplier_id', 'scope', 'created_at', 'id'], 'supplier_verification_temporal');
        });
        Schema::create('price_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('offer_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->enum('scope', ['organization', 'platform']);
            $table->enum('status', ['pending', 'verified', 'rejected', 'disputed']);
            $table->text('reason');
            $table->unsignedBigInteger('evidence_file_id');
            $table->timestamp('created_at', 6)->useCurrent();
            $table->foreign(['organization_id', 'offer_id'])->references(['organization_id', 'id'])->on('offers')->restrictOnDelete();
            $table->foreign(['organization_id', 'evidence_file_id'])->references(['organization_id', 'id'])->on('catalog_files')->restrictOnDelete();
            $table->index(['organization_id', 'offer_id', 'scope', 'created_at', 'id'], 'price_reviews_temporal');
        });
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('rate', 28, 12);
            $table->string('source');
            $table->string('source_reference');
            $table->timestamp('effective_at', 6);
            $table->timestamp('expires_at', 6);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at', 6)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['organization_id', 'currency', 'effective_at', 'created_at', 'id'], 'exchange_rates_temporal');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rate_positive CHECK (rate > 0), ADD CONSTRAINT exchange_rate_interval CHECK (expires_at > effective_at), ADD CONSTRAINT exchange_rate_independent CHECK (approved_by IS NULL OR approved_by <> submitted_by)');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: price evidence and historical rates must be preserved.');
    }
};
