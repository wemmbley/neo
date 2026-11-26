<?php

declare(strict_types=1);

use Neo\Information\Markdown\Support\UrlGuard;

/**
 * В HTML не должно остаться ни одного атрибута с опасным адресом.
 */
function assertNoActiveUrl(string $html): void
{
    expect(preg_match('~(?:href|src)\s*=\s*"?\s*(?:javascript|vbscript|data:text|data:image/svg)~i', $html))->toBe(0);
}

it('accepts relative, protocol-relative and http(s) urls', function () {
    foreach (['/a.png', 'a/b.png', './a.png', '../a.png', '//cdn.test/a.png', 'http://x.test/a', 'HTTPS://x.test/a', '#anchor'] as $url) {
        expect(UrlGuard::isSafe($url))->toBeTrue();
    }
});

it('rejects dangerous schemes including obfuscated ones', function () {
    foreach (['javascript:alert(1)', "java\tscript:alert(1)", ' JaVaScRiPt:alert(1)', 'data:text/html;base64,AAAA', 'vbscript:x', 'file:///etc/passwd', ''] as $url) {
        expect(UrlGuard::isSafe($url))->toBeFalse();
    }
});

it('allows raster data uris only', function () {
    expect(UrlGuard::isSafe('data:image/png;base64,AAAA'))->toBeTrue();
    expect(UrlGuard::isSafe('data:image/svg+xml;base64,AAAA'))->toBeFalse();
});

it('never emits a javascript link, image or media from any syntax', function () {
    foreach ([
        '[x](javascript:alert(1))',
        '![x](javascript:alert(1))',
        '[![x](/a.png)](javascript:alert(1))',
        '<javascript:alert(1)>',
        "[x](java\tscript:alert(1))",
        '![x](data:text/html;base64,AAAA)',
        '![x](data:image/svg+xml;base64,AAAA)',
        '::video{thumbnail="/t.jpg" url="javascript:alert(1)"}',
        '::video{thumbnail="javascript:alert(1)" url="https://v.test/v"}',
        "para\n\n![x](vbscript:x)",
    ] as $source) {
        assertNoActiveUrl(markdownHtml($source));
    }
});

it('drops only the link target of a linked image', function () {
    $el = markdown('[![x](/a.png)](javascript:void0)')[0];

    expect($el['type'])->toBe('image');
    expect($el['data']['link'])->toBeNull();
});

it('escapes raw html in every text position by default', function () {
    expect(markdownHtml('<script>alert(1)</script>'))->toContain('&lt;script&gt;')->not->toContain('<script>');
    expect(markdownHtml('# <img src=x onerror=alert(1)>'))->not->toContain('<img');
    expect(markdownHtml('- <b>x</b>'))->not->toContain('<b>');
    expect(markdownHtml("| <i>a</i> |\n|---|\n| <u>b</u> |"))->not->toContain('<i>')->not->toContain('<u>');
    expect(markdownHtml('> <svg onload=x>'))->not->toContain('<svg');
});

it('cannot break out of an attribute through title, alt, label or url', function () {
    foreach ([
        '![a](x.png "\\" onerror=\\"alert(1)")',
        '![a" onerror="x](x.png)',
        '[a](/x "\" onmouseover=\"y")',
        '[a](/x"onmouseover="y)',
        '::video{title="a\\" onerror=\\"x" thumbnail="/t.jpg" url="https://v.test/v"}',
    ] as $source) {
        expect(markdownHtml($source))->not->toContain(' onerror="')->not->toContain(' onmouseover="');
    }
});

it('blocks unsafe urls only while sanitize is on', function () {
    $trusted = ['modes' => ['html' => ['escape' => true, 'sanitize' => false]]];

    expect(markdownHtml('[x](javascript:void(0))', $trusted))->toContain('href="javascript:void(0)"');
});

it('allows raw html only when both escape and sanitize are disabled', function () {
    $open = ['modes' => ['html' => ['escape' => false, 'sanitize' => false]]];

    expect(markdownHtml('<b>x</b> and <i>y</i>', $open))->toContain('<b>x</b>');
    expect(types(markdown("<div>\nblock\n</div>", $open)))->toBe(['html']);
    expect(types(markdown("<div>\nblock\n</div>")))->toBe(['paragraph']);
});

it('strips raw html when escape is off but sanitize is on', function () {
    $strip = ['modes' => ['html' => ['escape' => false, 'sanitize' => true]]];

    expect(markdownHtml('a <b>x</b> <script>alert(1)</script>', $strip))->not->toContain('<b>')->not->toContain('<script>');
});

it('stays bounded on pathological input', function () {
    $start = microtime(true);

    markdown(str_repeat('*', 4000));
    markdown(str_repeat('[', 2000) . str_repeat(']', 2000));
    markdown(str_repeat('`', 3000));
    markdown(str_repeat('**a ', 1500));
    markdown(str_repeat('- ', 300) . 'x');

    expect(microtime(true) - $start < 5.0)->toBeTrue();
});
