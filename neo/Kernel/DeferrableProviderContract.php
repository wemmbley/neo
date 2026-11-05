<?php

declare(strict_types=1);

namespace Neo\Kernel;

/**
 * Ленивый провайдер.
 *
 * Если ServiceProvider реализует этот интерфейс и регистрируется
 * именем класса, он не создаётся при старте приложения. Контейнер
 * подгрузит его (register() + boot()) при первом запросе
 * любого сервиса из provides().
 *
 * Метод статический: список читается без создания провайдера.
 *
 * Пример:
 *
 *   final class CacheServiceProvider extends ServiceProvider implements DeferrableProviderContract
 *   {
 *       public static function provides(): array
 *       {
 *           return [CacheContract::class];
 *       }
 *
 *       public function register(): void
 *       {
 *           $this->app->singleton(CacheContract::class, FileCache::class);
 *       }
 *   }
 *
 * Важно: register() обязан привязать ВСЕ перечисленные сервисы,
 * а список provides() не должен включать то, что провайдер
 * не привязывает: иначе запрос такого сервиса просто загрузит
 * провайдера впустую.
 */
interface DeferrableProviderContract
{
    /**
     * @return list<string> Какие сервисы (abstract) привязывает провайдер.
     */
    public static function provides(): array;
}