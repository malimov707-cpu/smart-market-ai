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
        Schema::table('products', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('barcode', 32)->nullable();
            $table->json('aliases')->nullable();
            $table->unsignedBigInteger('parent_product_id')->nullable();
            $table->json('variant_attributes')->nullable();
            $table->decimal('package_quantity', 12, 3)->nullable();
            $table->string('package_unit', 8)->nullable();
            $table->unique(['organization_id', 'barcode']);
            $table->index(['organization_id', 'archived_at', 'name']);
            $table->foreign(['organization_id', 'parent_product_id'])->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('email', 254)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected', 'suspended'])->default('unverified');
            $table->enum('platform_verification_status', ['unverified', 'pending', 'verified', 'rejected', 'suspended'])->default('unverified');
            $table->index(['organization_id', 'archived_at', 'name']);
        });
        Schema::create('platform_permissions', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('permission', 80);
            $table->primary(['user_id', 'permission']);
        });
        Schema::create('catalog_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->foreign(['organization_id', 'product_id'])->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['organization_id', 'supplier_id'])->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->index(['organization_id', 'supplier_id', 'retired_at']);
            $table->index(['organization_id', 'product_id', 'retired_at']);
        });
        Schema::create('supplier_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->enum('scope', ['organization', 'platform']);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('reason');
            $table->json('evidence_ids');
            $table->timestamps();
            $table->foreign(['organization_id', 'supplier_id'])->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->index(['organization_id', 'supplier_id', 'scope', 'id']);
        });
        Schema::table('offers', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id']);
        });
        Schema::create('supplier_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('offer_id');
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'supplier_id', 'user_id']);
            $table->foreign(['organization_id', 'supplier_id'])->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['organization_id', 'offer_id'])->references(['organization_id', 'id'])->on('offers')->restrictOnDelete();
        });
        Schema::create('supplier_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->unique()->constrained('supplier_reviews')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('product_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('headers');
            $table->json('rows');
            $table->json('mapping');
            $table->json('summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_package_pair CHECK ((package_quantity IS NULL AND package_unit IS NULL) OR (package_quantity IS NOT NULL AND package_quantity > 0 AND package_unit IS NOT NULL))');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_not_self_parent CHECK (parent_product_id IS NULL OR parent_product_id <> id)');
            DB::statement('ALTER TABLE catalog_files ADD CONSTRAINT catalog_file_single_owner CHECK ((product_id IS NOT NULL) <> (supplier_id IS NOT NULL))');
            DB::statement('ALTER TABLE supplier_reviews ADD CONSTRAINT supplier_review_rating_range CHECK (rating BETWEEN 1 AND 5)');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only catalog migration: preserve supplier evidence and product history.');
    }
};
