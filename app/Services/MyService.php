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
        dump(123);
    }
}