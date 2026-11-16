<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('fireflies_id')->unique();
            $table->string('title');
            $table->dateTime('started_at')->index();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->string('organizer_email')->nullable();
            $table->string('meeting_link')->nullable();
            $table->mediumText('summary')->nullable();
            $table->json('keywords')->nullable();
            // Fireflies' own action items, kept as they came to compare with ours.
            $table->mediumText('fireflies_action_items')->nullable();
            // [{email, name}] from the calendar invite.
            $table->json('attendees')->nullable();
            // [{start, end, speaker, text}] — sentences, in order.
            $table->json('sentences')->nullable();
            // {speaker name: user id | "external"} suggested by the model on analysis.
            $table->json('speaker_map')->nullable();
            $table->dateTime('analyzed_at')->nullable();
            // Set while the model reads the meeting (runs after the response, see AnalyzeMeeting).
            $table->dateTime('analysis_started_at')->nullable();
            $table->string('analysis_model')->nullable();
            $table->text('analysis_error')->nullable();
            $table->timestamps();
        });

        // How a name or an email that Fireflies shows maps to a tracker user
        // (or is marked as someone from outside the company).
        Schema::create('meeting_person_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias')->unique(); // normalised email or name
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_external')->default(false);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('meeting_task_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            // named | absent_speaker | speaker | organizer | unassigned
            $table->string('assignment_reason', 32);
            $table->string('mentioned_name')->nullable();
            $table->foreignId('mentioned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('speaker_name')->nullable();
            $table->foreignId('speaker_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('deadline')->nullable();
            $table->string('deadline_phrase')->nullable();
            $table->text('quote')->nullable();
            $table->decimal('start_seconds', 10, 2)->nullable();
            $table->string('status', 16)->default('draft')->index(); // draft | created | dismissed
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_task_drafts');
        Schema::dropIfExists('meeting_person_aliases');
        Schema::dropIfExists('meetings');
    }
};
