<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Models;

use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Model;

class BenchComment extends Model
{
    protected ?string $table = 'bench_comments';

    #[PrimaryKey]
    public int $id;

    public int $postId;

    public int $userId;

    public string $body;

    public ?string $createdAt;

    public ?string $updatedAt;
}
