<?php

namespace Neo\Logger;

use Neo\Kernel\Facade;
use Neo\Kernel\FacadeOf;
use Neo\Logger\Managers\LogManager;

#[FacadeOf(LogManager::class)]
class Log extends Facade {}