<?php

declare(strict_types=1);

use Neo\Network\Http\Facades\Route;

#
# Маршруты сайта. Группа middleware «web» (куки, сессия, CSRF)
# Подключена в app/Config/Http.php.
#

Route::get('/', fn () => view('Home'));

#
# Маршрут API-документации. Можно отключить.
#
Route::documentation('/docs/api');