<?php

use App\Neo\Helpers\FileSystem\File;
use App\Neo\Helpers\FileSystem\Path;
use App\Neo\Helpers\Regex;

/*
|--------------------------------------------------------------------------
| Dynamic classes
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
| Get .env value
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