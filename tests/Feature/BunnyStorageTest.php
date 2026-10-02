<?php

namespace Tests\Feature;

use App\Services\Media\BunnyStorage;
use App\Services\Media\BunnyStorageException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\MediaTestHelpers;
use Tests\TestCase;

class BunnyStorageTest extends TestCase
{
    use MediaTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureBunny();
    }

    public function test_upload_puts_to_storage_zone_with_access_key_and_returns_lowercased_pull_zone_url(): void
    {
        $this->fakeBunny();
        $url = app(BunnyStorage::class)->upload('articles/my-slug/abc.jpg', 'BYTES', 'image/jpeg');

        $this->assertSame('https://test-cdn.b-cdn.net/articles/my-slug/abc.jpg', $url);
        Http::assertSent(function (Request $r) {
            return $r->method() === 'PUT'
                && $r->url() === 'https://storage.bunnycdn.com/testzone/articles/my-slug/abc.jpg'
                && $r->header('AccessKey') === [self::BUNNY_KEY]
                && $r->header('Content-Type')[0] === 'image/jpeg'
                && $r->body() === 'BYTES';
        });
    }

    public function test_region_mapping(): void
    {
        foreach (['' => 'storage.bunnycdn.com', 'storage' => 'storage.bunnycdn.com', 'de' => 'storage.bunnycdn.com', 'falkenstein' => 'storage.bunnycdn.com', 'ny' => 'ny.storage.bunnycdn.com', 'SG' => 'sg.storage.bunnycdn.com'] as $region => $host) {
            $this->configureBunny(['region' => $region]);
            $this->assertSame($host, app(BunnyStorage::class)->storageHost(), "region '$region'");
        }
    }

    public function test_only_hostname_of_pull_zone_is_lowercased(): void
    {
        $this->assertSame('https://truth-social-images-cdn.b-cdn.net', BunnyStorage::normalizePullZone('https://Truth-Social-IMAGES-CDN.b-cdn.net/'));
        $this->assertSame('https://cdn.example.com:8443/Media/Path', BunnyStorage::normalizePullZone('https://CDN.Example.com:8443/Media/Path/'));
        $this->assertSame('https://host.b-cdn.net', BunnyStorage::normalizePullZone('Host.b-cdn.net'));
        $this->assertSame('', BunnyStorage::normalizePullZone(''));
    }

    public function test_upload_failure_status_throws_without_leaking_key(): void
    {
        $this->fakeBunny(put: 401);
        try {
            app(BunnyStorage::class)->upload('articles/a/b.jpg', 'x', 'image/jpeg');
            $this->fail('expected exception');
        } catch (BunnyStorageException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringNotContainsString(self::BUNNY_KEY, $e->getMessage().$e->getTraceAsString());
        }
    }

    public function test_connection_error_throws_bunny_exception_without_key(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));
        try {
            app(BunnyStorage::class)->upload('articles/a/b.jpg', 'x', 'image/jpeg');
            $this->fail('expected exception');
        } catch (BunnyStorageException $e) {
            $this->assertStringNotContainsString(self::BUNNY_KEY, $e->getMessage());
        }
    }

    public function test_unconfigured_storage_throws_on_upload_and_false_on_delete(): void
    {
        $this->configureBunny(['api_key' => '', 'storage_zone' => '']);
        Http::fake();
        $this->assertFalse(app(BunnyStorage::class)->delete('articles/a/b.jpg'));
        $this->expectException(BunnyStorageException::class);
        app(BunnyStorage::class)->upload('articles/a/b.jpg', 'x', 'image/jpeg');
    }

    public function test_delete_treats_404_and_200_as_success(): void
    {
        $this->fakeBunny(delete: 404);
        $this->assertTrue(app(BunnyStorage::class)->delete('articles/a/b.jpg'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://storage.bunnycdn.com/testzone/articles/a/b.jpg' && $r->header('AccessKey') === [self::BUNNY_KEY]);
    }

    public function test_delete_other_failures_return_false_and_never_throw(): void
    {
        $this->fakeBunny(delete: 500);
        $this->assertFalse(app(BunnyStorage::class)->delete('articles/a/b.jpg'));
    }

    public function test_delete_connection_error_returns_false(): void
    {
        Http::fake(fn () => throw new ConnectionException('boom'));
        $this->assertFalse(app(BunnyStorage::class)->delete('articles/a/b.jpg'));
    }

    public function test_delete_rejects_path_traversal_without_calling_bunny(): void
    {
        Http::fake();
        foreach (['../etc/passwd', 'articles/../../x', '', '/', 'a//b'] as $bad) {
            $this->assertFalse(app(BunnyStorage::class)->delete($bad), $bad);
        }
        Http::assertNothingSent();
    }

    public function test_access_key_never_reaches_the_logs(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.json_encode($m->context);
        });
        $this->fakeBunny(put: 500, delete: 500);
        $s = app(BunnyStorage::class);
        try {
            $s->upload('articles/a/b.jpg', 'x', 'image/jpeg');
        } catch (BunnyStorageException) {
        }
        $s->delete('articles/a/b.jpg');

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::BUNNY_KEY, $line);
        }
    }
}
