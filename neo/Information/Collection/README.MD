# app/collections

Автономная коллекция в стиле Laravel (PHP >= 8.1, без зависимостей). В одном классе `Collections\Collection` объединены методы `Illuminate\Support\Collection` и `Illuminate\Database\Eloquent\Collection`, плюс пагинаторы.

## Быстрый старт

```php
use Collections\Collection;

$page = Collection::fromArr([1, 2, 3, /* ... */])->paginate(15);

// то же самое, что в Laravel
$page = collect($items)->paginate(15);
$page = Collection::make($items)->paginate(15, page: 2);
```

## Создание

| Метод | Описание |
|---|---|
| `new Collection($items)` / `Collection::make($items)` | из массива, Traversable, JsonSerializable или объекта с `toArray()` |
| `Collection::fromArr(array $items)` | алиас `make()` (обратная совместимость) |
| `collect($value)` | хелпер |
| `Collection::wrap($v)` | обернуть скаляр/массив/коллекцию |
| `Collection::unwrap($v)` | коллекция в массив |
| `Collection::times($n, $cb)` | `1..n`, опционально через callback |
| `Collection::range($a, $b, $step)` | диапазон |
| `Collection::empty()` | пустая коллекция |

## Пагинация

```php
$p = collect($rows)->paginate(
    perPage: 15,
    page: null,                 // null = берётся из ?page=
    options: ['path' => '/users', 'pageName' => 'page']
);

$p->items();          // элементы текущей страницы
$p->total();          // всего элементов
$p->currentPage();
$p->lastPage();
$p->perPage();
$p->hasMorePages();
$p->firstItem();      // номер первого элемента на странице
$p->lastItem();
$p->nextPageUrl();
$p->previousPageUrl();
$p->url(3);
$p->appends(['q' => 'foo']);   // добавить параметры в ссылки
$p->withQueryString();         // подтянуть текущий query string
$p->fragment('section');
$p->through(fn ($x) => ...);   // преобразовать элементы страницы
$p->elements();                // окно ссылок (как UrlWindow в Laravel)
$p->linkCollection();
$p->toArray();                 // формат Laravel: current_page, data, links, total...
$p->toJson();
```

Опции `paginate()`: `path`, `query`, `fragment`, `pageName`, `onEachSide`.

`simplePaginate($perPage, $page, $options)` возвращает `Paginator` без `total` и `lastPage`: только `hasMorePages`, `nextPageUrl`, `previousPageUrl`.

Пагинатор ведёт себя как коллекция: `foreach`, `count()`, `$p[0]`, а неизвестные методы (`map`, `pluck` и т.д.) проксируются в коллекцию элементов страницы.

### Настройка вне Laravel

По умолчанию страница читается из `$_GET`, путь из `REQUEST_URI`. Переопределение:

```php
use Collections\AbstractPaginator;

AbstractPaginator::currentPageResolver(fn (string $name) => $request->query($name));
AbstractPaginator::currentPathResolver(fn () => $request->path());
AbstractPaginator::queryStringResolver(fn () => $request->query());
```

## Методы коллекции

**Получение:** `all`, `get`, `has`, `hasAny`, `first`, `firstWhere`, `firstOrFail`, `sole`, `last`, `keys`, `values`, `pluck` (dot-нотация), `random`, `search`

**Итерация:** `each`, `map`, `transform`, `mapWithKeys`, `mapInto`, `flatMap`, `reduce`, `tap`, `pipe`, `when`, `unless`, `whenEmpty`, `whenNotEmpty`, `dump`, `dd`

**Фильтры:** `filter`, `reject`, `where`, `whereStrict`, `whereIn`, `whereInStrict`, `whereNotIn`, `whereNull`, `whereNotNull`, `whereBetween`, `whereNotBetween`, `whereInstanceOf`, `partition`, `every`, `contains`, `containsStrict`, `doesntContain`, `some`, `unique`, `uniqueStrict`

**Срезы:** `slice`, `take`, `skip`, `forPage`, `nth`, `chunk`, `splice`

**Изменение:** `add`, `push`, `put`, `prepend`, `pop`, `shift`, `pull`, `forget`

**Объединение:** `merge`, `concat`, `combine`, `zip`, `pad`, `collapse`, `flatten`, `flip`, `reverse`, `shuffle`

**Разность и пересечение:** `diff`, `diffKeys`, `diffAssoc`, `intersect`, `intersectByKeys`, `only`, `except`

**Сортировка:** `sort`, `sortDesc`, `sortBy` (в том числе массив критериев `[['a','asc'],['b','desc']]`), `sortByDesc`, `sortKeys`, `sortKeysDesc`

**Группировка:** `groupBy` (в том числе вложенная), `keyBy`, `countBy`

**Агрегаты:** `sum`, `avg`/`average`, `min`, `max`, `count`, `implode`, `join`

**Прочее:** `isEmpty`, `isNotEmpty`, `containsOneItem`, `toArray`, `toJson`, `toBase`, `macro`

Higher-order прокси: `$c->map->name`, `$c->each->save()`, `$c->sum->price`, а также `filter`, `groupBy`, `sortBy`, `unique` и другие.

### Методы Eloquent

Если коллекция непустая и все элементы имеют `getKey()`, то методы ниже работают по первичному ключу. Для обычных массивов и объектов используется поведение базовой коллекции Laravel.

| Метод | Поведение для моделей |
|---|---|
| `find($key \| $model \| array)` | поиск по ключу, массив ключей возвращает коллекцию |
| `contains($key \| $model)` | проверка по ключу или модели |
| `unique()` | уникальные модели по ключу |
| `merge($models)` | слияние по ключу (последняя модель заменяет) |
| `diff($models)`, `intersect($models)` | сравнение по ключу |
| `only($keys)`, `except($keys)` | фильтр по списку ключей |
| `modelKeys()`, `getDictionary()` | ключи и словарь `key => model` |
| `makeVisible`, `makeHidden`, `setVisible`, `setHidden`, `append` | вызываются на каждой модели |
| `load`, `fresh`, `toQuery` | делегируют в Eloquent-модель, без Eloquent не работают |

## Макросы

```php
Collection::macro('toUpper', function () {
    return $this->map(fn ($v) => strtoupper($v));
});

collect(['a', 'b'])->toUpper();
```

## Исключения

- `ItemNotFoundException` из `firstOrFail()` и `sole()`
- `MultipleItemsFoundException` из `sole()`, у него есть `getCount()`

## Не реализовано

`LazyCollection`, `Enumerable`, `chunkWhile`, `sliding`, `pipeThrough`, `mapSpread`, `ensure`, `percentage`, `multiply` и другие редкие методы Laravel.

## Тесты

```bash
php tests/smoke.php
```