<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_items', function (Blueprint $table) {
            $table->id();
            $table->string('vendor');
            $table->string('product');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('kind');
            $table->unsignedTinyInteger('period_months')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('next_due_on')->nullable();
            $table->unsignedTinyInteger('due_day_of_month')->nullable();
            $table->string('due_day_rule')->nullable();
            $table->string('payment_method');
            $table->string('card_last4', 4)->nullable();
            $table->string('card_label')->nullable();
            $table->foreignId('payer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_label')->nullable();
            $table->string('portal_url')->nullable();
            $table->string('account_ref')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->string('state')->default('active');
            $table->date('paused_until')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archive_reason')->nullable();
            $table->string('vat_note')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('last_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->date('reminder_7_sent_for')->nullable();
            $table->date('reminder_3_sent_for')->nullable();
            $table->date('reminder_overdue_sent_for')->nullable();
            $table->timestamps();

            $table->index(['state', 'kind', 'next_due_on']);
            $table->index('payer_user_id');
        });

        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_item_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->date('cycle_due_on');
            $table->date('recorded_on');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['billing_item_id', 'cycle_due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_items');
    }
};
