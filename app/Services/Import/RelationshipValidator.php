<?php

namespace App\Services\Import;

use App\Services\Import\Support\TargetSchema;
use Illuminate\Support\Facades\DB;

/** Post-import verification against the TARGET database (counts, FKs, featured-image rule, author/assignee integrity ...). */
class RelationshipValidator
{
    private const FKS = [
        ['categories', 'industry_id', 'industries'], ['subcategories', 'category_id', 'categories'],
        ['articles', 'author_id', 'users'], ['articles', 'assigned_reporter_id', 'users'], ['articles', 'subcategory_id', 'subcategories'], ['articles', 'category_id', 'categories'],
        ['article_tag', 'article_id', 'articles'], ['article_tag', 'tag_id', 'tags'],
        ['article_images', 'article_id', 'articles'], ['article_images', 'uploaded_by_id', 'users'],
        ['article_reviews', 'article_id', 'articles'], ['article_reviews', 'reviewer_id', 'users'],
        ['publishing_schedules', 'article_id', 'articles'], ['publishing_schedules', 'scheduled_by_id', 'users'],
        ['reporter_category_assignments', 'reporter_id', 'users'], ['reporter_category_assignments', 'category_id', 'categories'], ['reporter_category_assignments', 'assigned_by_id', 'users'],
        ['subscriptions', 'user_id', 'users'], ['subscriptions', 'plan_id', 'subscription_plans'],
        ['payments', 'subscription_id', 'subscriptions'], ['payments', 'user_id', 'users'],
        ['notifications', 'recipient_id', 'users'], ['notifications', 'article_id', 'articles'],
        ['article_daily_views', 'article_id', 'articles'],
        ['ai_analysis_results', 'article_id', 'articles'], ['ai_analysis_results', 'requested_by_id', 'users'],
        ['plagiarism_check_results', 'article_id', 'articles'], ['plagiarism_check_results', 'requested_by_id', 'users'],
    ];

    public function __construct(private LegacyReader $legacy, private ImportReport $report) {}

    public function run(array $ranEntities, bool $targetWasEmpty, bool $sequencesReset): void
    {
        $r = $this->report;
        $ran = array_fill_keys($ranEntities, true);

        // 1. counts
        foreach ($r->entities() as $name => $e) {
            if (! isset($ran[$name])) {
                continue;
            }
            $table = ImportContext::TARGET_TABLES[$name] ?? null;
            $accounted = $e['imported'] + $e['skipped'];
            if ($accounted !== $e['source_count']) {
                $r->validation("count:{$name}", 'fail', "source {$e['source_count']} != imported {$e['imported']} + skipped {$e['skipped']}");

                continue;
            }
            if ($table) {
                $inTarget = DB::table($table)->count();
                $ok = $targetWasEmpty ? $inTarget === $e['imported'] : $inTarget >= $e['imported'];
                $r->validation("count:{$name}", $ok ? 'pass' : 'fail', "source {$e['source_count']} = imported {$e['imported']} + skipped {$e['skipped']}; target rows {$inTarget}");
            }
        }

        // 2. FK integrity
        $bad = 0;
        foreach (self::FKS as [$child, $col, $parent]) {
            $n = (int) DB::table($child)->whereNotNull($col)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($parent)->whereColumn("{$parent}.id", "{$child}.{$col}"))->count();
            if ($n > 0) {
                $bad += $n;
                $r->validation("fk:{$child}.{$col}", 'fail', "{$n} row(s) reference a missing {$parent} row");
            }
        }
        $r->validation('fk:integrity', $bad === 0 ? 'pass' : 'fail', $bad === 0 ? count(self::FKS).' foreign-key relations checked, no orphans' : "{$bad} orphan reference(s)");

        // 3. taxonomy mirror + missing relationships
        $mirror = (int) DB::table('articles as a')->join('subcategories as s', 's.id', '=', 'a.subcategory_id')->whereRaw('NOT (a.category_id <=> s.category_id)')->count(); // <=> is the NULL-safe equality operator
        $r->validation('articles.category mirrors subcategory.category', $mirror === 0 ? 'pass' : 'fail', "{$mirror} article(s) where category_id != subcategory.category_id");
        $noSub = DB::table('articles')->whereNull('subcategory_id')->count();
        $noTax = DB::table('articles')->whereNull('subcategory_id')->whereNull('category_id')->count();
        $r->validation('articles without subcategory', $noSub === 0 ? 'pass' : 'warn', "{$noSub} article(s) without subcategory ({$noTax} also without category) - needs editor decision");
        $noInd = DB::table('categories')->whereNull('industry_id')->count();
        $r->validation('categories without industry', $noInd === 0 ? 'pass' : 'warn', "{$noInd} legacy category row(s) with industry_id NULL");

