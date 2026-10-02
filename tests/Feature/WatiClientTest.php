<?php

namespace Tests\Feature;

use App\Services\Wati\PhoneNumber;
use App\Services\Wati\WatiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatiClientTest extends TestCase
{
    /** @var string[] */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('portal.wati.endpoint', ' https://wati.test/ ');
        config()->set('portal.wati.token', ' Bearer super-secret-wati-token ');
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e->message.' '.json_encode($e->context));
    }

    public function test_sends_documented_request_shape_with_normalized_number_and_single_bearer(): void
    {
        Http::fake(['wati.test/*' => Http::response(['result' => true], 200)]);
        $ok = (new WatiClient)->sendTemplate('9876543210', 'otp_verification', [['name' => '1', 'value' => '123456']]);

        $this->assertTrue($ok);
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && str_starts_with($r->url(), 'https://wati.test/api/v1/sendTemplateMessage?')
                && str_contains($r->url(), 'whatsappNumber=919876543210')
                && $r->hasHeader('Authorization', 'Bearer super-secret-wati-token')
                && $r->data() === ['template_name' => 'otp_verification', 'broadcast_name' => 'otp_verification', 'parameters' => [['name' => '1', 'value' => '123456']]];
        });
    }

    public function test_accepts_a_name_value_map_of_params(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        (new WatiClient)->sendTemplate('919876543210', 't', ['plan_name' => 'Gold']);
        Http::assertSent(fn (Request $r) => $r->data()['parameters'] === [['name' => 'plan_name', 'value' => 'Gold']]);
    }

    public function test_never_throws_and_returns_false_on_failures(): void
    {
        Http::fake(['*' => Http::response(['result' => false, 'info' => 'Check your template'], 400)]);
        $this->assertFalse((new WatiClient)->sendTemplate('9876543210', 't', []));

        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['result' => false, 'validWhatsAppNumber' => false], 200)]);
        $this->assertFalse((new WatiClient)->sendTemplate('9876543210', 't', []));

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('cURL error 6 for https://wati.test/api/v1/sendTemplateMessage?whatsappNumber=919876543210'));
        $this->assertFalse((new WatiClient)->sendTemplate('9876543210', 't', []));
    }

    public function test_unconfigured_or_blank_inputs_skip_without_http(): void
    {
        Http::fake();
        config()->set('portal.wati.endpoint', '');
        $this->assertFalse((new WatiClient)->sendTemplate('9876543210', 't'));
        config()->set('portal.wati.endpoint', 'https://wati.test');
        config()->set('portal.wati.token', '');
        $this->assertFalse((new WatiClient)->sendTemplate('9876543210', 't'));
        config()->set('portal.wati.token', 'x');
        $this->assertFalse((new WatiClient)->sendTemplate('9876543210', ''));
        $this->assertFalse((new WatiClient)->sendTemplate('', 't'));
        Http::assertNothingSent();
    }

    public function test_logs_never_contain_token_otp_or_full_phone(): void
    {
        $paths = [
            fn () => Http::fake(['*' => Http::response(['result' => false], 400)]),
            fn () => Http::fake(fn () => throw new ConnectionException('failed for https://wati.test/x?whatsappNumber=919876543210 token super-secret-wati-token')),
        ];
        foreach ($paths as $fake) {
            Http::swap(new Factory);
            $fake();
            (new WatiClient)->sendTemplate('919876543210', 'otp_verification', [['name' => '1', 'value' => '654321']]);
        }
        $all = implode("\n", $this->logged);
        $this->assertNotSame('', trim($all));
        foreach (['super-secret-wati-token', '654321', '919876543210', '9876543210'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_phone_normalization_and_masking(): void
    {
        $this->assertSame('919876543210', PhoneNumber::normalize('9876543210'));
        $this->assertSame('919876543210', PhoneNumber::normalize('09876543210'));
        $this->assertSame('919876543210', PhoneNumber::normalize('+91 98765-43210'));
        $this->assertSame('', PhoneNumber::normalize(null));
        $this->assertTrue(PhoneNumber::looksValid('919876543210'));
        $this->assertFalse(PhoneNumber::looksValid('12345'));
        $this->assertSame('*********210', PhoneNumber::mask('919876543210'));
    }
}
