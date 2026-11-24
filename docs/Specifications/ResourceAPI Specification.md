# Resource API.

### Короткое описание

Фреймворки активно используют API, следовательно нам надо это учесть. Крайне
неудобно и нерационально использовать под каждый роут своё объявление,
поэтому предлагается унификация под спец.класс `ResourceAPI`.

Наследуемый от этого класса получает автоматическую генерацию Resource API,
с возможностью доступа по токену и тесной связи с авторизованными пользователями
в sessions. Авторизация может быть обязательной по API, и с получением временного
токена мы получаем права на закрытые (защищённые) запросы.

Также, ко всему прочему, генерируется динамический роут для веба, который
позволяет просматривать списки доступных API-эндпоинтов, структуру ответов,
и возможность делать запросы прямо из браузера.

Данный модуль технически завязан на модуле Auth, поэтому ядро должно быть
независимым, а всё что относится к Auth должно являться надстройкой, декоратором,
адаптером или чем-либо подобным. Ядро детерминировано-иммутабельно, но может
расширяться.

### Архитектура

Архитектура должна быть максимально просто и незамысловатой. Базово описание 
спецификации требует как минимум две сущности: `ResourceAPIServiceProvider` 
и `ResourceAPI.php` конфиг. Провайдер настраивает конфиг и его публикацию. 
В остальном сущности добавляются по мере роста и используют архитектуру 
laravel-модулей из ядра illuminate. Чем меньше сущностей, тем лучше. Мы 
придерживаемся правила базового минимума чем роскошного максимума. Так же 
документация обязывает расплодить множество Exception - их выносим просто в папку. 
Не боимся паттерна GodClass. Лучше позже распилить, чем расплодить.

### Базовый пример

#### Регистрация роута

Обязательно укажите Middleware, который будет защищать этот роут по вашему усмотрению.
Группа `api` даёт throttle и CORS и не содержит сессии и CSRF, поэтому для API
она обязательна. Префикс задаётся группой.

```php 
Route::prefix('api/v1')->middleware(['api', 'auth'])->group(function() {
    Route::resource(PostResourceAPI::class);
});
```

#### Объявление ресурса
```php 
class PostResourceAPI extends ResourceAPI
{

}
```

Имя ресурса строится из имени класса: `PostResourceAPI` → модель `Post`,
имя в URL `posts` (множественное число, snake_case). Можно переопределить через `$name`.

#### Запрос на URL

Мы получим автоматически следующие роуты (при дефолтных `$actions` только первые три):

```
GET localhost/api/v1/posts
GET localhost/api/v1/posts/5
POST localhost/api/v1/posts
PUT localhost/api/v1/posts/5
PATCH localhost/api/v1/posts/5
DELETE localhost/api/v1/posts/5
```

И фасад на JS для клиента:

```js 
$model.get('posts').filter('title').sort('author.name:asc');
```

#### Дополнительно можем открыть доступ на веб-просмотрщик

Просмотрщик раскрывает карту всего API, поэтому он закрыт авторизацией и правом,
а в продакшене по умолчанию выключен (см. конфигурацию, `documentation.enabled`).

```php 
Route::middleware(['web', 'auth', 'can:admin'])->group(function() {
    Route::documentation('/docs/api');
});
```

Путь просмотрщика зарезервирован: ресурс с таким именем зарегистрировать нельзя.

# Маршрутизация

После указания ResourceAPI в маршрутах генерируется следующий роутинг
(только разрешённые действия, указанные в `$actions`, но здесь приведены ВСЕ):

| Метод  | Маршрут                   | Назначение           | Действие  | Ability Gate | Успешный ответ             |
|--------|---------------------------|----------------------|-----------|--------------|----------------------------|
| GET    | /{prefix}/{resource}      | Получение коллекции  | `index`   | `viewAny`    | 200                        |
| GET    | /{prefix}/{resource}/{id} | Получение записи     | `show`    | `view`       | 200                        |
| POST   | /{prefix}/{resource}      | Создание записи      | `store`   | `create`     | 201 + заголовок `Location` |
| PUT    | /{prefix}/{resource}/{id} | Полное обновление    | `update`  | `update`     | 200                        |
| PATCH  | /{prefix}/{resource}/{id} | Частичное обновление | `update`  | `update`     | 200                        |
| DELETE | /{prefix}/{resource}/{id} | Удаление записи      | `destroy` | `delete`     | 204 без тела               |

