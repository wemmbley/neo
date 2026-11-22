<?php

namespace Neo\Information\Persistence\Storage\Contracts\ShouldProvide;

interface ShouldProvideLocalPathContract
{
    public function path(string $path): string; // абсолютный путь (только local)
}