# HTTP Спецификация

HTTP слой домена `Network`. Жизненно необходим для любого веб-сайта или сервера. 
Для апишек и чего-угодно связанного с этим. 

# Жизненный цикл

Жизненный цикл у HTTP модуля завязан на Kernel контейнера приложения:
1. Request пришёл
2. Применяем глобальные Middleware к нему
3. Маршрутизируем его через роутинг 
4. Применяем к нему Middleware группы и роута
5. Вызывает контроллер или анонимную функцию
6. Response проходит middleware в обратном порядке
7. Отправляем по HTTP-протоколу сформированный ответ
8. После отправки приложение завершается `terminate()`

Исключения на любом шаге ловит единый Handler.

# Маршрутизация

Примеры маршрутизации в деле будут показаны ниже.

## Доступные методы

| Метод  | Значение             | Способ        | Замечания                                               |
|--------|----------------------|---------------|---------------------------------------------------------|
| GET    | Получение            | Route::get    | Используется для Web'a или API для получения информации |
| POST   | Добавление           | Route::post   | Используется для Web'a форм и API                       |
| PUT    | Полное обновление    | Route::put    | Только API, работа с хранилищем (например с бд)         |
| PATCH  | Частичное обновление | Route::patch  | API, для хранилища                                      |
| DELETE | Удаление             | Route::delete | API, для хранилища                                      |

#### Обычная маршрутизация

| Символ   | Значение                          |
|----------|-----------------------------------|
| category | Обязательный параметр             |
| slug?    | Необязательный из за знака вопрос |

```php 
Route::get('/{category}/{slug?}', [CategoryController::class, 'HandleCategory']);
```

#### Маршрут с кастомной регуляркой

```php 
Route::get('/user/{id}/{name}', [UserController::class, 'index'])
    ->where([
        'id' => '[0-9]+', 
        'name' => '[a-z]+',
    ]);
```

#### Именованый маршрут для сокращений

```php 
Route::get('/user/profile', [UserProfileController::class, 'show'])
    ->name('profile');
```

Теперь Вам станет доступна ссылка при вызове `route('profile')`. 
Причём `route()` вторым параметром принимает массив значений для заполнения.

# Middleware

Прослойка нужна чтобы сгруппировать роуты и единомоменто проверить их всех. 
То есть при запросе на один роут из группы под одной Middleware он будет проверен.
Но для использования прослоке роуты нужно обьявлять специальной группой. 

```php 
Route::middleware(['first', 'second'])->group(function () {
    Route::get('/', fn() => {});
    Route::get('/user/profile', fn() => {});
});
```

Соответственно, одна из прослоек может выглядеть так:

```php 
class FirstMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->input('token') !== 'my-secret-token') {
            return redirect('/home');
        }
 
        return $next($request);
    }
}
```

Не забудьте зарегестрировать свою прослойку в сервис провайдере:
```php 
public function register() {
    Middleware::register([
        'first' => FirstMiddleware::class,
        'second' => SecondMiddleware::class,
    ]);
}
```

Можно и без регистрации. Но тогда Вы будете жить без алиасов в роутинге.
Для пакетов рекомендуется строго с регистрацией.

## Встроенные во фреймворк Middleware

| Имя      | Класс                                                   | Значение                                                       |
|----------|---------------------------------------------------------|----------------------------------------------------------------|
| cookies  | Neo\Network\Cookie\Middleware\EncryptCookies            | Шифрует и подписывает исходящие куки, расшифровывает входящие. |
| cors     | Neo\Network\Http\Foundation\Middleware\HandleCors       | Мухобойка для CORS.                                            |
| session  | Neo\Network\Session\Middleware\StartSession             | Читает session id из куки, поднимает сессию из хранилища.      |
| errors   | Neo\Presentation\View\Middleware\ShareErrorsFromSession | Отправить все валидационные ошибки вместе с ответом            |
| csrf     | Neo\Network\Http\Foundation\Middleware\VerifyCsrfToken  | Для POST/PUT/DELETE требует токен, привязанный к сессии        |
| throttle | Neo\Network\Http\Routing\Middleware\ThrottleRequests    | Rate limit по ключу (IP, юзер)                                 |
| auth     | Neo\Security\Authentication\Middleware\Authenticate     | Аутентификация пользователя в системе                          |
| can      | Neo\Security\Authorization\Middleware\Authorize         | Мост к Gate и Policy: `->middleware('can:update,post')`.       |


Порядок загрузки строго регламентирован во избежания сбоев. У каждой middleware 
есть приоритет загрузки. В таблице указан строгий порядок загрузки.

# Request

Запрос, который прилитает от клиента. Содержит в себе полезную информацию и его 
данные, которые он ввёл или передал. 

#### Пример использования в контроллере

```php 
class UserController extends Controller
{
    public function store(Request $request): ViewResponse
    {
        $name = $request->input('name');
 
        return view('/users', ['name' => $name]);
    }
}
```

#### Пример использования для валидации

```php 
class TestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'unique:posts', 'max:255'],
            'body' => ['required'],
        ];
    }
}
```

Нужен чтобы вынести валидацию входящего трафика из контроллера.

