<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_inline_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index(['uploaded_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_inline_attachments');
    }
};
