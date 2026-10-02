<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 280)->unique();
            $table->text('excerpt');
            $table->longText('content');
            $table->string('location_name', 200)->default('');
            $table->json('faqs');
            // Subcategory is the primary classification; `category_id` is the
            // legacy mirror, always derived from the subcategory on save.
            $table->foreignId('subcategory_id')->nullable()->constrained('subcategories')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            // author is immutable and never touched by workflow actions.
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('DRAFT')->index();
            $table->text('rejection_reason');
            $table->string('access_level', 20)->default('PUBLIC')->index();
            $table->dateTime('scheduled_publish_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('subscribers_notified_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['status', 'access_level']);
            $table->index(['author_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['subcategory_id', 'status']);
            $table->index(['status', 'subscribers_notified_at']);
            $table->index(['assigned_reporter_id', 'status']);
        });

        // Full-text search lives in its own table (see ArticleSearchIndexer): a FULLTEXT
        // index cannot weight columns, and the body of locked articles must not be indexed.
        Schema::create('article_search_index', function (Blueprint $table) {
            $table->unsignedBigInteger('article_id')->primary();
            $table->string('title', 255);
            $table->text('excerpt');          // excerpt + location
            $table->longText('body');         // plain text; EMPTY for non-PUBLIC articles
            $table->text('taxonomy');         // subcategory / category / industry / tag names
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            // Combined index: matches (AND/OR/phrase) across all four fields; the single-column
            // indexes below give the per-field relevance boosts.
            $table->fullText(['title', 'excerpt', 'body', 'taxonomy'], 'ft_article_search_all');
            $table->fullText('title', 'ft_article_search_title');
            $table->fullText('excerpt', 'ft_article_search_excerpt');
            $table->fullText('body', 'ft_article_search_body');
            $table->fullText('taxonomy', 'ft_article_search_taxonomy');
        });

        Schema::create('article_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->unique(['article_id', 'tag_id']);
        });

        Schema::create('article_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            // null reviewer = automatic (scheduled) publish
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('reason');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('publishing_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->dateTime('scheduled_for');
            $table->foreignId('scheduled_by_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('PENDING')->index();
            $table->dateTime('executed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['status', 'scheduled_for']);
        });
        // At most one PENDING schedule per article. MySQL has no partial unique index, so a
        // stored generated column is article_id while PENDING and NULL otherwise; NULLs never
        // collide in a UNIQUE index. VIRTUAL (not STORED): MySQL 8 rejects a STORED generated column
        // whose base column (article_id) carries an ON DELETE CASCADE foreign key (error 1215);
        // InnoDB can still put a UNIQUE secondary index on a VIRTUAL column (MySQL 8 and MariaDB 10.2+).
        Schema::table('publishing_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('pending_article_id')->nullable()
                ->virtualAs("CASE WHEN status = 'PENDING' THEN article_id ELSE NULL END");
            $table->unique('pending_article_id', 'uniq_pending_publishing_schedule_per_article');
        });

        Schema::create('reporter_category_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['reporter_id', 'category_id']);
        });

        Schema::create('article_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->constrained('users')->restrictOnDelete();
            $table->string('bunny_url', 500);
            $table->string('bunny_storage_path', 500);
            $table->string('alt_text', 255)->default('');
            $table->string('caption', 500)->default('');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            // original_filename, content_type, file_size_bytes, width, height, checksum
            $table->json('metadata')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['article_id', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_images');
        Schema::dropIfExists('reporter_category_assignments');
        Schema::dropIfExists('publishing_schedules');
        Schema::dropIfExists('article_reviews');
        Schema::dropIfExists('article_search_index');
        Schema::dropIfExists('article_tag');
        Schema::dropIfExists('articles');
    }
};
