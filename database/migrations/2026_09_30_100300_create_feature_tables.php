<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->cascadeOnDelete();
            $table->string('notification_type', 30);
            $table->string('message', 500);
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->useCurrent();
            $table->index(['recipient_id', 'is_read']);
        });

        Schema::create('article_daily_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['article_id', 'date'], 'uniq_article_daily_view');
            $table->index('date');
        });

        Schema::create('advertisements', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('placement', 20)->index();
            $table->string('image_url', 500);
            $table->string('bunny_storage_path', 500)->default('');
            $table->string('target_url', 500)->default(''); // optional
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['placement', 'is_active']);
            $table->index(['start_at', 'end_at']);
        });

        Schema::create('ai_analysis_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('model_name', 100)->default('');
            $table->string('status', 20)->default('COMPLETED')->index();
            $table->text('error_message');
            $table->float('readability_score')->nullable();
            $table->json('grammar_issues');
            $table->json('seo_suggestions');
            $table->float('ai_content_likelihood')->nullable();
            $table->text('ai_content_rationale');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('plagiarism_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('scan_id', 100)->unique();
            $table->string('status', 20)->default('PENDING')->index();
            $table->text('error_message');
            $table->float('similarity_score')->nullable();
            $table->json('matches');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('completed_at')->nullable();
            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plagiarism_check_results');
        Schema::dropIfExists('ai_analysis_results');
        Schema::dropIfExists('advertisements');
        Schema::dropIfExists('article_daily_views');
        Schema::dropIfExists('notifications');
    }
};
