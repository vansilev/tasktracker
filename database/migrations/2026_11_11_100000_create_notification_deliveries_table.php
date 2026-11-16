<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64);
            $table->string('channel', 32);
            $table->string('status', 16);
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['task_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
