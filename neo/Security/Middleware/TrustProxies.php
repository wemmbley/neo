<?php

declare(strict_types=1);

namespace Neo\Security\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/**
 * Сообщает запросу, каким прокси (балансировщикам, CDN) можно верить заголовкам
 * X-Forwarded-For/Proto/Host/Port. Список в `security.proxies`: IP, подсети (CIDR) или `*`.
 *
 * По умолчанию список пуст: заголовки X-Forwarded-* игнорируются, и IP клиента равен адресу
 * соединения. Включайте `*` только если сервер недоступен напрямую, иначе любой клиент
 * сможет подделать свой IP и обойти ограничение частоты.
 */
final class TrustProxies
{
    public function handle(Request $request, Closure $next): Response
    {
        $proxies = config('security.proxies', []);

        $request->setTrustedProxies(is_string($proxies) ? array_map('trim', explode(',', $proxies)) : array_values((array) $proxies));

        return $next($request);
    }
}
