# Neo\Storage

Файловое хранилище движка: диски с единым API, безопасная работа с путями, загрузки и обработка изображений.

> Пространства имён ниже взяты из текущего кода. Если вы переносите классы, обновите этот файл.

## Быстрый старт

```php
$manager = new StorageManager(new StorageTransfer([
    'default' => 'local',
    'disks' => [
        'local'  => ['driver' => 'local', 'root' => '/var/app/storage', 'serve' => true, 'throw' => true],
        'memory' => ['driver' => 'memory'],
    ],
    'links' => ['/var/www/public/storage' => '/var/app/storage'],
]));

$disk = $manager->disk();            // диск по умолчанию
$disk->put('docs/a.txt', 'hello');
$disk->get('docs/a.txt');            // 'hello'
$disk->url('docs/a.txt');            // '/storage/docs/a.txt' (если serve = true)
```

## Структура

| Класс | Namespace | Роль |
|---|---|---|
| `StorageManager` | `Neo\Storage\Managers` | Создаёт и кеширует диски, `extend()`, `build()`, `fake()`, `link()` |
| `StorageTransfer` | `Neo\Storage\Transfers` | DTO конфигурации (валидируется при создании) |
| `Disk` | `Neo\Storage\Domain` | Пользовательский API поверх драйвера, политика ошибок |
| `LocalDriver`, `MemoryDriver` | `Neo\Storage\Drivers` | Реализации хранилища |
| `PathNormalizer` | `Neo\Storage\Domain` (проверьте, тесты могут ссылаться на другой) | Нормализация и проверка путей |
| `Visibility` | `Neo\Storage\Enums` | `Public` / `Private`, `Visibility::resolve()` |
| `UploadedFile` | `Neo\Storage\Files` | Безопасная обёртка над загруженным файлом |
| `Image` | `Neo\Storage\Images` | Цепочка обработки изображений на GD |
| `StorageResponse` | `Neo\Http\Response` | Ответ «отдать файл»: `status`, `headers`, `stream`, `send()` |
| `Neo\Storage\Contracts\*` | | `DiskContract`, `DriverContract`, `StorageManagerContract`, `UploadedFileContract`, `ShouldProvide\*` |
| `Neo\Storage\Exceptions\*` | | См. раздел «Исключения» |

## Конфигурация диска

Ключи диска в `StorageTransfer` (все необязательные, `driver` и `root` проверяет менеджер):

| Ключ | Тип | Смысл |
|---|---|---|
| `driver` | string | `local`, `memory` или имя из `extend()` |
| `root` | string | Корень local-диска (создаётся автоматически) |
| `serve` | bool | Разрешает `url()`; влияет на права по умолчанию (`true` → public) |
| `throw` | bool | Бросать исключения в bool-методах вместо `false` |
| `report` | bool | Отправлять сбои в репортёр |
| `url` | string | База URL, по умолчанию `/storage` |
| `visibility` | string | Видимость по умолчанию |

Верхний уровень: `default` (имя диска), `disks`, `links` (`[путь_ссылки => цель]` для `link()`).

Лишние ключи на любом уровне приводят к `ValidationException`. Чтобы увидеть список ошибок, используйте `$e->errors()`: в `getMessage()` его нет.

## API диска

**Чтение:** `get`, `json($path, $assoc = true)`, `readStream`.
**Запись:** `put($path, string|UploadedFile, $options)`, `putStream`, `putFile($dir, $file)`, `putFileAs($dir, $file, $name)`, `append`, `prepend`.
**Проверки:** `exists`, `missing`, `directoryExists`.
**Метаданные:** `size`, `lastModified`, `mimeType`, `checksum($path, $algo = 'md5')`, `getVisibility`, `setVisibility`.
**Операции:** `delete(string|array)`, `copy`, `move`, `copyToDisk`, `moveToDisk`. Копирование и перемещение не перезаписывают существующий файл.
**Директории:** `files`, `allFiles`, `directories`, `allDirectories`, `makeDirectory`, `deleteDirectory`.
**Клиенту:** `download` (всегда attachment), `response` (inline только для безопасных типов).
**URL:** `url`, `temporaryUrl`, `temporaryUploadUrl`, `buildTemporaryUrlsUsing(fn)`, `path` (только local).

Опции записи: `overwrite` (false → `FileAlreadyExistsException`), `visibility`.

Листинги возвращают отсортированные относительные пути; симлинки и временные файлы (`.<16 hex>.tmp`) скрыты.

## Политика ошибок

- Методы с результатом `bool` при `throw=false` возвращают `false`, при `throw=true` бросают.
- Методы с другим результатом (`get`, `size`, `putFile`…) бросают всегда.
- **Всегда бросаются**, независимо от `throw`: `PathTraversalException`, `UnsupportedOperationException`, а также `DiskNotConfiguredException` (ошибка конфигурации).
- `report=true` отправляет исключение в репортёр с контекстом `['disk' => имя]`. `PathTraversalException` репортится всегда, если репортёр задан.

