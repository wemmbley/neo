<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use LogicException;
use Neo\Foundation\Kernel\Kernel;
use Neo\Network\Http\Contracts\ViewRendererContract;

/**
 * HTML из шаблона. Тело рисуется лениво при отправке через ViewRendererContract,
 * который привязывает модуль Presentation. Пока его нет, ViewResponse можно создать,
 * но нельзя отправить.
 */
class ViewResponse extends Response
{
    private bool $rendered = false;

    /** @param array<string, mixed> $data */
    public function __construct(
        protected readonly string $view,
        protected readonly array $data = [],
        int $status = 200,
        array $headers = [],
    ) {
        parent::__construct('', $status, $headers);

        $this->headers->set('Content-Type', 'text/html; charset=UTF-8');
    }

    public function view(): string
    {
        return $this->view;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function getContent(): string
    {
        if (!$this->rendered) {
            $kernel = Kernel::getInstance();

            if (!$kernel->has(ViewRendererContract::class)) {
                throw new LogicException(
                    'Шаблонизатор не подключён: привяжите ' . ViewRendererContract::class . ' (модуль Presentation).'
                );
            }

            $this->content = $kernel->make(ViewRendererContract::class)->render($this->view, $this->data);
            $this->rendered = true;
        }

        return $this->content;
    }
}
