<?php

require_once dirname(__DIR__) . '/bootstrap/Bootstrap.php';

use App\Services\MyService;
use Bootstrap\Bootstrap;

$bootstrap = new Bootstrap();
$bootstrap->boot();

app(MyService::class)->handle();

$container = app()->getContainer();

$resolver = $container->getResolver();

profiler();