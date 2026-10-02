<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->text('description');
            $table->decimal('price_amount', 10, 2);
            $table->string('price_currency', 3)->default('INR');
            $table->unsignedInteger('duration_days');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('status', 20)->default('PENDING')->index();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->string('contact_email', 254)->default('');
            $table->string('contact_phone', 20)->default('');
            $table->dateTime('expiring_notified_at')->nullable();
            $table->dateTime('expired_notified_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('razorpay_order_id', 100)->unique();
            $table->string('razorpay_payment_id', 100)->default('');
            $table->string('razorpay_signature', 255)->default('');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->default('CREATED')->index();
            $table->string('failure_reason', 255)->default('');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['user_id', 'status']);
        });

        Schema::create('phone_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('code_hash', 255);
            $table->dateTime('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->boolean('is_verified')->default(false);
            $table->dateTime('created_at')->useCurrent();
            $table->index(['user_id', 'is_verified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_otps');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
