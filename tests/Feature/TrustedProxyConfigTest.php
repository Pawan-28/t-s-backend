<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** TRUSTED_PROXIES is read through config/trustedproxy.php so it survives `php artisan config:cache`. */
class TrustedProxyConfigTest extends TestCase
{
    private function ip(): string
    {
        Route::get('/_t/ip', fn (Request $r) => response()->json(['ip' => $r->ip()]));

        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])
            ->getJson('/_t/ip')->json('ip');
    }

    public function test_default_config_parses_the_env_list(): void
    {
        $this->assertSame(['127.0.0.1'], config('trustedproxy.proxies'));
    }

    public function test_forwarded_for_is_honoured_only_from_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
        $this->assertSame('203.0.113.9', $this->ip());
    }

    public function test_forwarded_for_is_ignored_from_an_untrusted_peer(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);
        $this->assertSame('10.0.0.5', $this->ip());
    }
}
