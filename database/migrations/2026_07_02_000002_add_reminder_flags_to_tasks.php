<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('deadline_reminder_sent_at')->nullable()->after('review_due_at');
            $table->timestamp('overdue_notified_at')->nullable()->after('deadline_reminder_sent_at');
            $table->timestamp('review_sla_notified_at')->nullable()->after('overdue_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'deadline_reminder_sent_at',
                'overdue_notified_at',
                'review_sla_notified_at',
            ]);
        });
    }
};
