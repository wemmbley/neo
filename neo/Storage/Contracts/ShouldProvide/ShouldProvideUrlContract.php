<?php

namespace Neo\Storage\Contracts\ShouldProvide;

interface ShouldProvideUrlContract
{
    public function url(string $path): string;
}