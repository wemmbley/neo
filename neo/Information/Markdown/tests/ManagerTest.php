<?php

declare(strict_types=1);

use Neo\Collection\Collection;
use Neo\Information\Markdown\Exceptions\MarkdownException;
use Neo\Information\Markdown\Facades\Markdown;
use Neo\Information\Markdown\Syntax\HeaderOneSyntax\HeaderOneSyntax;
use Neo\View\View;

function writeMarkdown(string $dir, string $content, string $name = 'doc.md'): string
{
    $path = $dir . '/' . $name;
    file_put_contents($path, $content);

    return $path;
}

it('returns an array, a collection or a view depending on format', function () {
    $path = writeMarkdown(markdownTempDir(), "# Hello\n\ntext");

    $array = markdownManager(['format' => 'array'])->parse($path);
    $collection = markdownManager(['format' => 'collection'])->parse($path);
    $view = markdownManager(['format' => 'view'])->parse($path);

    expect($array)->toHaveCount(2);
    expect($collection)->toBeInstanceOf(Collection::class);
    expect($collection->toArray())->toHaveCount(2);
    expect($view)->toBeInstanceOf(View::class);
    expect(squish($view->render()))->toBe('<h1 id="hello">Hello</h1><p>text</p>');
});

it('works through the facade, including registerSyntax', function () {
    markdownManager(['format' => 'array']);

    expect(Markdown::parseString('# Facade')[0]['data']['id'])->toBe('facade');

    Markdown::registerSyntax(HeaderOneSyntax::class, null, __DIR__ . '/Fixtures/h1-custom.blade.php');

    expect(Markdown::parseString('# Facade')[0]['html'])->toContain('class="title"');
});

it('throws for a missing file', function () {
    expect(fn () => markdownManager()->parse('/nope/missing.md'))->toThrow(MarkdownException::class);
});

it('rejects an invalid format', function () {
    expect(fn () => markdownConfig(['format' => 'json']))->toThrow(Throwable::class);
});

it('requires a cache path only when the cache is enabled', function () {
    expect(fn () => markdownConfig(['cache' => ['enabled' => true, 'path' => '']]))->toThrow(Throwable::class);
    expect(markdownConfig(['cache' => ['enabled' => false]])->cache->enabled)->toBeFalse();
});

it('caches the parsed document and invalidates it when the file changes', function () {
    $dir = markdownTempDir();
    $cache = $dir . '/cache';
    $path = writeMarkdown($dir, '# One');

    $manager = markdownManager(['cache' => ['enabled' => true, 'ttl' => 3600, 'path' => $cache]]);

    expect($manager->parse($path)[0]['data']['text'])->toBe('One');

    $files = glob($cache . '/*.json');
    expect($files)->toHaveCount(1);

    file_put_contents($files[0], json_encode([['type' => 'paragraph', 'line' => 1, 'data' => [], 'html' => '<p>cached</p>']]));
    expect($manager->parse($path)[0]['html'])->toBe('<p>cached</p>');

    file_put_contents($path, '# Two, longer');
    touch($path, time() + 5);
    expect($manager->parse($path)[0]['data']['text'])->toBe('Two, longer');
    expect(glob($cache . '/*.json'))->toHaveCount(2);

    expect($manager->flush())->toBe(2);
});

it('ignores expired and corrupted cache entries', function () {
    $dir = markdownTempDir();
    $cache = $dir . '/cache';
    $path = writeMarkdown($dir, '# Fresh');

    $manager = markdownManager(['cache' => ['enabled' => true, 'ttl' => 10, 'path' => $cache]]);
    $manager->parse($path);
    $file = glob($cache . '/*.json')[0];

    file_put_contents($file, json_encode([['type' => 'paragraph', 'line' => 1, 'data' => [], 'html' => 'stale']]));
    touch($file, time() - 100);
    expect($manager->parse($path)[0]['type'])->toBe('header-one');

    file_put_contents($file, '{broken');
    expect($manager->parse($path)[0]['type'])->toBe('header-one');
});

it('keeps cached output identical to fresh output', function () {
    $dir = markdownTempDir();
    $path = writeMarkdown($dir, "# T\n\n**b** [l](/x)\n\n| a |\n|---|\n| 1 |\n\nhttps://youtu.be/dQw4w9WgXcQ\n");

    $manager = markdownManager(['cache' => ['enabled' => true, 'ttl' => 3600, 'path' => $dir . '/cache']]);

    expect($manager->parse($path))->toBe($manager->parse($path));
    expect($manager->parse($path))->toBe(markdownManager()->parse($path));
});