### Доступные поля в ресурсе

| Поле             | Тип    | Предназначение                                  | По умолчанию                 |
|------------------|--------|-------------------------------------------------|------------------------------|
| $model           | string | Указывает конкретный класс модели               | Берётся из названия класса   |
| $name            | string | Имя ресурса в URL                               | Множественное от модели      |
| $sort            | bool   | Включает или отключает сортировку               | true                         |
| $filter          | bool   | Включает или отключает фильтрацию               | true                         |
| $filterFields    | array  | Список доступных полей к фильтрации             | всё, что не $hidden в модели |
| $sortFields      | array  | Список доступных полей к сортировке             | всё, что не $hidden в модели |
| $renamedFields   | array  | Список переименованных полей модели             | по умолчанию как есть        |
| $filterOperators | array  | Ограничение операторов фильтрации               | [], по умолчанию все         |
| $filters         | array  | Подключённые кастомные фильтры                  | []                           |
| $include         | array  | Связи, доступные для подгрузки через `include`  | []                           |
| $rules           | array  | Правила валидации записи (`store` и `update`)   | []                           |
| $actions         | array  | Доступные действия с ресурсом                   | ['index', 'show', 'store']   |

По умолчанию ресурс не даёт возможности удалять или обновлять записи в целях
безопасности. Это можно настроить в самом ресурсе или в группе ресурсов.

Каждое действие ресурса проходит через Gate (`viewAny`, `view`, `create`, `update`, `delete`),
а при отсутствии Policy действует только `$actions`.

Оговорки:

- Поля модели из `$hidden` недоступны везде: в ответе, фильтре, сортировке, `include` и теле запроса.
- `$renamedFields` действует в обе стороны: клиент использует только переименованные
  имена (в ответе, фильтрах, сортировке и теле записи). Исходные имена недоступны.
- Запись (`store`, `update`) принимает только поля из `$fillable` модели
  и только те, для которых есть правило в `$rules`. Остальные поля отклоняются
  (`PayloadFieldNotAllowedResourceAPIException`), а не молча отбрасываются.
- Валидацию можно вынести в `FormRequest`, ресурс принимает его вместо `$rules`.
- Просмотр и подгрузка связей (`include`) подчиняются Policy `view`/`viewAny`
  связанного ресурса, иначе связь через `include` стала бы обходом авторизации.

### Токены

Временный токен выдаётся и проверяется модулем `Security\Authentication\Tokens`
через гвард, указанный в middleware `auth`. Ресурс про токены ничего не знает:
ему достаточно, что `auth()->user()` определён. Подробности в спецификации модуля Auth.

### Спецификация конфигурации

#### Табличный формат

| Поле                          | Значение                                         | Структура   | По умолчанию                |
|-------------------------------|--------------------------------------------------|-------------|-----------------------------|
| null_last                     | Сортировать null значения в конец списка         | true\|false | true                        |
| relation_deep                 | Максимальная глубина вложений в отношения        | int         | 3                           |
| pagination.per_page           | Сколько по умолчанию записей выводит ресурс      | int         | 15                          |
| pagination.max_per_page       | Максимум записей на вывод                        | int         | 50                          |
| filters.max_conditions        | Максимум условий в одном запросе                 | int         | 20                          |
| filters.max_in_items          | Максимум элементов в `$in`, `$notIn`             | int         | 100                         |
| filters.max_value_length      | Максимальная длина значения фильтра              | int         | 255                         |
| documentation.enabled         | Включить просмотрщик API                         | bool        | true (false в production)  |

При пересечении лимита максимального количества записей бросается `PerPageLimitReachedResourceAPIException`.
При пересечении остальных лимитов бросается соответствующее исключение (см. таблицу исключений).

#### Конфигурационный формат

```php 
return [
    'null_last' => true,
    'relation_deep' => 3,
    'pagination' => [
        'per_page' => 15,
        'max_per_page' => 50,
    ],
    'filters' => [
        'max_conditions' => 20,
        'max_in_items' => 100,
        'max_value_length' => 255,
    ],
    'documentation' => [
        'enabled' => env('APP_ENV') !== 'production',
    ],
];
```

### Спецификация ServiceProvider

#### Добавить путь к кастомным фильтрам

```php 
ResourceAPI::addFiltersPath($dir);
```

Папка должна содержать классы с постфиксом `Filter` и наследоваться
от `FilterResourceAPI` (он реализует контракт `ResourceFilter`).
Подключается к ресурсу через поле `$filters`:

