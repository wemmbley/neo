<?php

declare(strict_types=1);

use Neo\Information\Markdown\Contracts\SyntaxContract;
use Neo\Information\Markdown\Syntax\CoreSyntax;
use Neo\Information\Markdown\Syntax\HeaderOneSyntax\HeaderOneSyntax;
use Neo\Information\Markdown\Syntax\HeaderSixSyntax\HeaderSixSyntax;
use Neo\Information\Markdown\Syntax\SyntaxRegistry;
use Neo\Information\Markdown\Syntax\VideoSyntax\VideoSyntax;
use Neo\Information\Markdown\Syntax\VideoSyntax\VideoSyntaxTransfer;
use Neo\Information\Markdown\Tests\Fixtures\CustomHeaderTransfer;
use Neo\Information\Markdown\Tests\Fixtures\HighlightSyntax;
use Neo\Information\Markdown\Tests\Fixtures\NoticeSyntax;

require_once __DIR__ . '/Fixtures/HighlightSyntax.php';
require_once __DIR__ . '/Fixtures/NoticeSyntax.php';
require_once __DIR__ . '/Fixtures/CustomHeaderTransfer.php';

const H1_TEMPLATE = __DIR__ . '/Fixtures/h1-custom.blade.php';
const H1_KICKER_TEMPLATE = __DIR__ . '/Fixtures/h1-kicker.blade.php';

it('ships a separate syntax with its own transfer and blade for every element', function () {
    $names = [];

    foreach (CoreSyntax::all() as $class) {
        /** @var class-string<SyntaxContract> $class */
        expect(is_file($class::template()))->toBeTrue();
        expect(class_exists($class::transfer()))->toBeTrue();

        $names[] = $class::name();
    }

    expect($names)->toHaveCount(23);

    foreach (['header-one', 'header-two', 'header-three', 'header-four', 'header-five', 'header-six', 'paragraph', 'link', 'image', 'bold', 'italic', 'strike', 'inline-code', 'code-block', 'quote', 'divider', 'bullet-list', 'ordered-list', 'table', 'embed', 'video-file', 'audio', 'html'] as $name) {
        expect($names)->toContain($name);
    }
});

it('resolves name, template and transfer by convention', function () {
    expect(VideoSyntax::name())->toBe('video');
    expect(VideoSyntax::transfer())->toBe(VideoSyntaxTransfer::class);
    expect(basename(VideoSyntax::template()))->toBe('VideoSyntax.blade.php');
    expect(basename(HeaderOneSyntax::template()))->toBe('HeaderOneSyntax.blade.php');
});

it('replaces the blade of one built-in syntax and leaves the others alone', function () {
    $manager = markdownManager();
    $manager->registerSyntax(HeaderOneSyntax::class, null, H1_TEMPLATE);

    $elements = $manager->parseString("# One\n\n## Two");

    expect($elements[0]['html'])->toBe('<h1 class="title" id="one">One</h1>');
    expect($elements[1]['html'])->toBe('<h2 id="two">Two</h2>');
});

it('replaces transfer and blade together: registerSyntax(Syntax, Transfer, blade)', function () {
    $manager = markdownManager();
    $manager->registerSyntax(HeaderOneSyntax::class, CustomHeaderTransfer::class, H1_KICKER_TEMPLATE);

    $el = $manager->parseString('# One')[0];

    expect($el['html'])->toContain('<small>KICKER</small>');
    expect($el['data']['kicker'])->toBe('KICKER');
});

it('accepts a custom blade through the config in the class => options form', function () {
    $manager = markdownManager(['syntax' => [VideoSyntax::class, HeaderOneSyntax::class => ['template' => H1_TEMPLATE]]]);

    expect($manager->parseString('# One')[0]['html'])->toContain('class="title"');
});

it('adds a custom inline syntax and applies it inside other syntaxes', function () {
    $manager = markdownManager();
    $manager->registerSyntax(HighlightSyntax::class);

    expect(squish(implode('', array_column($manager->parseString('a ==hot== b'), 'html'))))->toBe('<p>a <mark>hot</mark> b</p>');
    expect($manager->parseString('# ==Hot== **==x==**')[0]['html'])->toContain('<mark>Hot</mark>')->toContain('<strong><mark>x</mark></strong>');
    expect($manager->parseString('- ==in list==')[0]['html'])->toContain('<mark>in list</mark>');
});

it('adds a custom directive with its own transfer and blade', function () {
    $manager = markdownManager();
    $manager->registerSyntax(NoticeSyntax::class);

    $el = $manager->parseString('::notice{type="warning" text="Careful <b>"}')[0];

    expect($el['type'])->toBe('notice');
    expect($el['html'])->toBe('<div class="notice notice--warning">Careful &lt;b&gt;</div>');
});

it('lets a custom syntax run before a built-in one through priority', function () {
    $manager = markdownManager();
    $manager->registerSyntax(NoticeSyntax::class);

    expect(types($manager->parseString("# T\n::notice{text=\"x\"}")))->toBe(['header-one', 'notice']);
});

