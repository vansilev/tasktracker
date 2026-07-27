<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('title', 120)->default('')->after('category_id');
            $table->index('priority');
            $table->index('deadline');
            $table->index('category_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('system_type');
            $table->index('is_active');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('channel');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'event', 'channel']);
        });

        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained('task_comments')->nullOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->string('mime', 127);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('task_comment_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_comment_id')->constrained('task_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comment_mentions');
        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['system_type']);
            $table->dropIndex(['is_active']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropIndex(['deadline']);
            $table->dropIndex(['category_id']);
            $table->dropColumn('title');
        });
    }
};
