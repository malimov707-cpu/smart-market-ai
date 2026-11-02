<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Telegram briefing: store chat link and delivery preference on users. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('telegram_chat_id', 40)->nullable()->after('locale');
            $table->string('telegram_link_token', 64)->nullable()->unique()->after('telegram_chat_id');
            $table->boolean('telegram_briefing')->default(true)->after('telegram_link_token');
            $table->index('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['telegram_chat_id']);
            $table->dropColumn(['telegram_chat_id', 'telegram_link_token', 'telegram_briefing']);
        });
    }
};
