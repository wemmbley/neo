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