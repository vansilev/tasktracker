<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // participants | selected | admins — see MeetingPeople::canView().
            $table->string('access_mode', 16)->default('participants')->after('analysis_error');
        });

        // People an admin let in by hand.
        Schema::create('meeting_viewers', function (Blueprint $table) {
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_viewers');
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('access_mode');
        });
    }
};
