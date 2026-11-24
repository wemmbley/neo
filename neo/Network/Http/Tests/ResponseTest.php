<?php

declare(strict_types=1);

use Neo\Network\Cookie\CookieValue;
use Neo\Network\Http\EventStreamResponse;
use Neo\Network\Http\FileDownloadResponse;
use Neo\Network\Http\FileResponse;
use Neo\Network\Http\JsonResponse;
use Neo\Network\Http\RedirectResponse;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\StreamedEvent;
use Neo\Network\Http\StreamResponse;

test('Response sets content, status and headers', function () {
    $response = new Response('hello', 201, ['X-Test' => 'a']);

    expect($response->getContent())->toBe('hello')
        ->and($response->status())->toBe(201)
        ->and($response->statusText())->toBe('Created')
        ->and($response->headers()->get('x-test'))->toBe('a')
        ->and($response->isSuccessful())->toBeTrue();
});

test('Response refuses header values with line breaks (response splitting)', function () {
    $response = new Response();

    expect(fn () => $response->header('X-A', "ok\r\nSet-Cookie: pwned=1"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $response->header("X\nBad", 'v'))->toThrow(InvalidArgumentException::class);
});

test('Response rejects invalid status codes', function () {
    expect(fn () => new Response('', 99))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Response('', 600))->toThrow(InvalidArgumentException::class);
});

test('Response converts arrays to JSON', function () {
    $response = new Response(['a' => 1]);

    expect($response->getContent())->toBe('{"a":1}')
        ->and($response->headers()->get('Content-Type'))->toBe('application/json');
});

test('Response prepare sets Content-Type, Content-Length and strips body for HEAD and 204', function () {
    $get = (new Response('héllo'))->prepare(Request::create('/x', 'GET'));
    $head = (new Response('héllo'))->prepare(Request::create('/x', 'HEAD'));
    $none = (new Response('ignored', 204))->prepare(Request::create('/x', 'GET'));

    expect($get->headers()->get('Content-Type'))->toBe('text/html; charset=UTF-8')
        ->and($get->headers()->get('Content-Length'))->toBe('6')
        ->and($head->getContent())->toBe('')
        ->and($head->headers()->get('Content-Length'))->toBe('6')
        ->and($head->isHeadResponse())->toBeTrue()
        ->and($none->getContent())->toBe('')
        ->and($none->headers()->has('Content-Length'))->toBeFalse();
});

test('JsonResponse escapes HTML-significant characters', function () {
    $response = new JsonResponse(['x' => '</script><b>&"\'']);

    expect($response->getContent())->not->toContain('<')
        ->and($response->getContent())->not->toContain('>')
        ->and($response->getData(true)['x'])->toBe('</script><b>&"\'')
        ->and($response->headers()->get('Content-Type'))->toBe('application/json');
});

test('JsonResponse rejects values that cannot be encoded', function () {
    expect(fn () => new JsonResponse(['bad' => "\xB1\x31"]))->toThrow(InvalidArgumentException::class);
});

