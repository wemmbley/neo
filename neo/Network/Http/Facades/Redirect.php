<?php

declare(strict_types=1);

namespace Neo\Network\Http\Facades;

use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Network\Http\Redirector;

#[FacadeOf(Redirector::class)]
class Redirect extends Facade {}
