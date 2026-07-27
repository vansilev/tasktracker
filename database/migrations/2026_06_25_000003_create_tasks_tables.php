<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->foreignId('initiator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_initiator_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('department_id')->constrained('departments');
            $table->foreignId('category_id')->constrained('categories');
            $table->text('description');
            $table->unsignedTinyInteger('priority')->default(5);
            $table->string('status')->default('new');
            $table->timestamp('deadline')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('rework_count')->default(0);
            $table->timestamp('review_due_at')->nullable();
            $table->string('spec_url')->nullable();
            $table->string('result_url')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['assignee_id', 'status']);
            $table->index(['initiator_id', 'status']);
            $table->index('department_id');
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->boolean('is_done')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('task_watchers', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_histories');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('tasks');
    }
};