## Исключения

Базовое `StorageException`. Потомки: `FileNotFoundException`, `FileAlreadyExistsException`, `PathTraversalException`, `UnableToReadException`, `UnableToWriteException`, `UnableToDeleteException`, `UnsupportedOperationException`, `DiskNotConfiguredException`, `InvalidImageException`.

## Безопасность

**Пути.** `PathNormalizer` приводит слеши к `/`, убирает `.` и дубли и отклоняет: `..`, управляющие символы и `\0`, stream-обёртки (`php://`, `file://`, `phar://`, `data://`), буквы дисков (`C:/`).

**Symlink-escape (local).** `resolve()` проверяет `realpath` ближайшего существующего предка. Симлинк внутри root, ведущий наружу, и битый симлинк дают `PathTraversalException`. Симлинк внутри root на другое место внутри root работает.

**Запись.** Атомарная: временный файл рядом и `rename()`. Читатель не видит частично записанный файл, при сбое временные файлы удаляются.

**Удаление.** Корень диска удалить нельзя. `deleteDirectory` удаляет симлинк, но не заходит в цель.

**Отдача клиенту.** Заголовки `X-Content-Type-Options: nosniff` и `Content-Security-Policy: default-src 'none'; sandbox`. Inline разрешён для jpeg/png/gif/webp/avif, pdf, text/plain, audio/*, video/*. HTML и SVG всегда скачиваются. Имя файла в `Content-Disposition` очищается от кавычек, слешей и управляющих символов, для не-ASCII добавляется `filename*=UTF-8''…`.

## Загрузки (`UploadedFile`)

- Имя клиента очищается от путей и управляющих символов.
- Расширение и MIME определяются **по содержимому**, `getClientMimeType()` возвращается как есть, но ему не доверяют.
- `hashName($dir = '')` даёт случайное имя `[0-9a-f]{40}` с расширением по содержимому (или без него для неизвестных типов).
- `isValid()` ложен при ошибке загрузки и для файлов, не прошедших `is_uploaded_file()` (кроме тестового режима).
- Сохранение: `store($dir, $disk = null)`, `storeAs`, `storePublicly`, `storePubliclyAs`. Для этого файлу нужен менеджер.
- `image()` возвращает `Image`.
- `fromGlobals()` не принимает многофайловые массивы (`UnsupportedOperationException`).

## Изображения (`Image`, требуется GD)

Источник: `Image::fromString($bytes, $manager = null)`. Принимает PNG/JPEG/WebP/GIF; SVG и мусор отклоняются (`InvalidImageException`), лимит входных данных 32 МБ.

| Группа | Методы |
|---|---|
| Безопасность | `validate($maxW, $maxH)` проверяет размеры по заголовку до декодирования и ограничивает дальнейшие операции; `reencode()` |
| Геометрия | `resize($w, $h, $keepRatio = true)`, `cover($w, $h)`, `crop($w, $h, $x, $y)`, `orientate()` (EXIF) |
| Формат | `toJpeg($q)`, `toPng()`, `toWebp($q)`, `quality($q)` |
| Информация | `width()`, `height()`, `mimeType()` |
| Вывод | `toString()`, `save($path)`, `store($dir, $disk)`, `storeAs($dir, $name, $disk)` |

`toString()` всегда перекодирует картинку: метаданные (EXIF, tEXt, комментарии) и дописанные в конец данные не сохраняются. Прозрачный PNG при конвертации в JPEG кладётся на белый фон.

## Менеджер

- `disk(?string $name)` создаёт диск и кеширует его. Неизвестный диск, драйвер или конфиг даёт `DiskNotConfiguredException`.
- `build(array $config)` создаёт диск на лету, не регистрируя его.
- `extend(string $driver, \Closure $factory)` добавляет драйвер. Фабрика получает конфиг и возвращает драйвер или готовый `Disk`.
- `fake(?string $name)` подменяет диск in-memory копией (с `throw=true`) для тестов.
- `link(bool $force = false)` создаёт симлинки из `links`. Существующие не трогает, `force` пересоздаёт только симлинки (реальные папки и файлы не удаляются). Если цели нет, `FileNotFoundException`. Возвращает созданные пары.

## Тесты

Запуск: `php cli test` (раннер `Neo\Testing`, синтаксис как в Pest). Файлы лежат в `neo/Storage/Tests`, общие хелперы в `TestHelpers.php` (`storage_manager`, `storage_disk`, `storage_upload`, `storage_png`, `storage_jpeg` и др.).

Одинаковое поведение local и memory проверяется общим циклом `foreach (['local', 'memory'])`.

Нужные расширения PHP: `gd`, `fileinfo`, `mbstring`, `exif` (для `orientate`).

**Windows.** `chmod` не меняет права, поэтому 5 тестов про права и visibility пропускаются через `->skip(...)`. Они проверяются на Linux или в WSL. Симлинк на папку удаляется только через `rmdir`, а не `unlink`.