<?php

namespace Tests\Feature\Import;

use Illuminate\Support\Facades\DB;

class ImportValidationTest extends ImportTestCase
{
    public function test_case_duplicate_emails_block_the_import_and_nothing_is_written(): void
    {
        $a = $this->legacy->user(['email' => 'Dup@example.test']);
        $b = $this->legacy->user(['email' => 'dup@example.test']);

        [$exit, $r] = $this->runImport();

        $this->assertSame(2, $exit);
        $this->assertSame('blocked', $r['run']['status']);
        $f = $this->findings($r, 'USR-EMAIL-CASE-DUP');
        $this->assertCount(1, $f);
        $this->assertSame('blocking', $f[0]['severity']);
        $this->assertSame($b, $f[0]['source_id']);
        $this->assertSame('users', $f[0]['entity']);
        $this->assertNotEmpty($f[0]['resolution']);
        $this->assertNotEmpty($f[0]['reason']);
        $this->assertSame(0, $this->rows('users'), 'a blocked run must not write anything');
    }

    public function test_resolve_defaults_keeps_both_users_with_a_deterministic_alias(): void
    {
        $a = $this->legacy->user(['email' => 'Dup@example.test']);
        $b = $this->legacy->user(['email' => 'dup@example.test']);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(2, $this->rows('users'));
        $ua = DB::table('users')->find($a);
        $ub = DB::table('users')->find($b);
        $this->assertSame('dup@example.test', $ua->email);
        $this->assertTrue((bool) $ua->is_active);
        $this->assertSame("dup+dup{$b}@example.test", $ub->email);
        $this->assertFalse((bool) $ub->is_active);
        $this->assertSame(0, DB::table('users')->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->get()->count());
    }

    public function test_blank_and_padded_emails(): void
    {
        $blank = $this->legacy->user(['email' => '']);
        $pad = $this->legacy->user(['email' => '  Padded@Example.Test ']);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame("legacy-user-{$blank}@invalid.local", DB::table('users')->find($blank)->email);
        $this->assertFalse((bool) DB::table('users')->find($blank)->is_active);
        $this->assertSame('padded@example.test', DB::table('users')->find($pad)->email);
        $this->assertNotEmpty($this->findings($r, 'USR-EMAIL-BLANK'));
        $this->assertSame('info', $this->findings($r, 'USR-EMAIL-NORMALIZED')[0]['severity']);
    }

    public function test_phone_inconsistencies_are_reported_and_resolved(): void
    {
        $p1 = $this->legacy->user(['phone' => '+91 98765 43210']);
        $p2 = $this->legacy->user(['phone' => '+919876543210', 'phone_verified_at' => '2026-01-01 00:00:00+00']);
        $blank = $this->legacy->user(['phone' => '']);
        $bad = $this->legacy->user(['phone' => 'call-me-maybe']);
        $none = $this->legacy->user(['phone' => null]);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($p1, $this->findings($r, 'USR-PHONE-DUP')[0]['source_id']);
        $this->assertSame($blank, $this->findings($r, 'USR-PHONE-BLANK')[0]['source_id']);
        $this->assertSame($bad, $this->findings($r, 'USR-PHONE-MALFORMED')[0]['source_id']);
        $this->assertNull(DB::table('users')->find($p1)->phone);           // duplicate cleared
        $this->assertSame('+919876543210', DB::table('users')->find($p2)->phone); // verified user keeps it
        $this->assertNotNull(DB::table('users')->find($p2)->phone_verified_at);
        $this->assertNull(DB::table('users')->find($blank)->phone);         // '' -> NULL
        $this->assertSame('call-me-maybe', DB::table('users')->find($bad)->phone); // malformed kept as-is, flagged
        $this->assertNull(DB::table('users')->find($none)->phone);
        // the phone number itself never appears in the report
        $this->assertStringNotContainsString('9876543210', json_encode($r));
    }

