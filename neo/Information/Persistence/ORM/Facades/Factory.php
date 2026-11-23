<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Facades;

use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Information\Persistence\ORM\Factories\FactoryManager;

/**
 * @method static \Neo\Information\Persistence\ORM\Factories\Factory make(string $model)
 * @method static void register(string $model, string $factory)
 *
 * @see FactoryManager
 */
#[FacadeOf(FactoryManager::class)]
final class Factory extends Facade {}