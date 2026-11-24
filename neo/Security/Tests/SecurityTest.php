<?php

declare(strict_types=1);

use Neo\Security\Proxy\IpMatcher;
use Neo\Security\Xss\Escaper;

test('Escaper::html escapes markup and quotes in every context that matters', function () {
    expect(Escaper::html('<script>alert("x")</script>'))->toBe('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;')
        ->and(Escaper::html("it's"))->toBe('it&#039;s')
        ->and(Escaper::html(null))->toBe('')
        ->and(Escaper::html(5))->toBe('5')
        ->and(Escaper::attr('" onmouseover="alert(1)'))->toBe('&quot; onmouseover=&quot;alert(1)')
        ->and(fn () => Escaper::html(['array']))->toThrow(InvalidArgumentException::class);
});

test('Escaper::js produces a literal that cannot close a script tag', function () {
    $literal = Escaper::js(['x' => '</script><img src=x onerror=alert(1)>', 'q' => "'\"&"]);

    expect($literal)->not->toContain('<')
        ->and($literal)->not->toContain('>')
        ->and($literal)->not->toContain('&')
        ->and(json_decode($literal, true)['x'])->toBe('</script><img src=x onerror=alert(1)>');
});

test('Escaper::url encodes components and safeUrl blocks script schemes', function () {
    expect(Escaper::url('a b&c=d/é'))->toBe('a%20b%26c%3Dd%2F%C3%A9')
        ->and(Escaper::safeUrl('javascript:alert(1)'))->toBe('#')
        ->and(Escaper::safeUrl("java\tscript:alert(1)"))->toBe('#')
        ->and(Escaper::safeUrl(' JaVaScRiPt:alert(1)'))->toBe('#')
        ->and(Escaper::safeUrl('data:text/html;base64,AAAA'))->toBe('#')
        ->and(Escaper::safeUrl('https://example.com/a'))->toBe('https://example.com/a')
        ->and(Escaper::safeUrl('/relative/path'))->toBe('/relative/path')
        ->and(Escaper::safeUrl('mailto:a@b.c'))->toBe('mailto:a@b.c');
});

test('IpMatcher handles single addresses, CIDR ranges, IPv6 and wildcard', function () {
    expect(IpMatcher::matches('10.1.2.3', ['10.0.0.0/8']))->toBeTrue()
        ->and(IpMatcher::matches('11.1.2.3', ['10.0.0.0/8']))->toBeFalse()
        ->and(IpMatcher::matches('192.168.1.5', ['192.168.1.5']))->toBeTrue()
        ->and(IpMatcher::matches('192.168.1.6', ['192.168.1.5']))->toBeFalse()
        ->and(IpMatcher::matches('192.168.1.130', ['192.168.1.128/25']))->toBeTrue()
        ->and(IpMatcher::matches('192.168.1.100', ['192.168.1.128/25']))->toBeFalse()
        ->and(IpMatcher::matches('fd12::1', ['fd00::/8']))->toBeTrue()
        ->and(IpMatcher::matches('fe80::1', ['fd00::/8']))->toBeFalse()
        ->and(IpMatcher::matches('1.2.3.4', ['*']))->toBeTrue()
        ->and(IpMatcher::matches('not-an-ip', ['*']))->toBeFalse()
        ->and(IpMatcher::matches('1.2.3.4', ['10.0.0.0/99']))->toBeFalse()
        ->and(IpMatcher::matches('::1', ['10.0.0.0/8']))->toBeFalse();
});
