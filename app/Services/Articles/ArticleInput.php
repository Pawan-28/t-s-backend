<?php

namespace App\Services\Articles;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\ReporterCategoryAssignment;
use App\Models\Subcategory;
use App\Models\Tag;
use App\Models\User;
use App\Support\DrfFields;
use App\Support\HtmlSanitizer;
use App\Support\PlainText;
use Illuminate\Http\Request;

/**
 * Validation of the article write payload (Django ArticleSerializer), same field
 * rules and message texts. `author`, `assigned_reporter`, `rejection_reason`,
 * `published_at`, `scheduled_publish_at` and every derived field (category,
 * industry, tags, featured image ...) are read-only and silently ignored.
 */
class ArticleInput
{
    public const MAX_FAQS = 10;

    /** Slugs that would be shadowed by the static /articles/mine and /articles/assigned routes. */
    public const RESERVED_SLUGS = ['mine', 'assigned'];

    /**
     * @return array{attrs: array<string,mixed>, subcategory: ?Subcategory, tag_ids: ?list<int>, status: ?ArticleStatus, slug_blank: bool}
     */
    public static function validate(Request $request, User $actor, ?Article $existing, bool $partial): array
    {
        $f = new DrfFields($request->all(), $partial);

        $f->string('title', 255, required: true, blank: false);
        $slug = $f->slug('slug', 280);
        $f->string('excerpt', 1000000);
        $content = $f->string('content', 2000000, required: true, blank: false);
        $location = $f->string('location_name', 200);
        $f->choice('access_level', array_column(AccessLevel::cases(), 'value'));

        if ($content !== null) {
            $clean = HtmlSanitizer::clean($content);
            if (trim($clean) === '') {
                $f->error('content', 'Content cannot be blank after sanitization.');
                $f->set('content', null);
            } else {
                $f->set('content', $clean);
            }
        }
        if ($location !== null && $location !== '') {
            $f->set('location_name', mb_substr(PlainText::strip($location), 0, 200));
        }

        if ($slug !== null && $slug !== '' && ! $f->failed('slug')) {
            if (in_array(strtolower($slug), self::RESERVED_SLUGS, true)) {
                $f->error('slug', 'This slug is reserved.');
            } elseif (self::slugTaken($slug, $existing)) {
                $f->error('slug', 'An article with this slug already exists.');
            }
        }

        // status: admin only (even an unchanged value from a non-admin is rejected, like Django).
        $status = null;
        if ($f->present('status')) {
            $s = $f->choice('status', array_column(ArticleStatus::cases(), 'value'));
            if ($s !== null && ! $actor->canPublishArticles()) {
                $f->error('status', 'Only administrators can set article status in Phase 3. Reporter submission workflow arrives in Phase 5 - omit this field.');
            } elseif ($s !== null) {
                $status = ArticleStatus::from($s);
            }
        }

        $faqs = null;
        if ($f->present('faqs')) {
            $faqs = self::faqs($f);
        }

        $subcategory = null;
        if ($f->present('subcategory_slug') || ! $partial) {
            $subcategory = self::subcategory($f);
        }

        $tagIds = null;
        if ($f->present('tag_slugs')) {
            $tagIds = self::tags($f);
        }

        $f->throwIfFailed();

        // Reporter category enforcement (Django validate()): only when a subcategory is being set.
        if ($subcategory && $actor->isReporter()) {
            $allowed = ReporterCategoryAssignment::query()
                ->where('reporter_id', $actor->id)
                ->where('category_id', $subcategory->category_id)
                ->exists();
            if (! $allowed) {
                $f->error('subcategory_slug', 'You are not assigned to the category this subcategory belongs to. Ask an administrator to assign you to it first.');
                $f->throwIfFailed();
            }
        }

        $attrs = $f->validated();
        unset($attrs['status'], $attrs['slug'], $attrs['subcategory_slug']);
        if ($faqs !== null) {
            $attrs['faqs'] = $faqs;
        }
        if ($subcategory) {
            $attrs['subcategory_id'] = $subcategory->id;
        }
        if (isset($attrs['access_level'])) {
            $attrs['access_level'] = AccessLevel::from($attrs['access_level']);
        }
        if ($slug !== null && $slug !== '') {
            $attrs['slug'] = $slug;
        }

        return [
            'attrs' => $attrs,
            'subcategory' => $subcategory,
            'tag_ids' => $tagIds,
            'status' => $status,
            'slug_blank' => $slug === '',
        ];
    }

