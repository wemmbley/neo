<?php

namespace Neo\Storage\Contracts\ShouldProvide;

interface ShouldProvideLocalPathContract
{
    public function path(string $path): string; // абсолютный путь (только local)
}