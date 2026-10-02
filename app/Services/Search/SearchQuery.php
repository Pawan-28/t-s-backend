<?php

namespace App\Services\Search;

/**
 * Turns the user's "web search" text (words, "quoted phrases", -exclusions, `or`) into MySQL/MariaDB
 * InnoDB FULLTEXT BOOLEAN MODE strings. Replaces PostgreSQL websearch_to_tsquery.
 *
 *  - every plain word is REQUIRED (`+word*`, prefix match: the closest thing to English stemming
 *    InnoDB offers; a light suffix strip makes "elections" match "election");
 *  - `"a phrase"` -> `+"a phrase"`;  `-word` -> `-word`;  `a or b` -> `+(a* b*)`;
 *  - English stop words and words shorter than the InnoDB minimum token size (innodb_ft_min_token_size,
 *    3 by default and NOT changeable on shared hosting) are dropped, because InnoDB would ignore them
 *    and a required-but-ignored word would make the whole query match nothing;
 *  - every boolean operator character typed by the user is stripped, so user text can never inject
 *    FULLTEXT operators. The result is always passed as a bound parameter.
 */
final class SearchQuery
{
    /** @var list<string> */
    public array $terms = [];          // usable single words (lower-case, no operators)

    /** @var list<string> */
    public array $phrases = [];

    /** @var list<string> */
    public array $excluded = [];

    public ?string $boolean = null;    // MATCH ... AGAINST (? IN BOOLEAN MODE) string for the combined index

    public ?string $optional = null;   // operator-free term list, used only to add per-column relevance boosts

    private const STOP = [
        'a', 'about', 'above', 'after', 'again', 'all', 'am', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'because', 'been',
        'before', 'being', 'below', 'between', 'both', 'but', 'by', 'can', 'com', 'did', 'do', 'does', 'doing', 'down', 'during',
        'each', 'few', 'for', 'from', 'further', 'had', 'has', 'have', 'having', 'he', 'her', 'here', 'hers', 'him', 'his', 'how',
        'i', 'if', 'in', 'into', 'is', 'it', 'its', 'just', 'me', 'more', 'most', 'my', 'no', 'nor', 'not', 'now', 'of', 'off',
        'on', 'once', 'only', 'or', 'other', 'our', 'ours', 'out', 'over', 'own', 'same', 'she', 'should', 'so', 'some', 'such',
        'than', 'that', 'the', 'their', 'theirs', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'to', 'too',
        'under', 'until', 'up', 'very', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'whom', 'why',
        'will', 'with', 'would', 'www', 'you', 'your', 'yours',
    ];

    public static function parse(string $text, int $minToken = 3): self
    {
        $q = new self;
        $text = mb_strtolower(trim($text));
        $q->phrases = [];
        // 1. pull out "quoted phrases"
        $phraseTokens = [];
        $text = preg_replace_callback('/(-?)"([^"]*)"/u', function ($m) use (&$phraseTokens, $minToken) {
            $neg = $m[1] === '-';
            $words = self::words($m[2], $minToken, false);
            if (count($words) === 1) {
                $phraseTokens[] = ['type' => $neg ? 'not' : 'word', 'v' => $words[0]];
            } elseif ($words) {
                $phraseTokens[] = ['type' => $neg ? 'notphrase' : 'phrase', 'v' => implode(' ', $words)];
            }

            return ' ';
        }, $text) ?? '';

        // 2. remaining words / -exclusions / or
        $tokens = [];
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            if ($raw === 'or') {
                $tokens[] = ['type' => 'or'];

                continue;
            }
            $neg = str_starts_with($raw, '-');
            foreach (self::words($raw, $minToken, ! $neg) as $w) {
                $tokens[] = ['type' => $neg ? 'not' : 'word', 'v' => $w];
            }
        }
        $tokens = array_merge($tokens, $phraseTokens);

        // 3. build the boolean expression
        $parts = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t['type'] === 'or') {
                continue;
            }
            if ($t['type'] === 'not') {
                $q->excluded[] = $t['v'];
                $parts[] = '-'.$t['v'].'*';

                continue;
            }
            if ($t['type'] === 'notphrase') {
                $q->excluded[] = $t['v'];
                $parts[] = '-"'.$t['v'].'"';

                continue;
            }
            if ($t['type'] === 'phrase') {
                $q->phrases[] = $t['v'];
                $parts[] = '+"'.$t['v'].'"';

                continue;
            }
            $group = [$t['v']];
            while ($i + 2 < $n && $tokens[$i + 1]['type'] === 'or' && $tokens[$i + 2]['type'] === 'word') {
                $group[] = $tokens[$i + 2]['v'];
                $i += 2;
            }
            foreach ($group as $w) {
                $q->terms[] = $w;
            }
            $parts[] = count($group) === 1
                ? '+'.self::prefix($group[0])
                : '+('.implode(' ', array_map(fn ($w) => self::prefix($w), $group)).')';
        }
        $positive = array_filter($parts, fn ($p) => $p[0] !== '-');
        if ($positive) { // a query with only exclusions matches nothing (same as websearch_to_tsquery)
            $q->boolean = implode(' ', $parts);
            $q->optional = implode(' ', array_map(fn ($w) => self::prefix($w), $q->terms));
            foreach ($q->phrases as $ph) {
                $q->optional .= ' "'.$ph.'"';
            }
            $q->optional = trim($q->optional);
        }

        return $q;
    }

    /** Words of a fragment: letters/digits (any script), lower-case, stop words and too-short words dropped. */
    private static function words(string $fragment, int $minToken, bool $dropStop): array
    {
        $fragment = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', $fragment) ?? '';
        $out = [];
        foreach (preg_split('/\s+/u', trim($fragment), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (mb_strlen($w) < $minToken || ($dropStop && in_array($w, self::STOP, true))) {
                continue;
            }
            $out[] = $w;
        }

        return $out;
    }

    /** Prefix wildcard with a light English suffix strip (elections -> election*, reported -> report*). */
    private static function prefix(string $w): string
    {
        if (! preg_match('/^[a-z]+$/', $w)) {
            return $w.'*';
        }
        foreach (['ing', 'ed', 'es', 's'] as $suffix) {
            if (str_ends_with($w, $suffix) && mb_strlen($w) - mb_strlen($suffix) >= 4) {
                $w = substr($w, 0, -strlen($suffix));
                break;
            }
        }

        return $w.'*';
    }

    /** Terms (words only) for the LIKE fallback: all must appear as substrings. */
    public function likeTerms(): array
    {
        return array_values(array_unique(array_merge($this->terms, $this->phrases)));
    }

    /** Plain words even when FULLTEXT cannot be used at all (all words shorter than the token size etc). */
    public static function fallbackWords(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/"|-(?=\S)/u', ' ', $text) ?? '';
        $w = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($w, fn ($x) => $x !== 'or' && ! in_array($x, self::STOP, true))));
    }
}
