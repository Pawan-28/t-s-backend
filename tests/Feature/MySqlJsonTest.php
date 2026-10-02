<?php

namespace Tests\Feature;

use App\Models\AiAnalysisResult;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\PlagiarismCheckResult;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MySQL JSON re-serialises (sorted keys, ", " and ": " spacing, unescaped unicode) and rejects invalid text;
 * MariaDB keeps LONGTEXT verbatim behind a JSON_VALID() CHECK. Application code only ever sees decoded arrays, so the
 * contract is "decodes to the same value" - key ORDER is not part of it (PostgreSQL jsonb reordered keys too).
 */
class MySqlJsonTest extends TestCase
{
    use RefreshDatabase;

    /** Canonical form for order-insensitive comparison of decoded JSON objects (lists keep their order). */
    private function canon(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(fn ($x) => $this->canon($x), $v);
        if (! array_is_list($v)) {
            ksort($v);
        }

        return $v;
    }

    public function test_article_faqs_round_trip_including_unicode_quotes_and_empty_list(): void
    {
        $faqs = [
            ['question' => 'क्या? 🤔', 'answer' => "उत्तर \"quoted\" \\ back / slash \u{2028} <b>tag</b> 😀"],
            ['question' => 'Q2', 'answer' => "line1\nline2\ttab"],
        ];
        $a = Article::factory()->create(['faqs' => $faqs]);
        $this->assertSame($this->canon($faqs), $this->canon($a->fresh()->faqs));
        $raw = DB::table('articles')->where('id', $a->id)->value('faqs');
        $this->assertSame($this->canon($faqs), $this->canon(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)));

        $empty = Article::factory()->create(['faqs' => []]);
        $this->assertSame([], $empty->fresh()->faqs);
        $this->assertSame([], json_decode(DB::table('articles')->where('id', $empty->id)->value('faqs'), true), 'an empty list stays a list ([]), never {} or null');
        $this->assertSame('[]', preg_replace('/\s+/', '', DB::table('articles')->where('id', $empty->id)->value('faqs')));

        // model default (no faqs attribute at all): TEXT/JSON cannot have a DB default on MySQL, the model supplies '[]'
        $fresh = new Article;
        $this->assertSame('[]', $fresh->getAttributes()['faqs']);
    }

    public function test_raw_json_with_unicode_escapes_and_surrogate_pairs_decodes_like_utf8(): void
    {
        $a = Article::factory()->create();
        DB::table('articles')->where('id', $a->id)->update(['faqs' => '[{"question":"नमस्ते 😀","answer":"a"}]']);
        $this->assertSame($this->canon([['question' => 'नमस्ते 😀', 'answer' => 'a']]), $this->canon($a->fresh()->faqs));
        DB::table('articles')->where('id', $a->id)->update(['faqs' => '[{"answer":"a","question":"नमस्ते 😀"}]']);
        $this->assertSame($this->canon([['answer' => 'a', 'question' => 'नमस्ते 😀']]), $this->canon($a->fresh()->faqs));
    }

    public function test_invalid_json_is_rejected_by_the_database_on_both_engines(): void
    {
        $a = Article::factory()->create();
        foreach (['{bad', '', '[1,2', "{'a':1}"] as $bad) {
            try {
                DB::table('articles')->where('id', $a->id)->update(['faqs' => $bad]);
                $this->fail('invalid JSON was accepted: '.$bad);
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/JSON|CONSTRAINT|Check constraint|3140|4025/i', $e->getMessage());
            }
        }
        $this->assertSame([], $a->fresh()->faqs);
    }

    public function test_image_metadata_ints_null_and_empty(): void
    {
        $a = Article::factory()->create();
        $mk = fn (?array $meta) => ArticleImage::query()->create(['article_id' => $a->id, 'uploaded_by_id' => $a->author_id, 'bunny_url' => 'https://x/1.jpg', 'bunny_storage_path' => 'articles/'.$a->slug.'/'.uniqid().'.jpg', 'metadata' => $meta]);
        $meta = ['original_filename' => 'फ़ोटो 😀.jpg', 'content_type' => 'image/jpeg', 'file_size_bytes' => 5_000_000_000, 'width' => 4000, 'height' => 3000, 'checksum' => str_repeat('a', 64)];
        $img = $mk($meta);
        $back = $img->fresh()->metadata;
        $this->assertSame($this->canon($meta), $this->canon($back));
        $this->assertSame(5_000_000_000, $back['file_size_bytes'], 'ints beyond 2^31 survive');
        $this->assertNull($mk(null)->fresh()->metadata, 'SQL NULL stays NULL (not the JSON literal null)');
        $this->assertSame([], $mk([])->fresh()->metadata);
    }

    public function test_score_and_matches_json_floats_are_exact(): void
    {
        $art = Article::factory()->create();
        $matches = [['source_url' => 'https://a.example/x?y=1&z=2#f', 'similarity_percent' => 0.1 + 0.2, 'matched_text' => 'नकल 😀 "x"'], ['source_url' => '', 'similarity_percent' => 100.0, 'matched_text' => '']];
        $p = PlagiarismCheckResult::query()->create(['article_id' => $art->id, 'provider' => 'COPYLEAKS', 'scan_id' => 'j1', 'status' => 'COMPLETED', 'error_message' => '', 'similarity_score' => 42.5, 'matches' => $matches]);
        $back = $p->fresh()->matches;
        $this->assertSame(0.1 + 0.2, $back[0]['similarity_percent']);
        $this->assertSame(100.0, (float) $back[1]['similarity_percent']);
        $this->assertSame($matches[0]['matched_text'], $back[0]['matched_text']);
        $this->assertSame([], PlagiarismCheckResult::query()->create(['article_id' => $art->id, 'provider' => 'COPYLEAKS', 'scan_id' => 'j2', 'status' => 'PENDING', 'error_message' => ''])->fresh()->matches, 'default [] (model attribute)');

        $ai = AiAnalysisResult::query()->create(['article_id' => $art->id, 'provider' => 'OPENAI', 'model_name' => 'm', 'status' => 'COMPLETED', 'error_message' => '', 'grammar_issues' => [['text' => 'त्रुटि', 'suggestion' => 's"1"']], 'seo_suggestions' => ['एक', 'two 😀'], 'ai_content_rationale' => '']);
        $this->assertSame($this->canon([['text' => 'त्रुटि', 'suggestion' => 's"1"']]), $this->canon($ai->fresh()->grammar_issues));
        $this->assertSame(['एक', 'two 😀'], $ai->fresh()->seo_suggestions);
    }
}