```php 
class PostResourceAPI extends ResourceAPI
{
    protected array $filters = [PopularPostFilter::class];
}
```

<hr>

# Типы фильтраций

Общие правила для всех операторов:

- Все значения приходят строками и приводятся к типу колонки. Не приводимое значение
  вызывает `IncorrectRequestParamsResourceAPIException`.
- Значения для `LIKE`-операторов (`$contains`, `$startsWith`, `$endsWith` и их варианты)
  экранируются: символы `%` и `_` считаются обычными символами и не управляют шаблоном.
- Фильтровать можно только по полям, разрешённым в `$filterFields` и не скрытым в `$hidden`.

#### Eq - эквивалентность

```http request
GET /api/v1/users?filters[name][$eq]=John
```

Найти в модели `User` поле `name`, значение которого равно `John`.
Даже если поле будет `john`, то в выборку запись попадёт.

Нечувствительность к регистру реализуется явно, через `LOWER()` с обеих сторон,
и не зависит от collation БД.

#### Eqc - эквивалентность с чувствительным регистром

```http request
GET /api/v1/users?filters[name][$eqc]=John
```

Найти в модели `User` поле `name`, значение которого равно `John`.
Если поле будет равно `john`, то в результирующей выборке его не будет.

Реализуется бинарным сравнением, независимо от collation БД.

#### Paginator - количественная настройка вывода

```http request
GET /api/v1/users?page[number]=1&page[size]=15
```

Указывает 1-ю страницу пагинатора по 15 записей.

Если `page[size]` больше `pagination.max_per_page`, бросается
`PerPageLimitReachedResourceAPIException`. Если параметры не переданы,
используются `pagination.per_page` и первая страница.

Формат ответа:
```json 
{
  "status": "ok",
  "code": 200,
  "data": [
    { "id": 1, "name": "Item 1" }
  ],
  "paginator": {
    "links": {
      "first": "http://example.com",
      "last": "http://example.com",
      "prev": null,
      "next": "http://example.com"
    },
    "meta": {
      "path": "http://example.com",
      "rows": {
        "from": 1,
        "to": 15
      },
      "pages": {
        "current_page": 1,
        "last_page": 12,
        "per_page": 15,
        "total": 175
      }
    }
  },
  "errors": []
}
```

#### Формат ответа для одной записи

Для `show`, `store` и `update` поле `paginator` отсутствует:

```json 
{
  "status": "ok",
  "code": 200,
  "data": { "id": 1, "name": "Item 1" },
  "errors": []
}
```

`store` отвечает кодом `201` и заголовком `Location`, `destroy` отвечает `204` без тела.

#### Sort - сортировка

```http request
GET /api/v1/posts?sort=author.name:asc,created_at:desc
```

Несколько полей перечисляются через запятую, порядок полей задаёт приоритет.
Сортировать можно только по прямым полям модели и по полям `BelongsTo`-связей.
Сортировка по `HasMany` и `BelongsToMany` запрещена, потому что порождает
дубли строк (`SortByManyRelationResourceAPIException`).

#### Include - подгрузка связей

```http request
GET /api/v1/restaurants?include=chef&fields=id,name
```

`include` подгружает связи одним запросом, без N+1 на сервере и без N запросов
с клиента. Допустимы только связи из `$include`, для которых существует свой
ресурс и разрешён `viewAny`. `fields` ограничивает набор полей ответа
(скрытые поля недоступны).

#### In - найти из массива записей

```http request
GET /api/v1/restaurants?filters[id][$in][0]=3&filters[id][$in][1]=6&filters[id][$in][2]=8
```

Найти в модели `Restaurant` поле `id`, значение которого равно `3` или `6` или `8`.
Размер массива ограничен `filters.max_in_items`.

#### Between - найти в промежутке между-между

```http request
GET /api/v1/users?filters[age][$between][0]=20&filters[age][$between][1]=30
```

Найти в модели `User` поле `age`, значение которого находится между `20` и `30`.

#### Complex - комплексная фильтрация

```http request
GET /api/v1/books?filters[$or][0][date][$eq]=2020-01-01&filters[$or][1][date][$eq]=2020-01-02&filters[author][name][$eq]=Saint%20Lucius
```

Найти в модели `Book`:

- Поле `date` равно `2020-01-01` или `2020-01-02`
- Поле `author` (это BelongsTo отношение в модели) и его поле `name` равно `Saint Lucius`