# Response (HTTP)

#### Пример ответа:

```php 
response()->json([1,2,3]);
```

#### Пример ответа с куками 

```php 
return response('Hello World')->cookie(
    'name', 'value', $minutes
);
```

Установит в браузере пользователя куку ограниченную по времени.

#### Доступные ответы

| Тип                  | Значение                                               |
|----------------------|--------------------------------------------------------|
| ViewResponse         | Возвращает отрендеренный HTML текст с заголовком HTML. |
| JSONResponse         | Возвращает JSON текст с заголовком json                |
| FileDownloadResponse | По запрошенному пути заставляет браузер скачать файл   |
| FileResponse         | Возвращает файл браузеру с его MIME, например PDF      |
| RedirectResponse     | Возвращает заголовок 302                               |
| StreamResponse       | Респонс будет по спец.протоколу стриминга              |
| EventStreamResponse  | Адаптер для StreamResponse                             |

# Controller

HTTP-адаптер: принял Request, вызвал сценарий и превратил результат в Response.

# HttpException

Форма ответа определяется по реквесту, глядя на accept.


# Реализация

Раздел описывает то, что уже написано. Остальной документ остаётся проектным описанием.

## Запуск

| Среда                           | Как запускается                                                                   | Что нужно                   |
|---------------------------------|-----------------------------------------------------------------------------------|-----------------------------|
| FPM / Apache / `php -S`         | `public/index.php` → `Bootstrap::run()` → `Kernel::run()` → `HttpKernel::serve()` | ничего, расширения не нужны |
| Сервер для разработки           | `php cli serve` (встроенный сервер PHP, статика отдаётся сервером)                | ничего                      |
| Swoole (опционально, ускорение) | `php cli serve --swoole` → `Neo\Network\Http\Server\SwooleServer`                 | расширение `swoole`         |

Движок целиком работает без Swoole. Класс `SwooleServer` подключается только командой `serve --swoole`.
`Kernel` про HTTP ничего не знает: HTTP-модуль вешает свой обработчик через `Kernel::runUsing()`.
Будущие серверы (UDP для STUN/TURN) живут рядом: `Neo\Network\Udp\Server`.

## Безопасность в долгоживущем процессе (Swoole)

* Singleton-сервисы (Router, MiddlewareRegistry, CookieManager, SessionManager, UrlGenerator...) не хранят данные запроса в свойствах: всё лежит в `RequestContext` (корутина Swoole / Fiber / обычный процесс определяются автоматически).
* `Route` после загрузки не меняется. Параметры запроса живут в копии маршрута (`Route::bound()`), её хранит `Request`.
* В контейнере есть `scoped()`: один экземпляр на запрос. `Request` и `RequestContract` зарегистрированы как scoped.
* Контроллер, middleware и FormRequest создаются заново на каждый запрос.
* Корутинные хуки ввода-вывода выключены по умолчанию (`hook_flags = 0`): слой БД не рассчитан на одно соединение PDO в нескольких корутинах.
* Swoole-адаптер написан по документации и **не проверялся** (в среде разработки нет расширения). Всё остальное проверено тестами и живым запуском через `php -S`.

## Жизненный цикл (как работает сейчас)

```
Request::capture()                          (SAPI)  или Swoole-запрос → Request
  → RequestContext::enter(request, pending response)
  → глобальные middleware: TrustProxies, TrustHosts, HandleCors, SecurityHeaders,
                           ValidatePostSize, TrimStrings, ConvertEmptyStringsToNull
  → маршрутизация (404 / 405 / OPTIONS)
  → маршрутные middleware (группа web или api, свои)
  → ParameterResolver: Request, FormRequest (проверка), параметры, модели, enum, сервисы
  → контроллер / замыкание → Responsable | Response | array | string → Response
  → обратно через middleware → prepare() → отправка → terminate()
```

Исключение внутри любого слоя превращается в `Response` на месте (HTTP-конвейер), поэтому внешние слои
(сессия, куки, CORS, заголовки безопасности) всегда успевают отработать: ошибки валидации сохраняются
в сессию, 404 получает заголовки безопасности.

## Встроенные middleware

| Псевдоним          | Класс                                               | Где по умолчанию              |
|--------------------|-----------------------------------------------------|-------------------------------|
| `proxies`          | `Neo\Security\Middleware\TrustProxies`              | глобальное                    |
| `hosts`            | `Neo\Security\Middleware\TrustHosts`                | глобальное                    |
| `cors`             | `Neo\Network\Http\Foundation\Middleware\HandleCors` | глобальное                    |
| `security.headers` | `Neo\Security\Middleware\SecurityHeaders`           | глобальное                    |
| `postsize`         | `...\Foundation\Middleware\ValidatePostSize`        | глобальное                    |
| `trim`, `nulls`    | `TrimStrings`, `ConvertEmptyStringsToNull`          | глобальные                    |
| `cookies`          | `Neo\Network\Cookie\Middleware\EncryptCookies`      | группа `web`                  |
| `cookies.queue`    | `...\Cookie\Middleware\AddQueuedCookiesToResponse`  | группа `web`                  |
| `session`          | `Neo\Network\Session\Middleware\StartSession`       | группа `web`                  |
| `csrf`             | `...\Foundation\Middleware\VerifyCsrfToken`         | группа `web`                  |
| `bindings`         | `...\Routing\Middleware\SubstituteBindings`         | группы `web`, `api`           |
| `throttle`         | `...\Routing\Middleware\ThrottleRequests`           | группа `api` (`throttle:api`) |
| `signed`           | `...\Routing\Middleware\ValidateSignature`          | по имени                      |

