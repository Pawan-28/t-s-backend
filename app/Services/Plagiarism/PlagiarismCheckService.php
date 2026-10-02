<?php

namespace App\Services\Plagiarism;

use App\Models\Article;
use App\Models\PlagiarismCheckResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10 plagiarism step. ADVISORY: never touches Article.status or the
 * workflow. Async: submit() stores a PENDING row and asks Copyleaks to call our
 * webhook; handleWebhook() finishes it (idempotent - only PENDING rows move).
 */
class PlagiarismCheckService
{
    public function __construct(private CopyleaksClient $client) {}

    /** Webhook URL handed to Copyleaks: contains the shared secret and our scan id. */
    public function webhookUrl(string $scanId): ?string
    {
        $base = rtrim(trim((string) config('portal.copyleaks.webhook_base_url')), '/');
        $secret = trim((string) config('portal.copyleaks.webhook_secret'));
        if ($base === '' || $secret === '') {
            return null;
        }

        // Copyleaks substitutes {STATUS} (completed|error|creditsChecked|indexed).
        return "{$base}/api/ai/plagiarism-webhook/".rawurlencode($secret)."/{$scanId}/{STATUS}";
    }

    public function submit(Article $article, User $actor): PlagiarismCheckResult
    {
        $scanId = bin2hex(random_bytes(16));
        $result = PlagiarismCheckResult::create([
            'article_id' => $article->id,
            'requested_by_id' => $actor->id,
            'provider' => 'COPYLEAKS',
            'scan_id' => $scanId,
            'status' => 'PENDING',
            'error_message' => '',
            'matches' => [],
        ]);

        $text = trim(html_entity_decode(strip_tags((string) $article->content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        try {
            $url = $this->webhookUrl($scanId);
            if ($url === null) {
                throw new PlagiarismProviderException('The plagiarism webhook is not configured.');
            }
            if ($text === '') {
                throw new PlagiarismProviderException('The article has no text to scan.');
            }
            $this->client->submitTextScan($scanId, $text, $url);
        } catch (PlagiarismProviderException $e) {
            $result->forceFill(['status' => 'FAILED', 'error_message' => mb_substr($e->getMessage(), 0, 500)])->save();
        }

        return $result->refresh();
    }

    /**
     * Process a (secret-verified) Copyleaks status callback.
     *
     * @return string one of: ignored (unknown scan) | duplicate (already finished) | pending (non-final status) | completed | failed
     */
    public function handleWebhook(string $scanId, ?string $pathStatus, array $payload): string
    {
        $row = PlagiarismCheckResult::where('scan_id', $scanId)->first();
        if (! $row) {
            return 'ignored';
        }
        if ($row->status !== 'PENDING') {
            return 'duplicate';
        }

        $state = $this->state($pathStatus, $payload);
        if ($state === 'error') {
            return $this->finish($row->id, 'FAILED', $this->errorMessage($payload));
        }
        if ($state !== 'completed') {
            return 'pending'; // creditsChecked / indexed / unknown: not a final state
        }

        // Never trust the callback body for results: pull them with our own credentials.
        try {
            $report = $this->client->getScanResult($scanId);
        } catch (PlagiarismProviderException $e) {
            return $this->finish($row->id, 'FAILED', $e->getMessage());
        }

        $results = is_array($report['results'] ?? null) ? $report['results'] : [];
        $score = is_array($results['score'] ?? null) ? $results['score'] : [];
        $matches = [];
        foreach (['internet', 'database', 'batch'] as $key) {
            foreach (is_array($results[$key] ?? null) ? $results[$key] : [] as $source) {
                if (! is_array($source)) {
                    continue;
                }
                $matched = is_array($source['matchedWords'] ?? null) ? ($source['matchedWords']['all'] ?? null) : ($source['matchedWords'] ?? null);
                $intro = is_array($source['introduction'] ?? null) ? ($source['introduction']['similarity'] ?? null) : null;
                $matches[] = [
                    'source_url' => mb_substr((string) ($source['url'] ?? $source['title'] ?? ''), 0, 2000),
                    'similarity_percent' => $this->float($matched ?: $intro),
                    'matched_text' => mb_substr((string) ($source['title'] ?? ''), 0, 500),
                ];
            }
        }

        return $this->finish($row->id, 'COMPLETED', '', $this->float($score['aggregatedScore'] ?? null), $matches);
    }

    private function state(?string $pathStatus, array $payload): string
    {
        $raw = $pathStatus !== null && $pathStatus !== '' ? $pathStatus : ($payload['status'] ?? '');
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            return match ((int) $raw) {
                0 => 'completed', 1 => 'error', default => 'other'
            };
        }

        $s = strtolower(trim((string) (is_scalar($raw) ? $raw : '')));

        return match ($s) {
            'completed' => 'completed', 'error' => 'error', default => 'other'
        };
    }

    private function errorMessage(array $payload): string
    {
        $msg = $payload['error']['message'] ?? $payload['errorMessage'] ?? null;
        $msg = is_scalar($msg) ? trim(strip_tags((string) $msg)) : '';

        return mb_substr($msg !== '' ? $msg : 'Copyleaks reported an error for this scan.', 0, 500);
    }

    /** Idempotent terminal transition: only a still-PENDING row is changed. */
    private function finish(int $id, string $status, string $error, ?float $score = null, array $matches = []): string
    {
        return DB::transaction(function () use ($id, $status, $error, $score, $matches) {
            $row = PlagiarismCheckResult::whereKey($id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'PENDING') {
                return 'duplicate';
            }
            $row->forceFill([
                'status' => $status,
                'error_message' => $error,
                'similarity_score' => $status === 'COMPLETED' ? $score : null,
                'matches' => $status === 'COMPLETED' ? $matches : [],
                'completed_at' => now(),
            ])->save();

            return $status === 'COMPLETED' ? 'completed' : 'failed';
        }, 3);
    }

    private function float(mixed $v): ?float
    {
        return is_numeric($v) && is_finite((float) $v) ? (float) $v : null;
    }

    /** Marks PENDING checks older than the configured timeout as FAILED. Returns rows changed. */
    public function expirePending(?int $hours = null): int
    {
        $hours ??= (int) config('portal.copyleaks.pending_timeout_hours', 24);
        $count = PlagiarismCheckResult::where('status', 'PENDING')
            ->where('created_at', '<', now()->subHours(max(1, $hours)))
            ->update([
                'status' => 'FAILED',
                'error_message' => 'Timed out waiting for the plagiarism provider result.',
                'completed_at' => now(),
            ]);
        if ($count > 0) {
            Log::info("plagiarism:expire-pending marked {$count} check(s) as FAILED");
        }

        return $count;
    }
}
