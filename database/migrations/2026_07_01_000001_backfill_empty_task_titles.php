<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->where(function ($query): void {
                $query->where('title', '')->orWhereNull('title');
            })
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $task) {
                    $normalized = trim(preg_replace('/\s+/', ' ', $task->description ?? '') ?? '');

                    DB::table('tasks')->where('id', $task->id)->update([
                        'title' => Str::limit($normalized, 120, ''),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible data fix.
    }
};
