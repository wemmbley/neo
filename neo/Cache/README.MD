# Neo\Cache

Кэш движка: единый API поверх сменных хранилищ. Файловый стор работает через `Neo\Storage`.

## Быстрый старт

```php
$cache = new CacheManager(new CacheTransfer([
    'default' => 'file',
    'prefix'  => 'neo',
    'stores'  => [
        'array' => ['driver' => 'array'],
        'file'  => ['driver' => 'file', 'disk' => 'local', 'directory' => 'cache'],
    ],
]), $storageManager);

Cache::put('user:1', $user, 600);
Cache::get('user:1');
Cache::remember('chart:42', 3600, fn () => buildChart(42));
Cache::store('array')->put('tmp', 1);
```

## Структура

| Класс | Роль |
|---|---|
| `Cache` (фасад) | `Neo\Cache`, проксирует в `CacheManager` |
| `CacheManager` | Создаёт и кеширует сторы, `store()`, `build()`, `extend()`, `fake()`, `purge()`. Сам реализует `CacheStoreContract` (вызовы идут в стор по умолчанию) |
| `AbstractStore` | Вся общая логика (TTL, remember, add, pull, increment). Стор реализует `fetch / write / remove / flush` |
| `ArrayStore` | В памяти процесса. По умолчанию хранит сериализованные значения (отдаёт копии) |
| `FileStore` | Поверх `DiskContract`. Раскладка `<dir>/ab/cd/<sha256>.cache` |
| `PrunableContract` | `prune()`: удалить просроченное. Есть у `ArrayStore` и `FileStore` |

## Семантика

- TTL: секунды, `DateInterval`, `DateTimeInterface` или `null` (навсегда). TTL `<= 0` или дата в прошлом удаляет ключ.
- `null` кэшируется как значение: `has()` для него `true`. Промах определяйте по `has()` или по default-замыканию.
- `get($key, fn () => ...)`: default может быть замыканием.
- `increment` на отсутствующем ключе создаёт его со значением шага. Срок жизни существующего ключа сохраняется. Нечисловое значение даёт `false`.
- `add` в `FileStore` атомарен (`overwrite => false`). `increment` в `FileStore` между процессами не атомарен.
- Просроченные записи удаляются лениво при чтении. Для очистки диска вызывайте `Cache::store('file')->prune()` по расписанию.
- `flush()` в `FileStore` удаляет только свою директорию. Директория не может быть корнем диска.
- Несериализуемое значение (замыкание, ресурс) даёт `CacheException`. Повреждённый файл считается промахом и удаляется.

## Исключения

`CacheException` (база), `CacheNotConfiguredException` (неизвестный стор или драйвер, нет StorageManager для file-драйвера).

## Свой драйвер

```php
Cache::extend('redis', fn (array $config) => new RedisStore(/* ... */, $config['prefix']));
```

Наследуйтесь от `AbstractStore` и реализуйте четыре метода.