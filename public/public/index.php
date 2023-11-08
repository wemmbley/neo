<?php

use App\Neo\Database\DB;
use App\Neo\Router\Router;

ini_set('display_errors', '1');

require_once '../vendor/autoload.php';
require_once '../app/routes.php';

try {
    DB::boot();
    Router::boot();
}
catch (Exception $e)
{
    var_dump($e->getMessage());
    exit;
}
