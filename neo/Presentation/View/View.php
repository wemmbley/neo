<?php

namespace Neo\Presentation\View;

use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Presentation\View\Directives\BasicDirective;

/**
 * @method static string render(string $view, array $data = [])
 * @method static string fragment(string $view, string $fragment, array $data = [])
 * @methid static bool exists(string $view)
 * @method static static directive(BasicDirective $directive)
 * @method static static shareUsing(\Closure $resolver)
 * @method static void flush()
 */
#[FacadeOf(ViewManager::class)]
class View extends Facade {}