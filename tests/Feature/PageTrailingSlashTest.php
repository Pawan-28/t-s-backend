<?php

namespace Tests\Feature;

use App\Support\Page;
use Illuminate\Http\Request;
use Tests\TestCase;

/** DRF parity: `next`/`previous` keep the trailing slash of the requested URL (the HTTP test client trims it, so build the Request by hand). */
class PageTrailingSlashTest extends TestCase
{
    public function test_next_and_previous_keep_the_requested_trailing_slash(): void
    {
        $rows = range(1, 45);

        $p2 = Page::make($rows, Request::create('/api/articles/?page=2&ordering=x', 'GET'));
        $this->assertStringEndsWith('/api/articles/?ordering=x&page=3', $p2['next']);
        $this->assertStringEndsWith('/api/articles/?ordering=x', $p2['previous']);

        $bare = Page::make($rows, Request::create('/api/articles?page=2', 'GET'));
        $this->assertStringEndsWith('/api/articles?page=3', $bare['next']);
        $this->assertStringEndsWith('/api/articles', $bare['previous']);
    }
}
