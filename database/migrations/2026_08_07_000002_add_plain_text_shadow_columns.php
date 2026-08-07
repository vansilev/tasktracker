<?php

use App\Services\TaskPlainTextBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->mediumText('description_text')->nullable()->after('description');
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->mediumText('body_text')->nullable()->after('body');
        });

        // Populate shadows for existing rows (including soft-deleted tasks) so a
        // deploy cannot leave search/previews broken until a manual command runs.
        // Chunked + query-builder updates: safe on large tables, no updated_at bump.
        app(TaskPlainTextBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('description_text');
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropColumn('body_text');
        });
    }
};