    public function test_orphan_rows_are_reported_skipped_and_cascade_from_skipped_articles(): void
    {
        $ids = $this->legacy->baseline();
        $ghostArticle = $this->legacy->article(['author_id' => 99999, 'subcategory_id' => $ids['sub'], 'category_id' => $ids['cat']]);
        $this->legacy->insert('articles_article_tags', ['article_id' => $ghostArticle, 'tag_id' => $ids['tag']]);
        $this->legacy->insert('articles_article_tags', ['article_id' => $ids['art'], 'tag_id' => 88888]);
        $this->legacy->image(['article_id' => $ghostArticle, 'uploaded_by_id' => $ids['rep']]);
        $this->legacy->image(['article_id' => 77777, 'uploaded_by_id' => $ids['rep']]);
        $this->legacy->insert('notifications_notification', ['recipient_id' => 66666, 'notification_type' => 'ARTICLE_APPROVED', 'message' => 'x', 'is_read' => false, 'created_at' => '2026-01-01 00:00:00+00']);
        $this->legacy->insert('reporters_articlereview', ['article_id' => $ghostArticle, 'reviewer_id' => $ids['admin'], 'action' => 'SUBMITTED', 'from_status' => 'DRAFT', 'to_status' => 'SUBMITTED', 'reason' => '', 'created_at' => '2026-01-01 00:00:00+00']);
        $this->legacy->insert('analytics_articledailyview', ['article_id' => $ghostArticle, 'date' => '2026-01-01', 'views' => 1, 'created_at' => '2026-01-01 00:00:00+00', 'updated_at' => '2026-01-01 00:00:00+00']);
        $this->legacy->subscription(['user_id' => 55555, 'plan_id' => $ids['plan']]);
        $this->legacy->subscription(['user_id' => $ids['reader'], 'plan_id' => 44444]);
        $this->legacy->payment(['subscription_id' => 33333, 'user_id' => $ids['reader']]);

        [$exit, $blocked] = $this->runImport();
        $this->assertSame(2, $exit);
        $this->assertSame(0, $this->rows('users'));

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(0, $exit, json_encode($r['errors'] ?? []));

        foreach (['ART-AUTHOR-ORPHAN', 'ART-TAG-ORPHAN', 'IMG-ARTICLE-ORPHAN', 'NTF-RECIPIENT-ORPHAN', 'REV-ARTICLE-ORPHAN', 'VIEW-ARTICLE-ORPHAN', 'SUB-USER-ORPHAN', 'SUB-PLAN-ORPHAN', 'PAY-SUBSCRIPTION-ORPHAN'] as $rule) {
            $this->assertNotEmpty($this->findings($r, $rule), "missing finding {$rule}");
        }
        foreach ($this->findings($r, 'ART-AUTHOR-ORPHAN') as $f) {
            $this->assertSame('blocking', $f['severity']);
            $this->assertSame($ghostArticle, $f['source_id']);
        }
        $this->assertNull(DB::table('articles')->find($ghostArticle));
        $this->assertSame(1, $this->rows('articles'));
        $this->assertSame(1, $this->rows('article_tag'));
        $this->assertSame(1, $this->rows('article_images'));
        $this->assertSame(1, $this->rows('subscriptions'));
        $this->assertSame(1, $this->rows('payments'));
        $entities = array_column($r['unresolved_records'], 'entity');
        foreach (['articles', 'article_tags', 'article_images', 'notifications', 'article_reviews', 'article_daily_views', 'subscriptions', 'payments'] as $e) {
            $this->assertContains($e, $entities, "unresolved list lacks {$e}");
        }
        // every source row is either imported or listed as unresolved
        foreach ($r['entities'] as $name => $e) {
            $this->assertSame($e['source_count'], $e['imported'] + $e['skipped'], $name);
        }
    }

