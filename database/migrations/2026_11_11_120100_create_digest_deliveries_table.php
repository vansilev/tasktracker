<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The scheduled digest claimed for one recipient: the guard behind "one digest a day",
        // with the same pending → sent / failed life cycle as task_reminders.
        Schema::create('digest_deliveries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('department_id')->default(0);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['date', 'department_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_deliveries');
    }
};
