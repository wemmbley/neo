# ResourceAPI

Автоматический REST API по модели: список с фильтрацией, сортировкой и пагинацией, просмотр, создание, изменение, удаление, схема и документация. Спецификация: `docs/Specifications/ResourceAPI Specification.md`.

## Подключение

```php
// src/API/Routes/api.php
Route::resource(PostResourceAPI::class);

// src/UI/Routes/web.php
Route::documentation('/docs/api');   // страница + /docs/api/schema.json
```

```php
final class PostResourceAPI extends ResourceAPI
{
    protected ?string $model = Post::class;
    protected array $include = ['author', 'tags'];
    protected array $searchFields = ['title'];
    protected array $actions = ['index', 'show', 'store'];
}

// Имя в URL и модель берутся из класса (UserResourceAPI → User, /users); при желании:
Route::resource(UserResourceAPI::class)->name('people');

// или без класса
Route::resource(ResourceAPI::make(Post::class)->name('posts')->actions(['index', 'show']));
```

Провайдер `ResourceAPIServiceProvider` уже добавлен в `app/Config/App.php`; настройки — `app/Config/ResourceAPI.php`.

## Запрос

```
GET /posts?filters[title][$contains]=neo&filters[author][name][$eq]=Ann
          &sort=-created_at,author.name:desc&page[number]=2&page[size]=20
          &include=author&fields=id,title&search=text
```

Операторы: `$eq $eqc $ne $lt $lte $gt $gte $in $notIn $contains $containsc $notContains $startsWith $endsWith $null $between $exists`, группы `$or` / `$and`. Фильтры по связям вычисляются через `EXISTS`; сортировка — по полям модели и `BelongsTo` (NULL всегда в конце), по `HasMany`/`BelongsToMany` — `422 sort_not_allowed`.

## Ответ

```json
{ "status": "ok", "code": 200, "errors": [], "data": [...],
  "paginator": { "links": {"first","last","prev","next"},
                 "meta": { "rows": {"from","to"}, "pages": {"current_page","last_page","per_page","total"} } } }
```

Ошибки: `{ "status": "error", "code": 422, "errors": [{"code","field","message"}] }`. Коды: `model_not_found`, `record_not_found`, `column_not_found`, `operator_not_allowed`, `relation_not_allowed`, `relation_depth_exceeded`, `filter_limit_exceeded`, `sort_not_allowed`, `per_page_limit_reached`, `incorrect_request_params`, `payload_field_not_allowed`, `action_not_allowed`, `validation_failed`, `unauthenticated`, `forbidden`.

Та же структура пагинации у `Collection::paginate()` / `simplePaginate()` (`toArray()` → `data` + `paginator`).

## Дополнительно

- Скрытые (`#[Hidden]`) поля недоступны ни в ответе, ни в фильтрах, ни в записи; ответ на скрытое и несуществующее поле одинаков.
- Доступ — через Policy (`GateResourceAuthorizer`); без политики разрешено всё, закрытые связи дают `relation_not_allowed`.
- Свои фильтры: наследник `FilterResourceAPI` + `withFilters([...])`; `Post::filter($params)` применяет правила ресурса к любому запросу.
- Тесты: `php cli test neo/Network/ResourceAPI/Tests`.