    public static function slugTaken(string $slug, ?Article $ignore): bool
    {
        $q = Article::query()->whereRaw('LOWER(slug) = LOWER(?)', [$slug]);
        if ($ignore) {
            $q->whereKeyNot($ignore->getKey());
        }

        return $q->exists();
    }

    /** DRF nested-serializer errors: a list with one {field: [msgs]} object per item. */
    private static function faqs(DrfFields $f): ?array
    {
        $raw = $f->value('faqs');
        if ($raw === null) {
            $f->nestedError('faqs', ['This field may not be null.']);

            return null;
        }
        if (! is_array($raw) || ($raw !== [] && ! array_is_list($raw))) {
            $f->nestedError('faqs', ['non_field_errors' => ['Expected a list of items but got type "'.get_debug_type($raw).'".']]);

            return null;
        }

        $clean = [];
        $itemErrors = [];
        $hasError = false;
        foreach ($raw as $item) {
            $errs = [];
            if (! is_array($item)) {
                $itemErrors[] = ['non_field_errors' => ['Invalid data. Expected a dictionary, but got '.get_debug_type($item).'.']];
                $hasError = true;

                continue;
            }
            $vals = [];
            foreach (['question' => 300, 'answer' => 2000] as $key => $max) {
                if (! array_key_exists($key, $item)) {
                    $errs[$key] = ['This field is required.'];

                    continue;
                }
                $v = $item[$key];
                $v = $v === null ? '' : (is_int($v) || is_float($v) ? (string) $v : $v);
                if (! is_string($v)) {
                    $errs[$key] = ['Not a valid string.'];
                } elseif (mb_strlen(trim($v)) > $max) {
                    $errs[$key] = ["Ensure this field has no more than {$max} characters."];
                } else {
                    $vals[$key] = PlainText::strip($v);
                }
            }
            if (! $errs && ($vals['question'] === '') !== ($vals['answer'] === '')) {
                $errs = ['non_field_errors' => ['Each FAQ needs both a question and an answer.']];
            }
            if ($errs) {
                $hasError = true;
                $itemErrors[] = $errs;

                continue;
            }
            $itemErrors[] = (object) [];
            if ($vals['question'] !== '' || $vals['answer'] !== '') {
                $clean[] = ['question' => $vals['question'], 'answer' => $vals['answer']];
            }
        }
        if ($hasError) {
            $f->nestedError('faqs', $itemErrors);

            return null;
        }
        if (count($clean) > self::MAX_FAQS) {
            $f->nestedError('faqs', ['An article can have at most '.self::MAX_FAQS.' FAQs.']);

            return null;
        }

        return $clean;
    }

    private static function subcategory(DrfFields $f): ?Subcategory
    {
        $slug = $f->string('subcategory_slug', 170, required: true, blank: false);
        if ($slug === null) {
            return null;
        }
        $matches = Subcategory::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('category', fn ($c) => $c->where('is_active', true)
                ->whereHas('industry', fn ($i) => $i->where('is_active', true)))
            ->with('category')
            ->limit(2)
            ->get();
        if ($matches->count() > 1) {
            $f->error('subcategory_slug', "More than one active subcategory has the slug \"{$slug}\", under different categories. Filter GET /api/subcategories/?slug={$slug} (optionally with &category=<category-slug>) to find the exact one you mean.");

            return null;
        }
        if ($matches->isEmpty()) {
            $f->error('subcategory_slug', "Object with slug={$slug} does not exist.");

            return null;
        }

        return $matches->first();
    }

    /** @return list<int>|null */
    private static function tags(DrfFields $f): ?array
    {
        $raw = $f->value('tag_slugs');
        if ($raw === null) {
            $f->error('tag_slugs', 'This field may not be null.');

            return null;
        }
        if (! is_array($raw) || ($raw !== [] && ! array_is_list($raw))) {
            $f->error('tag_slugs', 'Expected a list of items but got type "'.get_debug_type($raw).'".');

            return null;
        }
        $ids = [];
        foreach ($raw as $slug) {
            if (! is_string($slug) && ! is_int($slug)) {
                $f->error('tag_slugs', 'Incorrect type. Expected slug value, received '.get_debug_type($slug).'.');

                return null;
            }
            $id = Tag::query()->where('slug', (string) $slug)->value('id');
            if ($id === null) {
                $f->error('tag_slugs', "Object with slug={$slug} does not exist.");

                return null;
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }
}
