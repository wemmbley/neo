<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Models;

use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Model;

class BenchCounter extends Model
{
    protected ?string $table = 'bench_counters';

    protected bool $timestamps = false;

    #[PrimaryKey]
    public int $id;

    public string $name;

    public int $value;
}
