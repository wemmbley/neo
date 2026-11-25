<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Models;

use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Model;

class BenchEvent extends Model
{
    protected ?string $table = 'bench_events';

    protected bool $timestamps = false;

    #[PrimaryKey]
    public int $id;

    public string $channel;

    public string $payload;

    public ?string $createdAt;
}
