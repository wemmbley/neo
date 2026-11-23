<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel\Events;

use Neo\Foundation\Kernel\ServiceProvider;

/**
 * Регистрирует Dispatcher. Слушатели берутся из app/Config/events.php:
 *   'listen'    => [PriceChanged::class => [NotifyManager::class]],
 *   'subscribe' => [AuditSubscriber::class],
 */
class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Events.php'), 'events');

        $this->app->singleton(
            Dispatcher::class,
            fn () => new Dispatcher(fn (string $class) => $this->app->make($class)),
        );
    }

    public function boot(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);

        foreach ((array) config('events.listen', []) as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                $events->listen($event, $listener);
            }
        }

        foreach ((array) config('events.subscribe', []) as $subscriber) {
            $events->subscribe($subscriber);
        }
    }
}
