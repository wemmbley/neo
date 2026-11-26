<?php

declare(strict_types=1);

it('renders bold, italic, strike and code', function () {
    expect(inlineHtml('**b** __b__ *i* _i_ ~~s~~ `c`'))->toBe(
        '<p><strong>b</strong> <strong>b</strong> <em>i</em> <em>i</em> <del>s</del> <code>c</code></p>',
    );
});

it('nests emphasis', function () {
    expect(inlineHtml('***both***'))->toBe('<p><strong><em>both</em></strong></p>');
    expect(inlineHtml('*a **b** c*'))->toBe('<p><em>a <strong>b</strong> c</em></p>');
    expect(inlineHtml('**a *b* c**'))->toBe('<p><strong>a <em>b</em> c</strong></p>');
});

it('leaves lone markers and intraword underscores alone', function () {
    expect(inlineHtml('2 * 3 * 4'))->toBe('<p>2 * 3 * 4</p>');
    expect(inlineHtml('snake_case_name'))->toBe('<p>snake_case_name</p>');
    expect(inlineHtml('unclosed **bold'))->toBe('<p>unclosed **bold</p>');
});

it('honours backslash escapes', function () {
    expect(inlineHtml('\*not italic\* and \[not link\](x)'))->toBe('<p>*not italic* and [not link](x)</p>');
});

it('does not interpret markdown inside inline code and escapes its content', function () {
    expect(inlineHtml('`**x** <b>`'))->toBe('<p><code>**x** &lt;b&gt;</code></p>');
    expect(inlineHtml('``a ` b``'))->toBe('<p><code>a ` b</code></p>');
});

it('renders links with title, relative and external targets', function () {
    expect(inlineHtml('[home](/index "Start")'))->toBe('<p><a href="/index" title="Start">home</a></p>');
    expect(inlineHtml('[x](https://example.com)'))->toBe('<p><a href="https://example.com" rel="noopener noreferrer">x</a></p>');
});

it('renders autolinks and links with markdown or parentheses in the label and url', function () {
    expect(inlineHtml('<https://example.com/a?b=1&c=2>'))->toContain('href="https://example.com/a?b=1&amp;c=2"');
    expect(inlineHtml('[**bold** `code`](/x)'))->toBe('<p><a href="/x"><strong>bold</strong> <code>code</code></a></p>');
    expect(inlineHtml('[w](https://en.wikipedia.org/wiki/A_(b))'))->toContain('href="https://en.wikipedia.org/wiki/A_(b)"');
});

it('keeps brackets without a destination as text', function () {
    expect(inlineHtml('[just text] and (parens)'))->toBe('<p>[just text] and (parens)</p>');
});

it('renders an inline image inside a paragraph', function () {
    expect(inlineHtml('Look ![alt *x*](/a.png "T") here'))->toBe(
        '<p>Look <img src="/a.png" alt="alt *x*" title="T" loading="lazy"> here</p>',
    );
});

it('renders a link that wraps an inline image', function () {
    expect(inlineHtml('see [![l](/l.png)](/page) now'))->toBe(
        '<p>see <a href="/page"><img src="/l.png" alt="l" loading="lazy"></a> now</p>',
    );
});

it('produces inline elements inside headings, lists and table cells', function () {
    expect(inlineHtml('## [Docs](/d) and `x`'))->toBe('<h2 id="docs-and-x"><a href="/d">Docs</a> and <code>x</code></h2>');
    expect(squish(inlineHtml('- *a*')))->toBe('<ul><li><em>a</em></li></ul>');
});
