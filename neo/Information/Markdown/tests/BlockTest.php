<?php

declare(strict_types=1);

it('parses all six heading levels into their own syntaxes', function () {
    $elements = markdown("# a\n\n## b\n\n### c\n\n#### d\n\n##### e\n\n###### f");

    expect(types($elements))->toBe(['header-one', 'header-two', 'header-three', 'header-four', 'header-five', 'header-six']);
    expect($elements[2]['html'])->toBe('<h3 id="c">c</h3>');
    expect($elements[5]['data']['level'])->toBe(6);
});

it('exposes heading text, id and inline html', function () {
    $el = markdown('# Hello *world*')[0];

    expect($el['html'])->toBe('<h1 id="hello-world">Hello <em>world</em></h1>');
    expect($el['data']['text'])->toBe('Hello world');
    expect($el['data']['id'])->toBe('hello-world');
});

it('supports optional closing hashes and makes ids unique', function () {
    $elements = markdown("## Intro ##\n\n## Intro\n\n## Intro");

    expect(array_column(array_column($elements, 'data'), 'id'))->toBe(['intro', 'intro-2', 'intro-3']);
    expect($elements[0]['data']['text'])->toBe('Intro');
});

it('transliterates cyrillic headings', function () {
    expect(markdown('# Привет, мир! Їжак')[0]['data']['id'])->toBe('privet-mir-yizhak');
});

it('keeps numbered titles as headings instead of lists', function () {
    $el = markdown('# 1. Intro')[0];

    expect($el['type'])->toBe('header-one');
    expect($el['html'])->toBe('<h1 id="1-intro">1. Intro</h1>');
});

it('does not treat hashtags or seven hashes as headings', function () {
    expect(markdown('#hashtag')[0]['type'])->toBe('paragraph');
    expect(markdown('####### seven')[0]['type'])->toBe('paragraph');
});

it('groups loose lines into paragraphs and keeps document order', function () {
    $elements = markdown("# T\n\nline one\nline two\n\n- a\n- b\n\n## N");

    expect(types($elements))->toBe(['header-one', 'paragraph', 'bullet-list', 'header-two']);
    expect($elements[1]['html'])->toBe("<p>line one\nline two</p>");
    expect(array_column($elements, 'line'))->toBe([1, 3, 6, 9]);
});

it('does not interpret anything inside fenced code', function () {
    $elements = markdown("```php\n# not a heading\n![x](a.png)\n::video{}\n<b>x</b>\n```");

    expect($elements)->toHaveCount(1);
    expect($elements[0]['type'])->toBe('code-block');
    expect($elements[0]['data']['lang'])->toBe('php');
    expect($elements[0]['html'])->toContain('class="language-php"');
    expect($elements[0]['html'])->toContain('&lt;b&gt;x&lt;/b&gt;');
    expect($elements[0]['html'])->toContain('# not a heading');
});

it('handles tilde fences, a longer closing fence and an unclosed fence', function () {
    expect(markdown("~~~\ncode\n~~~")[0]['data']['code'])->toBe('code');
    expect(markdown("````\n```\ninner\n```\n````")[0]['data']['code'])->toBe("```\ninner\n```");

    $elements = markdown("text\n\n```\n# inside");
    expect(types($elements))->toBe(['paragraph', 'code-block']);
});

it('drops unsafe characters from the code language', function () {
    expect(markdown("```p\"hp<\ncode\n```")[0]['data']['lang'])->toBe('php');
});

it('renders a divider', function () {
    foreach (['---', '***', '___', '- - -'] as $line) {
        expect(types(markdown($line)))->toBe(['divider']);
    }

    expect(markdownHtml("a\n\n---\n\nb"))->toBe('<p>a</p><hr><p>b</p>');
});

it('renders nested blocks inside a blockquote', function () {
    $elements = markdown("> # Title\n> text with **bold**\n>\n> - item");

    expect($elements)->toHaveCount(1);
    expect($elements[0]['type'])->toBe('quote');
    expect(squish($elements[0]['html']))->toBe(
        '<blockquote><h1 id="title">Title</h1><p>text with <strong>bold</strong></p><ul><li>item</li></ul></blockquote>',
    );
});

it('parses bullet and ordered lists with tight items rendered without paragraphs', function () {
    expect(markdownHtml("- a\n- b\n* c"))->toBe('<ul><li>a</li><li>b</li><li>c</li></ul>');
    expect(markdownHtml("1. one\n2. two"))->toBe('<ol><li>one</li><li>two</li></ol>');
    expect(markdownHtml("3) three\n4) four"))->toBe('<ol start="3"><li>three</li><li>four</li></ol>');
});

it('parses nested lists and multi-paragraph items', function () {
    expect(markdownHtml("- a\n  - b\n  - c\n- d"))->toBe('<ul><li><p>a</p><ul><li>b</li><li>c</li></ul></li><li>d</li></ul>');

    expect(markdownHtml("- one\n\n  more\n- two"))->toBe('<ul><li><p>one</p><p>more</p></li><li>two</li></ul>');
});

it('keeps items separated by blank lines in one list', function () {
    expect(types(markdown("- a\n\n- b\n\n- c")))->toBe(['bullet-list']);
});

it('separates a bullet list from an ordered list and a divider', function () {
    expect(types(markdown("- a\n1. b")))->toBe(['bullet-list', 'ordered-list']);
    expect(types(markdown("- a\n---\n- b")))->toBe(['bullet-list', 'divider', 'bullet-list']);
});

it('parses a table with alignment and inline cells', function () {
    $html = markdownHtml("| Name | Qty |\n|:-----|----:|\n| **a** | 1 |\n| b \\| c | 2 |");

    expect($html)->toContain('<th style="text-align: left">Name</th>');
    expect($html)->toContain('<th style="text-align: right">Qty</th>');
    expect($html)->toContain('<td style="text-align: left"><strong>a</strong></td>');
    expect($html)->toContain('<td style="text-align: left">b | c</td>');
});

it('does not turn pipes without a delimiter row into a table', function () {
    expect(types(markdown("a | b\nc | d")))->toBe(['paragraph']);
});

it('records line numbers for nested blocks through the top element', function () {
    expect(array_column(markdown("para\n\n> quote\n\n# h"), 'line'))->toBe([1, 3, 5]);
});

it('copes with windows line endings', function () {
    expect(types(markdown("# A\r\n\r\ntext\r\n- x\r\n- y")))->toBe(['header-one', 'paragraph', 'bullet-list']);
});

it('renders a hard line break', function () {
    expect(markdownHtml("one  \ntwo\\\nthree"))->toBe("<p>one<br>\ntwo<br>\nthree</p>");
});

it('does not hang or crash on deeply nested quotes', function () {
    $html = markdownHtml(str_repeat('> ', 60) . 'deep');

    expect($html)->toContain('deep');
});
