<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Attributes;

use Attribute;

/**
 * Привязывает политику к модели прямо на классе модели:
 *
 *   #[UsePolicy(PostPolicy::class)]
 *   class Post extends Model {}
 *
 * Без атрибута политика ищется по соглашению: App\Database\Models\Post => App\Policies\PostPolicy
 * (пространство имён задаётся в app/Config/Auth.php), или регистрируется вручную: Gate::policy().
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class UsePolicy
{
    /** @param class-string $policy */
    public function __construct(public readonly string $policy) {}
}
