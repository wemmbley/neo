<?php

declare(strict_types=1);

it('parses a standalone image as a block element with alt and title', function () {
    $el = markdown('![Alt text](/img/a.png "The title")')[0];

    expect($el['type'])->toBe('image');
    expect($el['data'])->toBe(['src' => '/img/a.png', 'alt' => 'Alt text', 'title' => 'The title', 'link' => null, 'block' => true]);
    expect($el['html'])->toBe('<p><img src="/img/a.png" alt="Alt text" title="The title" loading="lazy"></p>');
});

it('parses a linked standalone image', function () {
    $el = markdown('[![Logo](/logo.png)](https://example.com)')[0];

    expect($el['type'])->toBe('image');
    expect($el['data']['link'])->toBe('https://example.com');
    expect($el['html'])->toContain('<a href="https://example.com"><img');
});

it('splits consecutive image lines into separate elements', function () {
    expect(types(markdown("![a](a.png)\n![b](b.png)")))->toBe(['image', 'image']);
});

it('keeps an image in the middle of a paragraph inline', function () {
    $elements = markdown('Look: ![x](a.png) inline');

    expect(types($elements))->toBe(['paragraph']);
    expect($elements[0]['html'])->toContain('<img src="a.png"');
});

it('detects video and audio files by extension', function () {
    $video = markdown('![Demo](/media/demo.mp4)')[0];
    $audio = markdown('![Track](https://cdn.test/a.mp3?x=1)')[0];

    expect($video['type'])->toBe('video-file');
    expect($video['html'])->toBe('<video controls preload="metadata" src="/media/demo.mp4" title="Demo"></video>');
    expect($audio['type'])->toBe('audio');
    expect($audio['data']['src'])->toBe('https://cdn.test/a.mp3?x=1');
});

it('turns a bare youtube url in its own paragraph into an embed', function () {
    $elements = markdown("Intro\n\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n\nOutro");

    expect(types($elements))->toBe(['paragraph', 'embed', 'paragraph']);
    expect($elements[1]['data']['provider'])->toBe('youtube');
    expect($elements[1]['data']['id'])->toBe('dQw4w9WgXcQ');
    expect($elements[1]['data']['thumbnail'])->toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
    expect($elements[1]['html'])->toContain('<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"');
});

it('recognises youtu.be, shorts and embed youtube urls and vimeo', function () {
    foreach (['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube.com/embed/dQw4w9WgXcQ'] as $url) {
        $el = markdown("![]($url)")[0];

        expect($el['type'])->toBe('embed');
        expect($el['data']['id'])->toBe('dQw4w9WgXcQ');
    }

    $vimeo = markdown('![Talk](https://vimeo.com/123456789)')[0];

    expect($vimeo['data']['src'])->toBe('https://player.vimeo.com/video/123456789');
    expect($vimeo['data']['title'])->toBe('Talk');
});

it('does not embed a url that is part of a paragraph', function () {
    expect(types(markdown("See\nhttps://youtu.be/dQw4w9WgXcQ\nnow")))->toBe(['paragraph']);
});

it('embeds a bare url right after a heading but not after paragraph text', function () {
    expect(types(markdown("# T\nhttps://youtu.be/dQw4w9WgXcQ\n")))->toBe(['header-one', 'embed']);
});

it('leaves a bare non-media url as a regular link', function () {
    $elements = markdown('https://example.com/page');

    expect($elements[0]['type'])->toBe('paragraph');
    expect($elements[0]['html'])->toContain('<a href=')->not->toContain('<iframe');
});

it('treats a bare image url in its own paragraph as an image', function () {
    expect(types(markdown('https://cdn.test/photo.jpg')))->toBe(['image']);
});

it('rejects youtube urls with a malformed video id', function () {
    expect(markdown('![x](https://www.youtube.com/watch?v=short)')[0]['type'])->toBe('image');
});
