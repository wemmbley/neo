<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Exceptions;

/** Пул пуст: все ресурсы заняты, а ожидание закончилось по таймауту. */
final class PoolExhaustedException extends PoolException {}