test('RedirectResponse sets Location and refuses dangerous schemes', function () {
    $response = new RedirectResponse('/dashboard?x=1');

    expect($response->status())->toBe(302)
        ->and($response->headers()->get('Location'))->toBe('/dashboard?x=1')
        ->and($response->getContent())->toContain('/dashboard?x=1')
        ->and(fn () => new RedirectResponse('javascript:alert(1)'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RedirectResponse("java\tscript:alert(1)"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RedirectResponse('data:text/html,x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RedirectResponse("/ok\r\nSet-Cookie: a=b"))->toThrow(InvalidArgumentException::class)
        ->and((new RedirectResponse('https://example.com/a'))->getTargetUrl())->toBe('https://example.com/a');
});

test('RedirectResponse HTML body escapes the target', function () {
    $response = new RedirectResponse('/x?a="><script>alert(1)</script>');

    expect($response->getContent())->not->toContain('<script>');
});

test('Response cookies are keyed by name, path and domain and replace each other', function () {
    $response = new Response();
    $response->cookie('a', '1', 10)->cookie('a', '2', 10)->cookie('a', '3', 10, '/other');

    expect(count($response->cookies()))->toBe(2);

    $response->withoutCookie('a');

    expect(count($response->cookies()))->toBe(2)
        ->and($response->cookies()['a|/|']->value)->toBe('');
});

test('CookieValue serializes a safe Set-Cookie header', function () {
    $cookie = new CookieValue('token', 'a b;c=d', time() + 60, '/app', 'example.com', true, true, 'strict');
    $header = $cookie->toHeader();

    expect($header)->toStartWith('token=a%20b%3Bc%3Dd; ')
        ->and($header)->toContain('Path=/app')
        ->and($header)->toContain('Domain=example.com')
        ->and($header)->toContain('Secure')
        ->and($header)->toContain('HttpOnly')
        ->and($header)->toContain('SameSite=Strict')
        ->and($header)->toMatch('/Max-Age=\d+/');
});

test('CookieValue forces Secure for SameSite=None and drops unusable domains', function () {
    $none = (new CookieValue('a', '1', null, '/', null, false, true, 'none'))->toHeader();
    $localhost = (new CookieValue('a', '1', null, '/', 'localhost'))->toHeader();
    $url = (new CookieValue('a', '1', null, '/', 'https://Site.Example.com'))->toHeader();
    $ip = (new CookieValue('a', '1', null, '/', '127.0.0.1'))->toHeader();

    expect($none)->toContain('Secure')
        ->and($localhost)->not->toContain('Domain=')
        ->and($ip)->not->toContain('Domain=')
        ->and($url)->toContain('Domain=site.example.com');
});

test('CookieValue validates the name and prefixes', function () {
    expect(fn () => new CookieValue("a\r\nb", 'v'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CookieValue('a b', 'v'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CookieValue('a=b', 'v'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CookieValue('__Host-id', 'v', null, '/', null, false))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CookieValue('__Secure-id', 'v', null, '/', null, false))->toThrow(InvalidArgumentException::class)
        ->and((new CookieValue('__Host-id', 'v', null, '/', null, true))->name)->toBe('__Host-id');
});

test('FileResponse serves a file and forces download for active content types', function () {
    $dir = sys_get_temp_dir() . '/neo_http_' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/a.txt', 'hello');
    file_put_contents($dir . '/page.html', '<script>alert(1)</script>');

    $text = new FileResponse($dir . '/a.txt');
    $html = new FileResponse($dir . '/page.html');
    $download = new FileDownloadResponse($dir . '/a.txt', "na\"me\r\n.txt");

    $out = '';
    $text->streamTo(function (string $chunk) use (&$out): void {
        $out .= $chunk;
    });

    expect($out)->toBe('hello')
        ->and($text->headers()->get('Content-Length'))->toBe('5')
        ->and($text->headers()->get('Content-Disposition'))->toStartWith('inline;')
        ->and($html->headers()->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($download->headers()->get('Content-Disposition'))->not->toContain("\n")
        ->and($download->headers()->get('Content-Disposition'))->not->toContain('"na"')
        ->and(fn () => new FileResponse($dir . '/missing.txt'))->toThrow(Neo\Network\Http\Exceptions\NotFoundHttpException::class);

    unlink($dir . '/a.txt');
    unlink($dir . '/page.html');
    rmdir($dir);
});

test('StreamResponse passes chunks to the writer', function () {
    $response = new StreamResponse(function (callable $write): void {
        $write('a');
        $write('b');
    });

    $out = [];
    $response->streamTo(function (string $chunk) use (&$out): void {
        $out[] = $chunk;
    });

    expect($out)->toBe(['a', 'b'])
        ->and($response->isStreamed())->toBeTrue()
        ->and($response->prepare(Request::create('/x'))->headers()->has('Content-Length'))->toBeFalse();
});

test('EventStreamResponse formats server-sent events and cannot be split by line breaks', function () {
    $response = new EventStreamResponse(function () {
        yield 'hello';
        yield ['a' => 1];
        yield new StreamedEvent("line1\nline2", "up\ndate", '7');
    });

    $out = '';
    $response->streamTo(function (string $chunk) use (&$out): void {
        $out .= $chunk;
    });

    expect($response->headers()->get('Content-Type'))->toBe('text/event-stream')
        ->and($out)->toContain("data: hello\n\n")
        ->and($out)->toContain("data: {\"a\":1}\n\n")
        ->and($out)->toContain("event: up date\n")
        ->and($out)->toContain("data: line1\ndata: line2\n\n")
        ->and($out)->toContain("event: update\ndata: </stream>\n\n");
});
