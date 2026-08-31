<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('parent_id');
            $table->index(['parent_id', 'sort_order']);
        });

        $children = DB::table('tasks')
            ->whereNotNull('parent_id')
            ->orderBy('parent_id')
            ->orderBy('number')
            ->get(['id', 'parent_id']);

        $lastParent = null;
        $order = 0;

        foreach ($children as $child) {
            if ($child->parent_id !== $lastParent) {
                $lastParent = $child->parent_id;
                $order = 0;
            }

            DB::table('tasks')->where('id', $child->id)->update(['sort_order' => $order]);
            $order++;
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
