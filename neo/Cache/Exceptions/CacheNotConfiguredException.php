<?php

declare(strict_types=1);

namespace Neo\Cache\Exceptions;

/**
 * Неизвестное хранилище, драйвер или ошибка в конфигурации кэша.
 */
class CacheNotConfiguredException extends CacheException {}