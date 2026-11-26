# Neo Markdown

Парсер markdown для Neo. Без сторонних пакетов. Разбирает документ на элементы
(заголовки, абзацы, картинки, медиа, списки, таблицы, код, директивы) и отдаёт `view`, `collection` или `array`.

```php
new MarkdownManager(new MarkdownTransfer($config));

Markdown::parse('path/file.md');        // зависит от config['format']
Markdown::parseString('# Заголовок');   // то же, без кэша
Markdown::flush();                      // очистить кэш, вернёт число удалённых файлов
```

## Главная идея: синтаксис = класс + Transfer + blade

Каждый элемент разметки — **отдельный синтаксис** со своим Transfer и своим blade.
Внешний вид любого элемента меняется подменой одного файла.

| Синтаксис | Разметка | Тип |
|---|---|---|
| `HeaderOneSyntax` … `HeaderSixSyntax` | `#` … `######` | блок |
| `ParagraphSyntax` | абзац | блок |
| `QuoteSyntax` | `> цитата` | блок |
| `CodeBlockSyntax` | ` ```lang ` / `~~~` | блок |
| `DividerSyntax` | `---` `***` `___` | блок |
| `BulletListSyntax`, `OrderedListSyntax` | `- a` / `1. a` (вложенные тоже) | блок |
| `TableSyntax` | таблица GFM с выравниванием | блок |
| `ImageSyntax` | `![alt](src "title")`, `[![alt](src)](href)` | блок и inline |
| `VideoFileSyntax` | `![t](x.mp4)` | блок |
| `AudioSyntax` | `![t](x.mp3)` | блок |
| `EmbedSyntax` | YouTube / Vimeo (`![t](url)` или URL отдельным абзацем) | блок |
| `HtmlSyntax` | сырой HTML (только когда `escape` и `sanitize` выключены) | блок |
| `LinkSyntax` | `[t](url "title")`, `<url>`, голый `https://…` | inline |
| `BoldSyntax` | `**a**` `__a__` | inline |
| `ItalicSyntax` | `*a*` `_a_` | inline |
| `StrikeSyntax` | `~~a~~` | inline |
| `InlineCodeSyntax` | `` `a` `` | inline |
| `VideoSyntax` | `::video{title="…" thumbnail="…" url="…"}` | директива, подключается через config |

Структура папки: `Syntax/HeaderOneSyntax/HeaderOneSyntax.php` + `HeaderOneSyntax.blade.php` (+ `…Transfer.php`,
если Transfer не общий).

## Привязка: registerSyntax(Syntax, Transfer, blade)

```php
// в любом service provider (наследник MarkdownServiceProvider или любой другой):
$registry = $this->app->make(SyntaxRegistry::class);

$registry->register(VideoSyntax::class, VideoSyntaxTransfer::class, '/abs/path/video.blade.php');
```

В `MarkdownServiceProvider` для этого есть методы:

```php
$this->registerSyntax(VideoSyntax::class, VideoSyntaxTransfer::class, '/abs/path/video.blade.php');
$this->registerSyntax(HeaderOneSyntax::class, template: '/abs/path/h1.blade.php'); // только свой blade
$this->unregisterSyntax(HeaderSixSyntax::class);                                    // убрать синтаксис
```

То же без провайдера (после `new MarkdownManager(...)`):

```php
Markdown::registerSyntax(HeaderOneSyntax::class, null, '/abs/path/h1.blade.php');
```

Или в конфиге (`config/markdown.php`):

```php
'syntax' => [
    VideoSyntax::class,                                         // всё по умолчанию
    HeaderOneSyntax::class => ['template' => '/abs/h1.blade.php'],
    HeaderTwoSyntax::class => ['transfer' => MyH2Transfer::class, 'template' => '/abs/h2.blade.php'],
],
```

Правила:

* Второй и третий аргумент необязательны. `null` — значение по умолчанию синтаксиса.
* Регистрация идёт **по имени** (`name()`): тот же синтаксис, зарегистрированный ещё раз, **заменяет** прежнюю привязку.
  Так переопределяется любой встроенный синтаксис.
* Свой Transfer должен **наследовать** Transfer по умолчанию этого синтаксиса (синтаксис принимает его в конструктор).
  Иначе `LogicException` при регистрации, а не `TypeError` при рендере.
* Шаблон — абсолютный путь к файлу. Если файла нет — `LogicException` при регистрации.
* Регистрация действует сразу, в том числе после первого `parse()`. Кэш сбрасывается автоматически.

## Что получает blade

Переменные: `$el` — Transfer элемента, `$syntax` — объект синтаксиса.

Договорённость об экранировании: поля с **готовым безопасным HTML** (`content`, `items`, `head`, `rows`)
выводятся через `{!! !!}`. Все остальные (`text`, `alt`, `title`, `url`, `code`, `src`) — **сырые строки**, только через `{{ }}`.

| Синтаксис | Поля `$el` |
|---|---|
| `HeaderOne…Six` | `level`, `content` (HTML), `text`, `id` |
| `Paragraph` | `content` (HTML), `text` |
| `Quote`, `Bold`, `Italic`, `Strike` | `content` (HTML) |
| `CodeBlock` | `lang` (?string), `code` |
| `InlineCode` | `code` |
| `Link` | `url`, `content` (HTML), `title`, `external` |
| `Image` | `src`, `alt`, `title`, `link`, `block` (картинка на всю строку или в абзаце) |
| `VideoFile`, `Audio` | `src`, `title` |
| `Embed` | `provider`, `id`, `url`, `src`, `thumbnail`, `title` |
| `BulletList` | `items` (список HTML) |
| `OrderedList` | `items` (список HTML), `start` |
| `Table` | `head` (HTML), `align`, `rows` (HTML) |
| `Html` | `html` |
| `Video` | `title`, `thumbnail`, `url` |