`auth` и `can` относятся к модулю авторизации, `errors` (ShareErrorsFromSession) 
к Presentation. Они появятся вместе с модулями. Любое незарегистрированное имя 
middleware **даёт ошибку**, а не пропускается молча.

Свои middleware: `Middleware::register('auth', Authenticate::class)`, группы и порядок: `app/Config/Http.php`.

## Защиты

| Угроза                                | Что сделано                                                                                                                                                                              |
|---------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| CSRF                                  | токен привязан к сессии (HMAC + срок), `_token` / `X-CSRF-TOKEN` / `X-XSRF-TOKEN`, проверка заголовка `Origin`, подмена метода только `POST → PUT/PATCH/DELETE`                          |
| XSS                                   | `e()`, `Escaper::html/attr/js/url/safeUrl`, CSP с nonce на запрос (`csp_nonce()`), HttpOnly-куки, `nosniff`, опасные файлы (HTML, SVG) отдаются как скачивание                           |
| Подделка IP, схемы, хоста             | `X-Forwarded-*` учитываются только от доверенных прокси (IP, CIDR), IP берётся справа налево; Host проверяется по формату и по белому списку                                             |
| Header injection / response splitting | CR, LF, NUL запрещены в именах и значениях заголовков, куки, `Location`                                                                                                                  |
| Открытый редирект                     | `back()` принимает Referer только со своего хоста, редирект запрещает схемы кроме http(s)                                                                                                |
| Кража и подмена кук                   | AES-GCM, шифртекст привязан к имени куки, подделанные куки отбрасываются; `Secure` автоматически по схеме запроса; `__Host-`/`__Secure-` проверяются; `SameSite=None` форсирует `Secure` |
| Перебор                               | `throttle` (по IP или пользователю, именованные лимитеры), `Retry-After`, 429                                                                                                            |
| Подмена ссылок                        | подписанные URL (`URL::signedRoute`, `temporarySignedRoute`, middleware `signed`)                                                                                                        |
| Большие тела                          | `ValidatePostSize` (413), JSON с лимитом глубины, битый JSON даёт 400                                                                                                                    |
| Утечки через ошибки                   | в боевом режиме 5xx отдают `Server Error`, подробности только при `app.debug`                                                                                                            |
| CORS                                  | белый список Origin, `*` не работает вместе с куками, `Vary: Origin`, preflight до маршрутизации                                                                                         |

## Что стоит знать

* Для боевого сайта заполните `security.trusted_hosts` и, если стоите за прокси, `security.proxies` (`app/Config/Security.php`).
* Файловый кэш не атомарен между процессами: лимиты `throttle` могут чуть недосчитывать при гонке. Для строгих лимитов (вход, SMS) нужен атомарный стор.
* Группа `web` по умолчанию требует рабочую сессию. Для драйвера `database` нужна таблица `sessions`: миграция лежит в `app/Database/Migrations/Sessions.php` (`php cli migrate`). Без неё первая же страница вернёт 500 (или поставьте `SESSION_DRIVER=file`).
* `with()`, `withInput()`, `withErrors()` у редиректа работают только в маршрутах с `StartSession`, иначе выбрасывается понятная ошибка, а не теряются данные.
* Тесты: `php cli test neo/Network/Http`, `neo/Security`, `neo/Information/Structures`. Для проверки маршрутов без сети есть `Neo\Network\Http\Testing\TestClient`.
* Список маршрутов: `php cli route:list [--name=] [--path=]`.

## Связь с шаблонизатором (Presentation/View)

* `view('home')` (хелпер модуля View) возвращает строку, роутер превращает её в HTML-ответ. Если нужен статус или заголовки: `response()->view('home', $data, 201)` или `Route::view('/', 'home')`: это `ViewResponse`, он рисует шаблон через `ViewRendererContract` (`Infrastructure/HttpViewRenderer`).
* `@csrf` берёт токен из `CsrfTokenManager` (`Infrastructure/CsrfDirectiveHandler`): он привязан к сессии, именно его проверяет `VerifyCsrfToken`. Случайный `Crypt::token()` проверка бы не приняла.
* `@method('PUT')` работает вместе с подменой метода в `Request` (подменяется только POST и только на PUT/PATCH/DELETE).
* `@error('поле')` получает `$errors` в каждом шаблоне: `ViewManager::shareUsing()` отдаёт ошибки из flash-сессии ТЕКУЩЕГО запроса (функция вызывается на каждый рендер, общий синглтон ничего не запоминает).
* `ValidationException` теперь содержит в сообщении список ошибок для разработчика. Клиенту уходит только первая строка (`message`), ошибки отдельным полем `errors`.
