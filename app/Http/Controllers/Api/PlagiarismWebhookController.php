<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Plagiarism\PlagiarismCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/ai/plagiarism-webhook/{token}/{scan_id}/{status?}
 *
 * Copyleaks documents no payload signature, so authenticity rests on (1) a
 * shared secret embedded in the URL we gave Copyleaks (compared with
 * hash_equals against portal.copyleaks.webhook_secret), (2) the scan id having
 * to match one of OUR still-PENDING rows, and (3) results never being read from
 * the callback body but pulled from Copyleaks with our own credentials.
 * Unknown / finished scans are acknowledged (200) so Copyleaks stops retrying.
 */
class PlagiarismWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, string $scanId, PlagiarismCheckService $service, ?string $status = null): JsonResponse
    {
        $secret = trim((string) config('portal.copyleaks.webhook_secret'));
        if ($secret === '' || ! hash_equals($secret, $token)) {
            Log::warning('Plagiarism webhook rejected: invalid token.');

            return response()->json(['detail' => 'Forbidden.'], 403);
        }

        $payload = $request->json()->all();
        $service->handleWebhook($scanId, $status, is_array($payload) ? $payload : []);

        return response()->json((object) [], 200);
    }
}
