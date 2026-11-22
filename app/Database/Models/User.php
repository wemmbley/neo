<?php

namespace App\Database\Models;

use Neo\Information\Persistence\ORM\Attributes\Hidden;
use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;

class User
{
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