Пример — свой `<h1>`:

```blade
<h1 class="post-title" id="{{ $el->id }}">{!! $el->content !!}</h1>
```

## Элементы документа

Каждый элемент верхнего уровня — массив:

```php
['type' => 'header-two', 'line' => 5, 'data' => [...поля Transfer...], 'html' => '<h2 …>…</h2>']
```

Оглавление: `collect($elements)->whereIn('type', ['header-two', 'header-three'])` → `data.text`, `data.id`.
Все картинки документа: `where('type', 'image')`.

Вложенные элементы (заголовок внутри цитаты) в верхний список не попадают, только в `html` родителя.

## Как расширить: свой синтаксис

Нужны три файла рядом: класс, Transfer, blade. Пример — inline `==выделение==`:

```php
class HighlightSyntax extends BasicSyntax implements InlineSyntaxContract
{
    public function __construct(public ContentTransfer $config) {}

    public static function triggers(): string { return '='; }

    public static function matchInline(string $text, int $position, Context $context): ?Hit
    {
        if (preg_match('~\G==(\S(?:.*?\S)?)==~', $text, $m, 0, $position) !== 1) {
            return null;
        }

        return new Hit(strlen($m[0]), ['content' => $context->inline($m[1])]);   // Hit(сколько байт, атрибуты Transfer)
    }
}
```

`HighlightSyntax.blade.php`: `<mark>{!! $el->content !!}</mark>`. Регистрация: `registerSyntax(HighlightSyntax::class)`.

* **Блочный** синтаксис реализует `BlockSyntaxContract::matchBlock(Lines $lines, Context $context): ?Hit`;
  `Hit(сколько строк занято, атрибуты)`.
* **Директива** `::имя{…}` — наследуйте `BasicDirectiveSyntax`, достаточно конструктора с Transfer (см. `VideoSyntax`).
  Если нужна проверка атрибутов (например URL) — переопределите `accepts()`.
* Синтаксис может быть и блочным, и inline (как `ImageSyntax`).
* `priority()` — порядок проверки, меньше — раньше. Блоки: код 10, директивы 20, заголовки 30, разделитель 40,
  цитата 50, таблица 60, списки 70, медиа 80–90, html 95. Inline: код 10, картинка 20, ссылка 30, жирный 40, зачёркнутый 45, курсив 50.
* Имя по умолчанию — из имени класса: `ImageGallerySyntax` → `image-gallery`.
  Чтобы заменить встроенный синтаксис наследником, переопределите `name()`.
* Внутри синтаксиса используйте `$context`: `inline()` (вложенный inline), `blocks()` (вложенные блоки),
  `text()` (безопасный текст), `safeUrl()` (проверка URL по режиму `sanitize`), `slug()`.

## Конфиг

См. `config/markdown.php`. Валидируется `MarkdownTransfer`.

| Ключ | |
|---|---|
| `format` | `view`, `collection`, `array` |
| `modes.html.escape` | сырой HTML из markdown экранируется |
| `modes.html.sanitize` | блокировка `javascript:`, `data:text/html`, SVG-data и т.п. в картинках, медиа и ссылках |
| `cache.enabled` / `ttl` / `path` | файловый кэш; `ttl = 0` — бессрочно (до изменения файла); `path` обязателен только при `enabled` |
| `syntax` | дополнительные и переопределяемые синтаксисы |

Режимы HTML: `escape = true` — всё экранируется. `escape = false, sanitize = true` — теги вырезаются.
Оба `false` — сырой HTML проходит как есть (только для доверенных авторов).

Ключ кэша: путь + mtime + размер файла + режимы + набор синтаксисов с mtime их шаблонов, классов и Transfer.
Правка файла или blade сбрасывает кэш сама.

## Ограничения

* Заголовки только ATX (`#`), без `===` / `---` под текстом.
* Блоки кода отступом (4 пробела) не поддерживаются, только ограждённые.
* Цитата: строки без `>` не считаются продолжением (нет «ленивого» продолжения).
* Список: пункты с пустыми строками между ними выводятся так же компактно, как без них; чекбоксы `[ ]` не поддерживаются.
* Ссылки-определения `[id]: url`, сноски, HTML-сущности вида `&copy;` сверх экранирования — не поддерживаются.
* Одиночный `~текст~` не зачёркивается, только `~~текст~~`.
* Директива — одна строка, без вложенного содержимого. Неизвестная `::name{}` остаётся обычным текстом.

## Интеграция с проектом

Единственное место, где пакет касается blade-движка, — `Rendering/ViewRenderer.php`:

```php
public function make(string $path, array $data = []): object
{
    return View::file($path, $data);   // Neo\View\View
}
```

Если у вашего `View` другой способ отрендерить файл по абсолютному пути — меняйте только этот метод
или передайте свой `TemplateRendererContract` третьим аргументом `MarkdownManager`.

## Тесты

Pest, `tests/`. Хелперы — `tests/Helpers.php`, примеры собственных синтаксисов — `tests/Fixtures/`.