Условия на одном уровне объединяются через `AND`, если не указано `$or`.
Общее число условий ограничено `filters.max_conditions`.

#### Relation - фильтрация через отношения

Глубина строго ограничена до трёх, ни более. Можно изменить в настройках.
Фильтр через связи подчиняется `$filterFields` и Policy `viewAny` связанного ресурса.

```http request
GET /api/v1/restaurants?filters[chef][restaurants][stars][$eq]=5
```

Алгоритм:
1. Разобрать путь `chef` → `restaurants` → `stars` на сегменты. Каждый сегмент, кроме последнего,
   должен быть связью модели, последний сегмент должен быть колонкой, за которой стоит оператор.
2. Проверить глубину: число связей в пути не больше `relation_deep`, иначе `RelationDepthExceededResourceAPIException`.
3. Для каждой связи проверить, что у связанной модели есть свой ResourceAPI, и
   Policy `viewAny` этого ресурса разрешает доступ. Иначе `RelationNotAllowedResourceAPIException`.
4. В `Restaurant` найти связь `chef` (`BelongsTo` на `Chef`).
5. Внутри `Chef` найти связь `restaurants` (`HasMany` на `Restaurant`).
6. Для `Restaurant` (в роли связанной модели) проверить, что поле `stars` разрешено в `$filterFields`
   его ресурса и не скрыто, а оператор `$eq` разрешён `$filterOperators`. Иначе
   `ColumnNotFoundResourceAPIException` или `OperatorNotAllowedResourceAPIException`.
7. Построить запрос: `whereHas('chef')` → внутри `whereHas('restaurants')` → внутри условие `stars = 5`.

Обязательное условие:

В каждой из этих моделей должно быть обозначено соответствующее отношение.
По умолчанию, если модель имеет релейшн к другой модели, а для этой второй
модели нет своего настроенного API-ресурса, такие запросы с фронта блокируются.
Для любых связей нужен роут API-ресурса. Принципиально.

```php 
class Restaurant extends Model 
{
    public array $fillable = [
        'stars'
    ];

    public function chef()
    {
        return $this->belongsTo(Chef::class);
    }
}

class Chef extends Model 
{
    public function restaurants()
    {
        return $this->hasMany(Restaurant::class);
    }
}
```

#### Complex Relation - комплексная фильтрация с отношениями

```http request
GET /api/v1/restaurants?filters[chef][restaurants][stars][$eq]=5&filters[chef][restaurants][cuisine][$eq]=Italian
```

Алгоритм:
1. Разобрать оба условия на пути. Оба имеют один и тот же путь связей `chef` → `restaurants`,
   различаются только колонки (`stars` и `cuisine`).
2. Сгруппировать условия по общему пути связей. Условия с одинаковым путём попадают в одну группу.
3. Для пути `chef` → `restaurants` выполнить проверки глубины, наличия ресурса, Policy `viewAny`
   (шаги 2 и 3 алгоритма Relation) один раз на группу.
4. Для каждой колонки группы (`stars`, `cuisine`) проверить `$filterFields` и `$filterOperators`.
5. Построить один вложенный `whereHas`: `whereHas('chef')` → `whereHas('restaurants')` → внутри оба
   условия через `AND`: `stars = 5` и `cuisine = 'Italian'`.
6. Если в запросе есть другие группы с другим путём (например, `author`),
   каждая получает свой `whereHas`, и все группы объединяются через `AND`
   (или через `OR`, если находятся внутри `$or`).

Условия `stars = 5` и `cuisine = Italian` выполняются у одного и того же ресторана (один `whereHas`
с двумя условиями), а не у разных ресторанов одного шефа. Если нужно второе поведение,
условия должны быть разнесены в разные группы через `$and`:

```http request
GET /api/v1/restaurants?filters[$and][0][chef][restaurants][stars][$eq]=5&filters[$and][1][chef][restaurants][cuisine][$eq]=Italian
```

Здесь каждый элемент `$and` строит свой `whereHas`, то есть шеф должен иметь
пятизвёздочный ресторан и (возможно, другой) итальянский ресторан.

## Schema - получение схемы

```http request
GET /api/v1/posts/schema
```

Ответ:

```json 
{
  "resource": "posts",
  "fields": [
    {
      "name": "title",
      "type": "string",
      "sortable": true,
      "searchable": true
    },
    {
      "name": "status",
      "type": "string"
    }
  ],
  "actions": [
    "create",
    "update",
    "delete"
  ]
}
```

