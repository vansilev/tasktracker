<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Existing rows are Markdown; new writes set this to html explicitly.
            $table->string('description_format', 16)
                ->default('markdown')
                ->after('description');
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->string('body_format', 16)
                ->default('markdown')
                ->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('description_format');
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropColumn('body_format');
        });
    }
};
