<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per day (and per department digest): the numbers the next digest compares itself with.
        Schema::create('digest_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            // 0 = the whole company. Not nullable, so the unique index also guards the company row.
            $table->unsignedBigInteger('department_id')->default(0);
            $table->unsignedInteger('open_count');
            $table->unsignedInteger('overdue_count');
            $table->timestamp('created_at')->nullable();

            $table->unique(['date', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_snapshots');
    }
};