Поскольку ResourceAPI планируется активно внедрять в будущую административную 
круд-панель, необходимо предусмотреть, как именно сюда будут попадать мета-данные 
по типу `actions`. Возможно, специализированным сервис-провайдером. 

## Полная таблица

| Имя             | Условие                                                     | Тип          | Пример      |
|-----------------|-------------------------------------------------------------|--------------|-------------|
| `$eq`           | Равен                                                       | string       | `"Lucius"`  |
| `$eqc`          | Равен (чувствителен к регистру)                             | string       | `"Lucius"`  |
| `$ne`           | Не равен                                                    | string       | `"test"`    |
| `$lt`           | Меньше чем                                                  | int \| float | `5`         |
| `$lte`          | Меньше чем или равен                                        | int \| float | `5`         |
| `$gt`           | Больше чем                                                  | int \| float | `5`         |
| `$gte`          | Больше чем или равен                                        | int \| float | `5`         |
| `$in`           | Включён хотя бы в один элемент массива                      | array        | `[1, 2, 3]` |
| `$notIn`        | Не включён ни в один элемент массива                        | array        | `[1, 2, 3]` |
| `$contains`     | Содержит (LIKE `%value%`)                                   | string       | `"test"`    |
| `$notContains`  | Не содержит (NOT LIKE `%value%`)                            | string       | `"test"`    |
| `$containsc`    | Содержит (чувствителен к регистру)                          | string       | `"test"`    |
| `$notContainsc` | Не содержит (чувствителен к регистру)                       | string       | `"test"`    |
| `$null`         | Значение колонки равно NULL (`true`) или не равно NULL (`false`) | bool    | `true`      |
| `$between`      | Находится в заданном диапазоне                              | array        | `[1, 10]`   |
| `$notBetween`   | Не находится в заданном диапазоне                           | array        | `[1, 10]`   |
| `$startsWith`   | Начинается с указанного значения                            | string       | `"Luc"`     |
| `$startsWithc`  | Начинается с указанного значения (чувствителен к регистру)  | string       | `"Luc"`     |
| `$endsWith`     | Заканчивается указанным значением                           | string       | `"ius"`     |
| `$endsWithc`    | Заканчивается указанным значением (чувствителен к регистру) | string       | `"ius"`     |
| `$or`           | Объединяет условия логическим OR                            | array        | `[...]`     |
| `$and`          | Объединяет условия логическим AND                           | array        | `[...]`     |
| `$exists`       | Связь имеет (`true`) или не имеет (`false`) связанных записей | bool       | `true`      |

Замечания к таблице:

- `$null` относится к значению колонки, `$exists` относится к наличию связи
  (`filters[chef][$exists]=true`). Отдельные `$notNull` и `$notExists` не нужны: достаточно `false`.
- Оператор вне списка `$filterOperators` ресурса вызывает `OperatorNotAllowedResourceAPIException`.

# Пример работы модели в движке

Данный ResourceAPI должен расширять базовую модель через новый метод `filter()`.
Метод принадлежит адаптеру `OrmResourceRepository`, ядро оперирует только AST запроса.

#### Базовая спецификация фильтрации

```php 
$params = [
    'filters' => [
        'chef' => [
            'restaurants' => [
                'stars' => [
                    '$eq' => 5,
                ],
                'cuisine' => [
                    '$eq' => 'Italian',
                ],
            ],
        ],
    ],
];

$restaurants = Restaurant::filter($params)->get();
```

# Клиент
### Пример сырого запроса с клиента

```http request
GET /api/v1/posts?filters[title][$contains]=Neo&sort=author.name:asc
```

### Пример спецификации JSON для ядра клиента

```json 
{
  "filters": {
    "$or": [
      {
        "date": {
          "$eq": "2020-01-01"
        }
      },
      {
        "date": {
          "$eq": "2020-01-02"
        }
      }
    ],
    "author": {
      "name": {
        "$eq": "Kai Doe"
      }
    }
  }
}
```

### Пример адаптера для ядра клиента на JS

```js
$model.config({
    url: 'localhost/api/v1',
});
$model.get('posts').filter('title').sort('author.name:asc');
```

### Механика именованных JS эндпоинтов

