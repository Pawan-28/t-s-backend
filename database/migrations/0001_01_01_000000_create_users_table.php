<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // utf8mb4_unicode_ci makes this UNIQUE index case-insensitive (Django enforced it in code only).
            $table->string('email', 254)->unique();
            $table->string('password', 255);
            $table->string('first_name', 150)->default('');
            $table->string('last_name', 150)->default('');
            $table->string('phone', 20)->nullable()->unique();
            $table->dateTime('phone_verified_at')->nullable();
            $table->string('role', 20)->default('USER')->index();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_login')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
