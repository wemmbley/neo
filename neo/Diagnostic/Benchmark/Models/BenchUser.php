<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Models;

use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Model;

class BenchUser extends Model
{
    protected ?string $table = 'bench_users';

    #[PrimaryKey]
    public int $id;

    public string $name;

    public string $email;

    public string $status;

    public int $balance;

    public ?string $createdAt;

    public ?string $updatedAt;
}