it('removes a built-in syntax', function () {
    $manager = markdownManager();
    $manager->unregisterSyntax(HeaderSixSyntax::class);

    expect(types($manager->parseString('###### x')))->toBe(['paragraph']);
    expect(types($manager->parseString('##### x')))->toBe(['header-five']);

    $manager->unregisterSyntax('bold');

    expect(markdownHtml('**x**'))->toBe('<p><strong>x</strong></p>');
    expect(squish(implode('', array_column($manager->parseString('**x**'), 'html'))))->toBe('<p>**x**</p>');
});

it('applies a registration made after the first parse', function () {
    $manager = markdownManager();

    expect(types($manager->parseString('# x')))->toBe(['header-one']);

    $manager->registerSyntax(HeaderOneSyntax::class, null, H1_TEMPLATE);

    expect($manager->parseString('# x')[0]['html'])->toContain('class="title"');
});

it('rejects invalid bindings with a clear error', function () {
    $registry = new SyntaxRegistry();

    expect(fn () => $registry->register(stdClass::class))->toThrow(LogicException::class);
    expect(fn () => $registry->register(HeaderOneSyntax::class, null, '/nope/missing.blade.php'))->toThrow(LogicException::class);
    expect(fn () => $registry->register(HeaderOneSyntax::class, stdClass::class))->toThrow(LogicException::class);
    // Transfer другого синтаксиса не подходит: нужен тот же или его наследник.
    expect(fn () => $registry->register(HeaderOneSyntax::class, VideoSyntaxTransfer::class))->toThrow(LogicException::class);
    expect(fn () => $registry->register(Neo\Information\Markdown\Syntax\Base\BasicHeaderSyntax::class))->toThrow(LogicException::class);
});

it('rejects invalid syntax entries in the config', function () {
    expect(fn () => markdownConfig(['syntax' => [stdClass::class]]))->toThrow(Throwable::class);
    expect(fn () => markdownConfig(['syntax' => [HeaderOneSyntax::class => ['templat' => 'x']]]))->toThrow(Throwable::class);
    expect(fn () => markdownConfig(['syntax' => [HeaderOneSyntax::class => ['template' => '/nope.blade.php']]]))->toThrow(Throwable::class);
});

it('invalidates the cache when a template changes', function () {
    $dir = markdownTempDir();
    $template = $dir . '/h1.blade.php';
    file_put_contents($template, '<h1>v1 {!! $el->content !!}</h1>');
    file_put_contents($dir . '/doc.md', '# Title');

    $manager = markdownManager(['cache' => ['enabled' => true, 'ttl' => 3600, 'path' => $dir . '/cache']]);
    $manager->registerSyntax(HeaderOneSyntax::class, null, $template);

    expect($manager->parse($dir . '/doc.md')[0]['html'])->toBe('<h1>v1 Title</h1>');

    file_put_contents($template, '<h1>v2 {!! $el->content !!}</h1>');
    touch($template, time() + 10);

    expect($manager->parse($dir . '/doc.md')[0]['html'])->toBe('<h1>v2 Title</h1>');
});

it('renders the video directive through its transfer and blade', function () {
    $el = markdown('::video{title="Demo" thumbnail="https://cdn.test/t.jpg" url="https://video.test/v"}')[0];

    expect($el['type'])->toBe('video');
    expect($el['data'])->toBe(['title' => 'Demo', 'thumbnail' => 'https://cdn.test/t.jpg', 'url' => 'https://video.test/v']);
    expect(squish($el['html']))->toBe('<a href="https://video.test/v"><img src="https://cdn.test/t.jpg" alt="Demo"></a>');
});

it('uses transfer defaults and accepts quote styles and unquoted values', function () {
    expect(markdown('::video{thumbnail="/t.jpg" url="https://v.test/v"}')[0]['data']['title'])->toBe('Title');

    $el = markdown("::video{title='It\\'s' thumbnail=/img/t.jpg url=https://video.test/v}")[0];

    expect($el['data']['title'])->toBe("It's");
    expect($el['data']['thumbnail'])->toBe('/img/t.jpg');
});

it('escapes directive values in the template output', function () {
    expect(markdownHtml('::video{title="A <b> \\"q\\"" thumbnail="/t.jpg" url="https://v.test/v"}'))->not->toContain('<b>');
});

it('throws on malformed directive attributes and on transfer violations', function () {
    expect(fn () => markdown('::video{title "x"}'))->toThrow(Neo\Information\Markdown\Exceptions\MarkdownException::class);
    expect(fn () => markdown('::video{thumbnail="/t.jpg" url="https://v.test/v" foo="1"}'))->toThrow(Neo\Transfer\ValidationException::class);
    expect(fn () => markdown('::video{thumbnail="/t.jpg"}'))->toThrow(Neo\Transfer\ValidationException::class);
});

it('keeps an unregistered directive as plain text', function () {
    expect(markdownHtml('::gallery{a="b"}'))->toContain('::gallery');
});
