<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ImportContext
{
    /** @var array<string, array<int, true>> entity => imported/would-import ids */
    private array $ids = [];

    /** @var array<string, true> entities processed by this invocation */
    private array $ran = [];

    /** @var array<int, int> subcategory id => category id (imported subcategories) */
    public array $subcategoryCategory = [];

    private ?string $unusableHash = null;

    /** Target table for each entity (used to look up ids of entities not part of this run). */
    public const TARGET_TABLES = [
        'users' => 'users', 'industries' => 'industries', 'categories' => 'categories', 'subcategories' => 'subcategories',
        'tags' => 'tags', 'subscription_plans' => 'subscription_plans', 'articles' => 'articles', 'article_tags' => 'article_tag',
        'article_images' => 'article_images', 'article_reviews' => 'article_reviews', 'publishing_schedules' => 'publishing_schedules',
        'reporter_assignments' => 'reporter_category_assignments', 'subscriptions' => 'subscriptions', 'payments' => 'payments',
        'notifications' => 'notifications', 'advertisements' => 'advertisements', 'article_daily_views' => 'article_daily_views',
        'ai_analyses' => 'ai_analysis_results', 'plagiarism_checks' => 'plagiarism_check_results',
    ];

    public function __construct(
        public readonly LegacyReader $reader,
        public readonly ImportReport $report,
        public readonly ResolutionPlan $plan,
        public readonly bool $dryRun,
        public readonly int $chunk,
        public readonly bool $targetEmpty,
    ) {}

    public function markRan(string $entity): void
    {
        $this->ran[$entity] = true;
        $this->ids[$entity] ??= [];
    }

    public function markImported(string $entity, int $id): void
    {
        $this->ids[$entity][$id] = true;
    }

    /** Was this parent row imported (or, for entities not in this run, does it already exist in the target)? */
    public function has(string $entity, int|string|null $id): bool
    {
        if ($id === null) {
            return false;
        }
        if (! isset($this->ran[$entity]) && ! isset($this->ids[$entity])) {
            $table = self::TARGET_TABLES[$entity];
            $this->ids[$entity] = array_fill_keys(DB::table($table)->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
        }

        return isset($this->ids[$entity][(int) $id]);
    }

    public function skip(string $entity, int|string|null $id, string $rule, string $reason): void
    {
        $this->report->unresolved($entity, $id, $rule, $reason);
    }

    /** @var array<int, ?int> */
    private array $subcategoryCategoryCache = [];

    /** Category id of a subcategory (imported in this run, else looked up in the target). */
    public function categoryOfSubcategory(int $id): ?int
    {
        if (isset($this->subcategoryCategory[$id])) {
            return $this->subcategoryCategory[$id];
        }
        if (! array_key_exists($id, $this->subcategoryCategoryCache)) {
            $v = DB::table('subcategories')->where('id', $id)->value('category_id');
            $this->subcategoryCategoryCache[$id] = $v === null ? null : (int) $v;
        }

        return $this->subcategoryCategoryCache[$id];
    }

    /** One shared, random, never-disclosed bcrypt hash used for unusable/unsupported Django hashes. */
    public function unusableHash(): string
    {
        return $this->unusableHash ??= Hash::make(bin2hex(random_bytes(32)));
    }
}
