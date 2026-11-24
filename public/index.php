<?php

declare(strict_types=1);

use app\Bootstrap\Bootstrap;

#
# Задаём рут, dirname выходит на папку выше по умолчанию.
#
$root = dirname(__DIR__);

#
# Подключаем базовый конфиг бутстрапа.
#
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Bootstrap.php';

#
# Первый класс подключаем руками, дальше работает автозагрузка.
#
require_once $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Bootstrap' . DIRECTORY_SEPARATOR . 'Bootstrap.php';

#
# Загружаем приложение.
#
$bootstrap = new Bootstrap($config);
$bootstrap->run();