<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreignId('parent_comment_id')
                ->nullable()
                ->after('author_id')
                ->constrained('task_comments')
                ->nullOnDelete();
        });

        Schema::create('task_comment_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_comment_id')->constrained('task_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['task_comment_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comment_reactions');

        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_comment_id');
        });
    }
};
