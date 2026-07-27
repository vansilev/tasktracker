<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('head_user_id')->nullable();
            $table->boolean('auto_assign_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('system_type')->default('user')->after('password');
            $table->foreignId('department_id')->nullable()->after('system_type')->constrained()->nullOnDelete();
            $table->string('locale', 5)->default('ru')->after('department_id');
            $table->string('telegram_chat_id')->nullable()->after('locale');
            $table->string('auth_provider')->default('password')->after('telegram_chat_id');
            $table->string('google_id')->nullable()->unique()->after('auth_provider');
            $table->string('avatar')->nullable()->after('google_id');
            $table->boolean('is_active')->default(true)->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'system_type',
                'locale',
                'telegram_chat_id',
                'auth_provider',
                'google_id',
                'avatar',
                'is_active',
            ]);
        });

        Schema::dropIfExists('settings');
        Schema::dropIfExists('departments');
    }
};
