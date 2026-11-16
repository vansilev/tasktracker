<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Please move the deadline", asked from Telegram: the deadline changes only once the
        // task's initiator agrees.
        Schema::create('deadline_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            // The deadline at the moment of asking: if it has changed since, the request is stale.
            $table->dateTime('old_deadline')->nullable();
            $table->date('new_deadline');
            $table->text('reason');
            // pending → approved | rejected | stale
            $table->string('status', 16)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_change_requests');
    }
};
