<?php

namespace App\Database\Models;

use App\Database\Factories\UserFactory;
use Neo\Information\Persistence\ORM\Attributes\Hidden;
use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Attributes\UseFactory;
use Neo\Information\Persistence\ORM\Model;
use Neo\Security\Authentication\Concerns\AuthenticatableTrait;
use Neo\Security\Authentication\Contracts\Authenticatable;
use Neo\Security\Authorization\Concerns\HasRoles;
use Neo\Security\Authorization\Gate\Authorizable;

#[UseFactory(UserFactory::class)]
class User extends Model implements Authenticatable
{
    use AuthenticatableTrait, Authorizable, HasRoles;

    #[PrimaryKey]
    public int $id;

    public string $email;

    #[Hidden]
    public string $password;

    public ?string $emailVerifiedAt;

    public ?string $rememberToken;

    public ?string $createdAt;

    public ?string $updatedAt;

}