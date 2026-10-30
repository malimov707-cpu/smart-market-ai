<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('city')->default('Toshkent');
            $table->timestamps();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'manager', 'auditor'])->default('auditor');
            $table->primary(['organization_id', 'user_id']);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku', 80);
            $table->enum('unit', ['kg', 'l', 'pcs']);
            $table->string('category')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'sku']);
            $table->unique(['organization_id', 'id']);
        });
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('city')->default('Toshkent');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
        });
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id');
            $table->decimal('price', 18, 2);
            $table->char('currency', 3)->default('UZS');
            $table->enum('unit', ['kg', 'g', 'l', 'ml', 'pcs']);
            $table->decimal('package_quantity', 12, 3)->default(1);
            $table->unsignedInteger('minimum_packages')->default(1);
            $table->decimal('delivery_fee', 18, 2)->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('expires_at')->nullable();
            $table->enum('provenance', ['manual', 'csv']);
            $table->string('source_reference');
            $table->string('fingerprint', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'fingerprint']);
            $table->index(['organization_id', 'product_id', 'observed_at']);
            $table->foreign(['organization_id', 'product_id'])->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['organization_id', 'supplier_id'])->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'offers', 'suppliers', 'products', 'organization_user', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
