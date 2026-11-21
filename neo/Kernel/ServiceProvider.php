<?php

declare(strict_types=1);

namespace Neo\Kernel;

/**
 * Сервис-провайдер: место, где пакет или часть приложения
 * настраивает себя.
 *
 * Сначала у всех провайдеров вызывается register()
 * (только привязки в контейнере, без обращения
 * к чужим сервисам), затем у всех boot() (здесь уже
 * можно пользоваться любыми сервисами).
 */
abstract class ServiceProvider
{
    #
    # Загружаем специфические методы доступные только в рамках Neo.
    #
    use NeoServiceProviderTrait;

    public function __construct(protected Kernel $app) {}

    #
    # Область биндинга привязок в контейнере.
    #
    public function register(): void {}

    #
    # Запускается после регистрации всех провайдеров.
    # Нужен для конфигурации подгружаемых сервисов.
    #
    public function boot(): void {}
}
