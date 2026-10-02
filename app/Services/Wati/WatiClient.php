<?php

namespace App\Services\Wati;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp sender (WATI "sendTemplateMessage"). Never throws. Logs never contain
 * the token, the template parameters (which can hold an OTP) or a full phone
 * number; connection exceptions are logged by class only because their message
 * embeds the request URL (which carries the phone number).
 */
class WatiClient
{
    /**
     * @param  array<int, array{name:string,value:string}>|array<string,string>  $params  WATI "parameters": a list of {name,value} or a name=>value map
     */
    public function sendTemplate(string $phone, string $template, array $params = []): bool
    {
        $masked = PhoneNumber::mask($phone);
        try {
            $template = trim($template);
            if ($template === '') {
                Log::warning('WATI send skipped: no template name configured.');

                return false;
            }
            $endpoint = rtrim(trim((string) config('portal.wati.endpoint')), '/');
            $token = trim((string) config('portal.wati.token'));
            if (stripos($token, 'bearer ') === 0) {
                $token = trim(substr($token, 7));
            }
            if ($endpoint === '' || $token === '') {
                Log::warning('WATI send skipped: WATI is not configured.', ['template' => $template]);

                return false;
            }
            $number = PhoneNumber::normalize($phone);
            if ($number === '') {
                Log::warning('WATI send skipped: empty phone number.', ['template' => $template]);

                return false;
            }

            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('portal.wati.timeout', 30))
                ->withQueryParameters(['whatsappNumber' => $number])
                ->post($endpoint.'/api/v1/sendTemplateMessage', [
                    'template_name' => $template,
                    'broadcast_name' => $template,
                    'parameters' => self::normalizeParams($params),
                ]);

            if (! in_array($response->status(), [200, 201], true)) {
                Log::warning('WATI send failed.', ['template' => $template, 'phone' => $masked, 'status' => $response->status()]);

                return false;
            }
            // WATI answers 200 with {"result": false} for soft failures (bad template/number).
            $json = $response->json();
            if (is_array($json) && array_key_exists('result', $json) && $json['result'] === false) {
                Log::warning('WATI rejected the message.', ['template' => $template, 'phone' => $masked]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('WATI send raised an error.', ['phone' => $masked, 'error' => $e::class]);

            return false;
        }
    }

    /** @return array<int, array{name:string,value:string}> */
    private static function normalizeParams(array $params): array
    {
        $out = [];
        foreach ($params as $key => $item) {
            if (is_array($item) && isset($item['name'])) {
                $out[] = ['name' => (string) $item['name'], 'value' => (string) ($item['value'] ?? '')];
            } elseif (! is_array($item)) {
                $out[] = ['name' => (string) $key, 'value' => (string) $item];
            }
        }

        return $out;
    }
}
