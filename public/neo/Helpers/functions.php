<?php

use App\Neo\Helpers\FileSystem\File;
use App\Neo\Helpers\FileSystem\Path;
use App\Neo\Helpers\Primitives\Str;
use App\Neo\Helpers\Regex;
use App\Neo\Http\Request;

/*
|--------------------------------------------------------------------------
| Dynamic classes.
|--------------------------------------------------------------------------
*/
function create_class(string $class, ...$params)
{
    if( ! class_exists($class)) {
        throw new \Exception(sprintf('Class %s not found.', $class));
    }

    return new $class(...$params);
}
function call_method($object, string $method, ...$params): void
{
    if( ! method_exists($object, $method)) {
        throw new Exception(sprintf('Method %s not found in object %s.', $method, get_class($object)));
    }

    $object->$method(...$params);
}

/*
|--------------------------------------------------------------------------
| Get .env value.
|--------------------------------------------------------------------------
*/
function env(string $param)
{
    if ( ! File::exists(Path::abs('/.env'))) {
        throw new Exception('.env not found');
    }

    $envBody = File::get(Path::abs('/.env'));
    $envRegex = Regex::matchAll('/(\w+)=(\w+.+|)/')->string($envBody);

    foreach ($envRegex[0] as $envParam) {
        [$envKey, $envVal] = explode('=', $envParam);

        if ($param === $envKey)
            return $envVal;
    }

    throw new Exception(sprintf('Param %s not found in .env', $param));
}

/*
|--------------------------------------------------------------------------
| Get config value from file in app/Config.
|--------------------------------------------------------------------------
|
| Example:
| config('database.fetch');
|
| Here we got "fetch" value from file app/Config/database.php
|
*/
function config(string $path)
{
    $path = Str::split($path, '.');
    $configPath = Path::abs('/app/Config/' . $path[0] . '.php');

    if ( ! File::exists($configPath)) {
        throw new Exception('Config file not found.');
    }

    $config = require_once $configPath;

    if ( ! array_key_exists($path[1], $config)) {
        throw new Exception('Config key not found.');
    }

    return $config[$path[1]];
}

/*
|--------------------------------------------------------------------------
| Get resources URL.
|--------------------------------------------------------------------------
*/
function asset(string $path)
{
    return Request::protocol() . Request::server('HTTP_HOST') . '/resources/' . $path;
}