    public function test_pending_subscriptions_are_reported_and_imported_as_pending(): void
    {
        $ids = $this->legacy->baseline();
        $pending = $this->legacy->subscription(['user_id' => $ids['reader'], 'plan_id' => $ids['plan'], 'status' => 'PENDING']);
        $pendingPaid = $this->legacy->subscription(['user_id' => $ids['rep'], 'plan_id' => $ids['plan'], 'status' => 'PENDING']);
        $this->legacy->payment(['subscription_id' => $pendingPaid, 'user_id' => $ids['rep'], 'status' => 'PAID']);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('PENDING', DB::table('subscriptions')->find($pending)->status);
        $this->assertSame('PENDING', DB::table('subscriptions')->find($pendingPaid)->status, 'never auto-activated');
        $this->assertSame($pending, $this->findings($r, 'SUB-PENDING')[0]['source_id']);
        $this->assertSame('warning', $this->findings($r, 'SUB-PENDING')[0]['severity']);
        $this->assertSame($pendingPaid, $this->findings($r, 'SUB-PENDING-PAID')[0]['source_id']);
        $this->assertSame(2, $r['stats']['subscriptions_pending']);
    }

    public function test_taxonomy_problems(): void
    {
        $ind = $this->legacy->industry();
        $catOk = $this->legacy->category(['industry_id' => $ind]);
        $catNoInd = $this->legacy->category(['industry_id' => null]);
        $catBadInd = $this->legacy->category(['industry_id' => 424242]);
        $subOk = $this->legacy->subcategory(['category_id' => $catOk]);
        $subOrphan = $this->legacy->subcategory(['category_id' => 313131]);
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $art = $this->legacy->article(['author_id' => $rep, 'subcategory_id' => $subOrphan, 'category_id' => $catOk]);
        $noSub = $this->legacy->article(['author_id' => $rep, 'subcategory_id' => null, 'category_id' => $catOk]);
        $nothing = $this->legacy->article(['author_id' => $rep, 'subcategory_id' => null, 'category_id' => null]);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertNull(DB::table('categories')->find($catNoInd)->industry_id);
        $this->assertNull(DB::table('categories')->find($catBadInd)->industry_id);
        $this->assertSame('warning', $this->findings($r, 'TAX-CAT-NO-INDUSTRY')[0]['severity']);
        $this->assertSame($catBadInd, $this->findings($r, 'TAX-CAT-INDUSTRY-ORPHAN')[0]['source_id']);
        $this->assertNull(DB::table('subcategories')->find($subOrphan));
        $this->assertSame('blocking', $this->findings($r, 'TAX-SUB-CATEGORY-ORPHAN')[0]['severity']);
        // the article of the skipped subcategory falls back to its legacy category
        $a = DB::table('articles')->find($art);
        $this->assertNull($a->subcategory_id);
        $this->assertSame($catOk, $a->category_id);
        $this->assertSame($noSub, $this->findings($r, 'ART-NO-SUBCATEGORY')[0]['source_id']);
        $this->assertSame($nothing, $this->findings($r, 'ART-NO-TAXONOMY')[0]['source_id']);
    }