Данная механика подразумевает парсинг JSON файла, который сервер генерирует
автоматически. Эта ответственность лежит на роутере системы, и для всех
named-роутов он должен генерировать понятный JSON. Это позволит на фронте
написать адаптер, который будет запрашивать JSON с сервера раз при старте,
получать имена роутов и обращаться напрямую по именам. Кеширование делается
средствами HTTP: ответ отдаётся с `ETag` и `Cache-Control: max-age=60`, клиент
использует условные запросы. Кука для этого не используется.

```js 
$route.get('posts')
```

## ResourceAPIException

Если поле не фильтруется или не сортируется, Exception обязательно об этом сообщит.
Исключения не зависят от HTTP: ядро бросает их, а адаптер-контроллер превращает
в ответ. Единый Handler выбирает формат по запросу (`expectsJson()` или префикс API).

Формат ответа:
```json 
{
  "status": "error",
  "code": 404,
  "errors": [
    { "code": "model_not_found", "field": null, "message": "Requested model Post was not found." }
  ]
}
```

Имена классов исключений в ответ не попадают, наружу уходит только стабильный строковый `code`.

Формат ответа при ошибке валидации записи (несколько ошибок сразу):
```json 
{
  "status": "error",
  "code": 422,
  "errors": [
    { "code": "validation_failed", "field": "title", "message": "The title field is required." },
    { "code": "validation_failed", "field": "body", "message": "The body must not exceed 5000 characters." }
  ]
}
```

Оговорки:

- Для скрытого (`$hidden`), запрещённого и несуществующего поля ответ **одинаковый**
  (`column_not_found`, 422, одно и то же сообщение). Иначе по разнице ответов
  можно узнать, что скрытое поле существует.
- В режиме отладки сообщение может перечислять допустимые поля. В продакшене не перечисляет.
- Ошибки авторизации (401, 403) и лимитов (429) принадлежат модулям Auth и Http (`throttle`)
  и приходят в том же формате через единый Handler.

Таблица доступных Exceptions:

| Имя                                          | Значение                                                         | Код | `code` в ответе          |
|----------------------------------------------|------------------------------------------------------------------|-----|--------------------------|
| ResourceAPIException                         | Базовый Exception, от него наследуются все.                      | 400 | `resource_api_error`     |
| ModelNotFoundResourceAPIException            | Запрашиваемая модель или ресурс не найдены на сервере            | 404 | `model_not_found`        |
| RecordNotFoundResourceAPIException           | Запись с указанным id не найдена                                 | 404 | `record_not_found`       |
| ColumnNotFoundResourceAPIException           | Колонка не найдена, скрыта или не разрешена для операции         | 422 | `column_not_found`       |
| OperatorNotAllowedResourceAPIException       | Оператор неизвестен или не входит в `$filterOperators`           | 422 | `operator_not_allowed`   |
| RelationNotAllowedResourceAPIException       | Связь не имеет своего ресурса, не разрешена или запрещена Policy | 422 | `relation_not_allowed`   |
| RelationDepthExceededResourceAPIException    | Превышена глубина вложений `relation_deep`                       | 422 | `relation_depth_exceeded`|
| FilterLimitExceededResourceAPIException      | Превышен `max_conditions`, `max_in_items` или `max_value_length` | 422 | `filter_limit_exceeded`  |
| SortByManyRelationResourceAPIException       | Попытка сортировки по `HasMany` или `BelongsToMany`              | 422 | `sort_not_allowed`       |
| PerPageLimitReachedResourceAPIException      | Превышен пользовательский лимит записей на страницу              | 422 | `per_page_limit_reached` |
| IncorrectRequestParamsResourceAPIException   | Пользователь ввёл некорректные данные в запрос                   | 400 | `incorrect_request_params`|
| ValidationFailedResourceAPIException         | Тело записи не прошло `$rules` или `FormRequest`                 | 422 | `validation_failed`      |
| PayloadFieldNotAllowedResourceAPIException   | В теле записи поле вне `$fillable` или без правила в `$rules`    | 422 | `payload_field_not_allowed`|
| ActionStrictResourceAPIException             | Попытка вызвать запрещённое действие для ресурса                 | 405 | `action_not_allowed`     |
| UnauthenticatedResourceAPIException          | Пользователь не опознан (бросает адаптер Auth)                   | 401 | `unauthenticated`        |
| ForbiddenResourceAPIException                | Gate или Policy отказали в действии (бросает адаптер Auth)       | 403 | `forbidden`              |