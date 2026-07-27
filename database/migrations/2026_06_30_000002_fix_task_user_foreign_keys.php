<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['initiator_id']);
            $table->dropForeign(['assignee_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('initiator_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assignee_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreign('author_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->dropForeign(['changed_by']);
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->foreign('changed_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_histories', function (Blueprint $table) {
            $table->dropForeign(['changed_by']);
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->foreign('changed_by')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['initiator_id']);
            $table->dropForeign(['assignee_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('initiator_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assignee_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
