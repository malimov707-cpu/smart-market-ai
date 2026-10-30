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
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('security_version')->default(0);
            $table->string('locale', 2)->default('uz');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organization_user DROP CONSTRAINT IF EXISTS organization_user_role_check');
            DB::statement("ALTER TABLE organization_user ADD CONSTRAINT organization_user_role_check CHECK (role IN ('owner', 'manager', 'buyer', 'analyst', 'auditor'))");
        } else {
            Schema::table('organization_user', function (Blueprint $table): void {
                $table->enum('role', ['owner', 'manager', 'buyer', 'analyst', 'auditor'])->default('auditor')->change();
            });
        }
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->json('metadata')->nullable();
        });
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->string('email', 254);
            $table->string('pending_email', 254)->nullable();
            $table->enum('role', ['owner', 'manager', 'buyer', 'analyst', 'auditor']);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'pending_email']);
            $table->index(['organization_id', 'created_at']);
        });
        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only identity migration: preserve accounts, memberships and audit history. Use a reviewed forward fix.');
    }
};
