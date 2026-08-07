<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->mediumText('description')->change();
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->mediumText('body')->change();
        });

        // Description edits are snapshotted into task_histories.old_value / new_value
        // (see TaskService::update), so those columns must grow with description.
        Schema::table('task_histories', function (Blueprint $table) {
            $table->mediumText('old_value')->nullable()->change();
            $table->mediumText('new_value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('description')->change();
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->text('body')->change();
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->text('old_value')->nullable()->change();
            $table->text('new_value')->nullable()->change();
        });
    }
};
