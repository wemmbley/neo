<?php

namespace App\Services;

class MyService
{
    public function __construct(
        private TestService $service
    ) {}

    public function handle()
    {
        echo $this->service->myHandler();
        dd(123);
    }
}