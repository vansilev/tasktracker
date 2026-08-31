<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comment_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_comment_id')->constrained('task_comments')->cascadeOnDelete();
            $table->foreignId('quoted_comment_id')->constrained('task_comments')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['task_comment_id', 'quoted_comment_id']);
        });

        $now = now();
        DB::table('task_comments')
            ->whereNotNull('parent_comment_id')
            ->orderBy('id')
            ->each(function (object $row) use ($now): void {
                DB::table('task_comment_quotes')->insertOrIgnore([
                    'task_comment_id' => $row->id,
                    'quoted_comment_id' => $row->parent_comment_id,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comment_quotes');
    }
};
