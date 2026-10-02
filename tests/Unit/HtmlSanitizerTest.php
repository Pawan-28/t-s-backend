<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public static function attacks(): array
    {
        return [
            'script tag' => ['<p>a</p><script>alert(1)</script>', ['<script', 'alert']],
            'script upper/mixed' => ['<ScRiPt SRC=//evil.test/x.js></ScRiPt>ok', ['script', 'evil']],
            'onerror img' => ['<img src=x onerror=alert(1)>', ['onerror', 'alert']],
            'onclick' => ['<p onclick="x()">t</p>', ['onclick']],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>', ['javascript']],
            'entity-obfuscated javascript' => ['<a href="jav&#x09;ascript:alert(1)">x</a>', ['ascript:alert']],
            'data uri' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', ['data:']],
            'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>', ['vbscript']],
            'iframe' => ['<iframe src="https://evil.test"></iframe>', ['iframe', 'evil']],
            'object/embed' => ['<object data="x"></object><embed src="y">', ['object', 'embed']],
            'style attr' => ['<p style="background:url(javascript:x)">t</p>', ['style', 'javascript']],
            'style tag' => ['<style>body{display:none}</style>t', ['style', 'display']],
            'svg onload' => ['<svg onload=alert(1)><circle/></svg>', ['svg', 'onload', 'alert']],
            'form' => ['<form action="https://evil.test"><input name=a></form>', ['form', 'input', 'evil']],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">', ['meta', 'evil']],
            'base' => ['<base href="https://evil.test/">', ['base', 'evil']],
            'nested broken' => ['<<script>alert(1)//<</script>', ['<script', 'alert(1)//']],
            'img srcset/poster' => ['<img src="https://ok.test/a.png" srcset="javascript:x" onload="y()">', ['srcset', 'onload', 'javascript']],
            'h1 not allowed' => ['<h1>big</h1>', ['<h1']],
            'id/class removed' => ['<p id="a" class="b">t</p>', ['id=', 'class=']],
        ];
    }

    #[DataProvider('attacks')]
    public function test_dangerous_markup_is_removed(string $dirty, array $forbidden): void
    {
        $clean = strtolower(HtmlSanitizer::clean($dirty));
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(strtolower($needle), $clean, "'{$needle}' survived in: {$clean}");
        }
    }

    public function test_allow_listed_markup_is_kept(): void
    {
        $html = '<h2>T</h2><h3>S</h3><h4>SS</h4><p>a <strong>b</strong> <b>b</b> <em>e</em> <i>i</i> <u>u</u><br></p><ul><li>1</li></ul><ol><li>2</li></ol><blockquote>q</blockquote>'
            .'<a href="https://ok.test/p?a=1&amp;b=2" title="t">l</a><a href="mailto:x@y.test">m</a><img src="http://ok.test/i.png" alt="A" title="T">';
        $clean = HtmlSanitizer::clean($html);
        foreach (['<h2>T</h2>', '<h3>S</h3>', '<h4>SS</h4>', '<strong>b</strong>', '<b>b</b>', '<em>e</em>', '<i>i</i>', '<u>u</u>', '<br>', '<ul><li>1</li></ul>', '<ol><li>2</li></ol>', '<blockquote>q</blockquote>', 'href="https://ok.test/p?a=1&amp;b=2"', 'href="mailto:x@y.test"', 'src="http://ok.test/i.png"', 'alt="A"'] as $keep) {
            $this->assertStringContainsString($keep, $clean);
        }
    }

    public function test_blank_and_null_input(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(null));
        $this->assertSame('', HtmlSanitizer::clean('<script>x</script>'));
        $this->assertSame('plain text', HtmlSanitizer::clean('plain text'));
    }

    public function test_target_blank_links_get_rel_noopener(): void
    {
        $clean = HtmlSanitizer::clean('<a href="https://ok.test" target="_blank">x</a>');
        $this->assertStringContainsString('target="_blank"', $clean);
        $this->assertStringContainsString('noopener', $clean);
        $this->assertStringNotContainsString('target="_top"', HtmlSanitizer::clean('<a href="https://ok.test" target="_top">x</a>'));
    }
}