    public function test_duplicate_slugs_are_suffixed_deterministically_and_recorded(): void
    {
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $draft = $this->legacy->article(['author_id' => $rep, 'slug' => 'Foo', 'status' => 'DRAFT', 'published_at' => null]);
        $published = $this->legacy->article(['author_id' => $rep, 'slug' => 'foo', 'status' => 'PUBLISHED']);
        $draft2 = $this->legacy->article(['author_id' => $rep, 'slug' => 'FOO', 'status' => 'DRAFT', 'published_at' => null]);
        $t1 = $this->legacy->tag(['name' => 'Alpha', 'slug' => 'Same']);
        $t2 = $this->legacy->tag(['name' => 'Beta', 'slug' => 'same']);
        $cat = $this->legacy->category();
        $s1 = $this->legacy->subcategory(['category_id' => $cat, 'name' => 'A', 'slug' => 'news']);
        $s2 = $this->legacy->subcategory(['category_id' => $cat, 'name' => 'B', 'slug' => 'News']);
        $cat2 = $this->legacy->category();
        $s3 = $this->legacy->subcategory(['category_id' => $cat2, 'name' => 'C', 'slug' => 'news']); // other category: no conflict

        [$exit, $r] = $this->runImport();
        $this->assertSame(2, $exit);
        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('foo', DB::table('articles')->find($published)->slug, 'published article keeps the public URL');
        $this->assertSame('Foo-2', DB::table('articles')->find($draft)->slug);
        $this->assertSame('FOO-3', DB::table('articles')->find($draft2)->slug);
        $this->assertSame('Same', DB::table('tags')->find($t1)->slug);
        $this->assertSame('same-2', DB::table('tags')->find($t2)->slug);
        $this->assertSame('news', DB::table('subcategories')->find($s1)->slug);
        $this->assertSame('News-2', DB::table('subcategories')->find($s2)->slug);
        $this->assertSame('news', DB::table('subcategories')->find($s3)->slug);
        $dup = $this->findings($r, 'SLUG-DUP', 'articles');
        $this->assertCount(2, $dup);
        $byId = array_column($dup, null, 'source_id');
        $this->assertSame('Foo', $byId[$draft]['old']);
        $this->assertSame('Foo-2', $byId[$draft]['new']);
        $this->assertSame('blocking', $byId[$draft]['severity']);
        $this->assertSame(0, DB::table('articles')->select('slug')->groupBy('slug')->havingRaw('COUNT(*) > 1')->get()->count());
    }

    public function test_invalid_article_references_fall_back_to_safe_values(): void
    {
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $badStatus = $this->legacy->article(['author_id' => $rep, 'status' => 'ARCHIVED']);
        $badAccess = $this->legacy->article(['author_id' => $rep, 'access_level' => 'PREMIUM']);
        $badFaq = $this->legacy->article(['author_id' => $rep, 'faqs' => '{"a": 1}']);
        $ok = $this->legacy->article(['author_id' => $rep, 'faqs' => '[{"question":"Q","answer":"A"}]', 'access_level' => 'SUBSCRIBER_ONLY']);
        $assigned = $this->legacy->article(['author_id' => $rep, 'assigned_reporter_id' => 12121]);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('DRAFT', DB::table('articles')->find($badStatus)->status);
        $this->assertSame('RESTRICTED', DB::table('articles')->find($badAccess)->access_level);
        $this->assertSame('[]', DB::table('articles')->find($badFaq)->faqs);
        $this->assertEquals([['question' => 'Q', 'answer' => 'A']], json_decode(DB::table('articles')->find($ok)->faqs, true));
        $this->assertSame('SUBSCRIBER_ONLY', DB::table('articles')->find($ok)->access_level);
        $this->assertNull(DB::table('articles')->find($assigned)->assigned_reporter_id);
        foreach (['ART-STATUS-INVALID', 'ART-ACCESS-INVALID', 'ART-FAQS-INVALID', 'ART-ASSIGNED-ORPHAN'] as $rule) {
            $this->assertNotEmpty($this->findings($r, $rule), $rule);
        }
    }

