<?php

namespace Neo\Security\Crypt;

use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;

#[FacadeOf(CryptManager::class)]
class Crypt extends Facade {}