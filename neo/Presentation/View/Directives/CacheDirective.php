<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * @cache('key', 600) ... @endcache — кэширует отрендеренный кусок через Neo\Cache\Cache
 * (ttl в секундах, по умолчанию cache.lifetime). Работает при cache.strategy = 'directive';
 * при 'full' кэшируется шаблон целиком, и директива ничего не делает.
 */
class CacheDirective extends BasicDirective
{
    public string $directive = '@cache';
    public ?string $directiveEnd = '@endcache';

    public function render(string $expression): string
    {
        return $this->php("if (!\$this->cacheBegin({$expression})):");
    }

    public function close(): ?string
    {
        return $this->php('$this->cacheEnd(); endif;');
    }
}
