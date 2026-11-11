<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A comment (or a mention in it) owed to one person in Telegram, and — for mentions —
        // whether that person has reacted. Deleting the comment or the task deletes the rows.
        Schema::create('comment_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained('task_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            // mention | comment
            $table->string('kind', 16);
            // mentioned | assignee | initiator | watcher — who the person was to the task when it was written
            $table->string('role', 16);
            // pending → sending → sent | failed; skipped = nothing to send (no Telegram, switched off, no longer relevant)
            $table->string('status', 16)->default('pending');
            $table->timestamp('send_after')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedBigInteger('telegram_message_id')->nullable();

            // Mentions only: the reaction that closes the question, and the follow-ups already made.
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('ack_via', 16)->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('author_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']);
            $table->index(['status', 'send_after']);
            $table->index(['kind', 'acknowledged_at']);
            $table->index(['task_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_notices');
    }
};
