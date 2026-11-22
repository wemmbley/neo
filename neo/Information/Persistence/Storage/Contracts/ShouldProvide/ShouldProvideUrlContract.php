<?php

namespace Neo\Information\Persistence\Storage\Contracts\ShouldProvide;

interface ShouldProvideUrlContract
{
    public function url(string $path): string;
}