        // 4. featured image rule
        $badFeat = DB::table('article_images')->select('article_id')->groupBy('article_id')->havingRaw('SUM(CASE WHEN is_featured THEN 1 ELSE 0 END) <> 1')->get()->count();
        $r->validation('exactly one featured image per article with images', $badFeat === 0 ? 'pass' : 'fail', "{$badFeat} article(s) violate the rule");

        // 5. author / assigned integrity
        $noAuthor = DB::table('articles')->whereNull('author_id')->count();
        $r->validation('every article has an author', $noAuthor === 0 ? 'pass' : 'fail', "{$noAuthor} article(s) without author");
        $badAssign = DB::table('articles as a')->join('users as u', 'u.id', '=', 'a.assigned_reporter_id')->whereNotIn('u.role', ['REPORTER', 'ADMIN'])->count();
        $r->validation('assigned reporters are REPORTER/ADMIN', $badAssign === 0 ? 'pass' : 'warn', "{$badAssign} article(s) assigned to a user that is not a reporter/admin");

        // 6. single PENDING schedule
        $multi = DB::table('publishing_schedules')->where('status', 'PENDING')->select('article_id')->groupBy('article_id')->havingRaw('count(*) > 1')->get()->count();
        $r->validation('single PENDING schedule per article', $multi === 0 ? 'pass' : 'fail', "{$multi} article(s) with several PENDING schedules");

        // 7. users: emails, passwords, roles
        // GROUP BY compares with the column's collation (utf8mb4_unicode_ci): equal groups = what the unique index rejects.
        $dupEmail = DB::table('users')->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->get()->count();
        $r->validation('emails unique case-insensitively', $dupEmail === 0 ? 'pass' : 'fail', "{$dupEmail} duplicate group(s) under the column collation (case/accent/trailing-space insensitive)");
        // Checked PHP-side (exact, engine independent): PBKDF2 / bcrypt shapes only.
        $badHash = 0;
        $tp = 0;
        DB::table('users')->select('id', 'password')->orderBy('id')->chunkById(2000, function ($rows) use (&$badHash, &$tp) {
            foreach ($rows as $u) {
                $p = (string) $u->password;
                if (preg_match('/^pbkdf2_sha256\$[0-9]+\$/', $p)) {
                    $tp++;
                } elseif (! preg_match('/^\$2[abxy]\$/', $p)) {
                    $badHash++;
                }
            }
        });
        $r->validation('every password hash is verifiable', $badHash === 0 ? 'pass' : 'fail', "{$badHash} user(s) with an unrecognised hash");

        if (isset($ran['users']) && $this->legacy) {
            $suIds = array_map(fn ($x) => (int) $x['id'], $this->legacy->select('SELECT id FROM accounts_user WHERE is_superuser ORDER BY id'));
            if ($suIds) {
                $notAdmin = DB::table('users')->whereIn('id', $suIds)->where('role', '<>', 'ADMIN')->count();
                $r->validation('Django superusers imported as ADMIN', $notAdmin === 0 ? 'pass' : 'fail', count($suIds).' superuser(s), '.$notAdmin.' not ADMIN');
            } else {
                $r->validation('Django superusers imported as ADMIN', 'pass', 'no superusers in the legacy database');
            }
            $py = (int) ($r->stats()['users_password_algorithms']['pbkdf2_sha256'] ?? 0);
            $r->validation('PBKDF2 hashes imported unchanged', $tp >= $py || ! $targetWasEmpty ? 'pass' : 'fail', "legacy pbkdf2 hashes {$py}, target pbkdf2 hashes {$tp}");
        }

        // 8. AUTO_INCREMENT counters
        if ($sequencesReset) {
            $badSeq = [];
            foreach (SequenceResetter::TABLES as $table) {
                $max = (int) DB::table($table)->max('id');
                $next = TargetSchema::autoIncrement($table);
                if ($next === null || $next <= $max) {
                    $badSeq[] = $table;
                }
            }
            $r->validation('sequences ahead of MAX(id)', $badSeq ? 'fail' : 'pass', $badSeq ? 'behind: '.implode(', ', $badSeq) : count(SequenceResetter::TABLES).' AUTO_INCREMENT counters checked (information_schema.TABLES)');
        }
    }
}
