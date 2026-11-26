<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Models;

use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Model;

class BenchPost extends Model
{
    protected ?string $table = 'bench_posts';

    #[PrimaryKey]
    public int $id;

    public int $userId;

    public string $title;

    public string $body;

    public string $status;

    public int $views;

    public int $score;

    public ?string $publishedAt;

    public ?string $createdAt;

    public ?string $updatedAt;
}
