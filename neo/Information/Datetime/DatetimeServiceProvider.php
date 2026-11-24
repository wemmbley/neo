<?php

declare(strict_types=1);

namespace Neo\Information\Datetime;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Foundation\Runtime\Lifecycle;

/**
 * Chronos держит настройки в статических свойствах (локаль, начало недели, выходные, test-now).
 * В обычном PHP процесс живёт один запрос, и это безопасно. В долгоживущем воркере провайдер
 * запоминает состояние после запуска приложения и возвращает его после каждого запроса.
 *
 * Правило для кода приложения: глобальные настройки Chronos задаются при запуске, а на запрос
 * локаль выбирается у экземпляра, а не через `Chronos::setLocale()`.
 */
class DatetimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var array{locale: string, weekStartsAt: int, weekendDays: list<int>}|null $baseline */
        $baseline = null;

        # Снимок делаем на старте воркера: к этому моменту все провайдеры уже загружены и настройки заданы.
        $this->app->make(Lifecycle::class)
            ->onWorkerStart(static function () use (&$baseline): void {
                $baseline = Chronos::snapshot();
            })
            ->resettable(static function () use (&$baseline): void {
                if ($baseline !== null) {
                    Chronos::restore($baseline);
                }
            });
    }
}
