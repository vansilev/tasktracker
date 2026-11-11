<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the morning run has already told whom: the guard behind "once per task and threshold".
        Schema::create('task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            // Days before the deadline for kind=threshold, -1 otherwise.
            $table->smallInteger('mark')->default(-1);
            // threshold: the deadline date it was counted from; overdue, escalation: the day it was sent.
            $table->date('ref_date');
            // The deadline date the reminder was about: a moved deadline starts escalations afresh.
            $table->date('deadline_date');
            $table->string('role', 16);
            // pending → sent, or failed (then a later run in the same working day tries again).
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['task_id', 'user_id', 'kind', 'mark', 'ref_date'], 'task_reminders_once');
            $table->index(['task_id', 'kind', 'ref_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminders');
    }
};
