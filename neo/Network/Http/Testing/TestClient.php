<?php

declare(strict_types=1);

namespace Neo\Network\Http\Testing;

use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Information\Cache\Managers\CacheManager;
use Neo\Information\Cache\Transfers\CacheTransfer;
use Neo\Network\Cookie\CookieManager;
use Neo\Network\Cookie\Middleware\AddQueuedCookiesToResponse;
use Neo\Network\Cookie\Middleware\EncryptCookies;
use Neo\Network\Http\Context\RequestContext;
use Neo\Network\Http\Contracts\ExceptionHandlerContract;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Exceptions\Handler;
use Neo\Network\Http\Foundation\Middleware\VerifyCsrfToken;
use Neo\Network\Http\HttpKernel;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\Routing\MiddlewareRegistry;
use Neo\Network\Http\Routing\Router;
use Neo\Network\Http\Routing\UrlGenerator;
use Neo\Network\Session\Drivers\ArraySessionDriver;
use Neo\Network\Session\Middleware\StartSession;
use Neo\Network\Session\SessionManager;
use Neo\Security\Crypt\CryptManager;
use Neo\Security\Csrf\CsrfTokenManager;
use Neo\Security\RateLimiter\RateLimiter;
use Throwable;

/**
 * Тестовый HTTP-клиент: собирает ИЗОЛИРОВАННЫЙ стек (свой контейнер, роутер, контекст,
 * массивные сессия и кэш, фиксированный ключ шифрования) и прогоняет запросы через
 * настоящее HttpKernel, без сети и без глобального состояния приложения.
 *
 *   $client = new TestClient();
 *   $client->router->get('/hello', fn () => 'hi');
 *   $client->get('/hello')->getContent();   // 'hi'
 *
 * Хранит «банку кук»: куки из ответов автоматически уходят в следующий запрос.
 */
final class TestClient
{
    public readonly ServiceContainer $container;

    public readonly RequestContext $context;

    public readonly MiddlewareRegistry $registry;

    public readonly Router $router;

    public readonly HttpKernel $kernel;

    public readonly CryptManager $crypt;

    public readonly SessionManager $session;

    public readonly CsrfTokenManager $csrf;

    public readonly RateLimiter $limiter;

    public readonly UrlGenerator $url;

    /** Обработчик, который не пишет в лог, а копит исключения. */
    public readonly Handler $handler;

    /** @var array<string, string> */
    public array $jar = [];

    public function __construct()
    {
        $this->container = new ServiceContainer();
        $this->context = new RequestContext();
        $this->container->useScope($this->context);
        $this->container->instance(ServiceContainer::class, $this->container);
        $this->container->instance(RequestContextContract::class, $this->context);

        $this->crypt = new CryptManager(str_repeat('k', 64));
        $this->container->instance(CryptManager::class, $this->crypt);

        $cookies = new CookieManager(['path' => '/', 'http_only' => true, 'same_site' => 'lax'], $this->context);
        $this->session = new SessionManager(
            ['cookie' => 'neo_session', 'lifetime' => 120, 'path' => '/', 'same_site' => 'lax'],
            new ArraySessionDriver(7200),
            $cookies,
            $this->context,
        );
        $this->container->instance(CookieManager::class, $cookies);
        $this->container->instance(SessionManager::class, $this->session);

        $this->csrf = new CsrfTokenManager($this->crypt, $this->session, $this->context);
        $this->container->instance(CsrfTokenManager::class, $this->csrf);

        $cache = new CacheManager(new CacheTransfer([
            'default' => 'array',
            'prefix' => 'test',
            'stores' => ['array' => ['driver' => 'array', 'serialize' => true]],
        ]));
        $this->limiter = new RateLimiter($cache);
        $this->container->instance(RateLimiter::class, $this->limiter);

        $this->handler = new class($this->container) extends Handler {
            /** @var list<Throwable> */
            public array $reported = [];

            /** Тесты не зависят от APP_DEBUG окружения. */
            public bool $debugMode = false;

            protected function debug(): bool
            {
                return $this->debugMode;
            }

            public function report(Throwable $e): void
            {
                if (!$this->shouldntReport($e)) {
                    $this->reported[] = $e;
                }
            }
        };
        $this->container->instance(ExceptionHandlerContract::class, $this->handler);

        $this->registry = new MiddlewareRegistry();
        $this->router = new Router($this->container, $this->registry, $this->context);
        $this->container->instance(Router::class, $this->router);
        $this->url = new UrlGenerator($this->router, $this->context, $this->container);
        $this->container->instance(UrlGenerator::class, $this->url);

        $this->kernel = new HttpKernel($this->container, $this->router, $this->registry, $this->context, $this->handler);
    }

    /**
     * Стек middleware «как в группе web»: куки, сессия, CSRF.
     *
     * @return list<object>
     */
    public function webStack(): array
    {
        return [
            new EncryptCookies($this->crypt),
            new AddQueuedCookiesToResponse($this->context),
            new StartSession($this->session),
            new VerifyCsrfToken($this->csrf, $this->crypt),
        ];
    }

    /**
     * @param array<string, mixed> $parameters query для GET/HEAD, иначе поля формы
     * @param array<string, string> $headers
     */
    public function call(
        string $method,
        string $uri,
        array $parameters = [],
        array $headers = [],
        ?string $content = null,
        array $server = [],
    ): Response {
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create($uri, $method, $parameters, $this->jar, [], $server, $content);

        $response = null;
        $this->kernel->serve($request, static function (Response $r) use (&$response): void {
            $response = $r;
        });

        foreach ($response->cookies() as $cookie) {
            if ($cookie->value === '' || ($cookie->expires !== null && $cookie->expires < time())) {
                unset($this->jar[$cookie->name]);
            } else {
                $this->jar[$cookie->name] = $cookie->value;
            }
        }

        return $response;
    }

    public function get(string $uri, array $parameters = [], array $headers = []): Response
    {
        return $this->call('GET', $uri, $parameters, $headers);
    }

    public function post(string $uri, array $parameters = [], array $headers = []): Response
    {
        return $this->call('POST', $uri, $parameters, $headers);
    }

    /** @param array<string, mixed> $data */
    public function json(string $method, string $uri, array $data = [], array $headers = []): Response
    {
        return $this->call(
            $method,
            $uri,
            [],
            ['Content-Type' => 'application/json', 'Accept' => 'application/json', ...$headers],
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
