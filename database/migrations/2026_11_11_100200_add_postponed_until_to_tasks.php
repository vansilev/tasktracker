<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // The day a postponed task comes back into reminders. Tasks postponed before this
            // column existed keep NULL.
            $table->date('postponed_until')->nullable()->after('review_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('postponed_until');
        });
    }
};
