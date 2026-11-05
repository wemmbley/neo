<?php

require_once dirname(__DIR__) . '/bootstrap/Bootstrap.php';

use App\Services\MyService;
use Bootstrap\Bootstrap;

try {

    $bootstrap = new Bootstrap();
    $bootstrap->boot();

    $isDisplayErrors = env('APP_DISPLAY_ERRORS', false);
    if($isDisplayErrors) {
        ini_set('display_errors', 1);
    }

    app(MyService::class)->handle();

    $container = app()->getContainer();

    $resolver = $container->getResolver();

    Storage::disk('local')->put('1.txt', 'hi!');

    Log::info('Пользователь {id} вошёл', ['id' => 5]); // подстановка плейсхолдера
    Log::stack(['single', 'daily'])->warning('x');
    Log::report(new RuntimeException('boom')); // во все каналы, сбои гасятся

} catch (\Throwable $exception) {
    throw $exception;
}