    public function test_exactly_one_featured_image_per_article(): void
    {
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $multi = $this->legacy->article(['author_id' => $rep]);
        $m1 = $this->legacy->image(['article_id' => $multi, 'uploaded_by_id' => $rep, 'is_featured' => true, 'updated_at' => '2026-01-01 00:00:00+00']);
        $m2 = $this->legacy->image(['article_id' => $multi, 'uploaded_by_id' => $rep, 'is_featured' => true, 'updated_at' => '2026-03-01 00:00:00+00']);
        $m3 = $this->legacy->image(['article_id' => $multi, 'uploaded_by_id' => $rep, 'is_featured' => false]);
        $none = $this->legacy->article(['author_id' => $rep]);
        $n1 = $this->legacy->image(['article_id' => $none, 'uploaded_by_id' => $rep, 'display_order' => 5]);
        $n2 = $this->legacy->image(['article_id' => $none, 'uploaded_by_id' => $rep, 'display_order' => 1]);
        $orphanUploader = $this->legacy->image(['article_id' => $none, 'uploaded_by_id' => 909090, 'display_order' => 9]);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame([$m2], DB::table('article_images')->where('article_id', $multi)->where('is_featured', true)->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([$n2], DB::table('article_images')->where('article_id', $none)->where('is_featured', true)->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame($rep, (int) DB::table('article_images')->find($orphanUploader)->uploaded_by_id, 'orphan uploader falls back to the article author');
        $this->assertSame($m1, $this->findings($r, 'IMG-MULTI-FEATURED')[0]['source_id']);
        $this->assertSame($n2, $this->findings($r, 'IMG-NO-FEATURED')[0]['source_id']);
        $this->assertSame('pass', collect($r['relationship_validation'])->firstWhere('check', 'exactly one featured image per article with images')['status']);
    }

    public function test_several_pending_schedules_keep_only_the_newest_pending(): void
    {
        $admin = $this->legacy->user(['role' => 'ADMIN', 'is_superuser' => true]);
        $art = $this->legacy->article(['author_id' => $admin, 'status' => 'SCHEDULED', 'published_at' => null]);
        $old = $this->legacy->schedule(['article_id' => $art, 'scheduled_by_id' => $admin, 'created_at' => '2026-01-01 00:00:00+00']);
        $new = $this->legacy->schedule(['article_id' => $art, 'scheduled_by_id' => $admin, 'created_at' => '2026-01-05 00:00:00+00']);
        $done = $this->legacy->schedule(['article_id' => $art, 'scheduled_by_id' => $admin, 'status' => 'EXECUTED']);
        $orphan = $this->legacy->schedule(['article_id' => $art, 'scheduled_by_id' => 5151, 'status' => 'CANCELLED']);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('CANCELLED', DB::table('publishing_schedules')->find($old)->status);
        $this->assertSame('PENDING', DB::table('publishing_schedules')->find($new)->status);
        $this->assertSame('EXECUTED', DB::table('publishing_schedules')->find($done)->status);
        $this->assertNull(DB::table('publishing_schedules')->find($orphan));
        $this->assertSame($old, $this->findings($r, 'SCH-MULTI-PENDING')[0]['source_id']);
        $this->assertSame($orphan, $this->findings($r, 'SCH-USER-ORPHAN')[0]['source_id']);
    }

    public function test_advertisement_placements(): void
    {
        $base = ['creative_type' => 'IMAGE', 'image_url' => 'https://cdn.example.test/a.png', 'bunny_storage_path' => 'ads/a.png', 'target_url' => '', 'start_at' => '2026-01-01 00:00:00+00', 'end_at' => '2027-01-01 00:00:00+00', 'is_active' => true, 'priority' => 3, 'created_at' => '2026-01-01 00:00:00+00', 'updated_at' => '2026-01-01 00:00:00+00'];
        $top = $this->legacy->insert('advertisements_advertisement', $base + ['name' => 'top', 'placement' => 'HOME_TOP']);
        $old = $this->legacy->insert('advertisements_advertisement', $base + ['name' => 'old', 'placement' => 'SIDEBAR']);
        $bad = $this->legacy->insert('advertisements_advertisement', $base + ['name' => 'bad', 'placement' => 'NOPE']);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('HOME_TOP', DB::table('advertisements')->find($top)->placement);
        $this->assertSame('ads/a.png', DB::table('advertisements')->find($top)->bunny_storage_path);
        $this->assertSame('HOME_SIDEBAR', DB::table('advertisements')->find($old)->placement);
        $this->assertNull(DB::table('advertisements')->find($bad));
        $this->assertSame($bad, $this->findings($r, 'ADS-PLACEMENT-INVALID')[0]['source_id']);
    }
}
