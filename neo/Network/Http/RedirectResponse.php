<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use InvalidArgumentException;
use LogicException;
use Neo\Network\Session\SessionManager;
use Neo\Security\Xss\Escaper;

/** Редирект (по умолчанию 302). */
class RedirectResponse extends Response
{
    protected string $targetUrl = '';

    protected ?SessionManager $sessionStore = null;

    protected ?Request $originalRequest = null;

    public function __construct(string $url, int $status = 302, array $headers = [])
    {
        parent::__construct('', $status, $headers);

        $this->setTargetUrl($url);

        if (!$this->isRedirect()) {
            throw new InvalidArgumentException("Статус [{$status}] не является статусом редиректа.");
        }
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    /**
     * Схемы кроме http(s) запрещены: `javascript:` и `data:` в Location не должны попадать в ответ.
     * CR/LF в адресе отклоняет HeaderBag (response splitting).
     */
    public function setTargetUrl(string $url): static
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('URL редиректа не может быть пустым.');
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '', $m) === 1
            && !in_array(strtolower($m[1]), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('Редирект на схему [' . $m[1] . '] запрещён.');
        }

        $this->targetUrl = $url;
        $this->headers->set('Location', $url);

        $safe = Escaper::attr($url);
        $this->setContent(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url=\'' . $safe . '\'">'
            . '<title>Redirecting to ' . Escaper::html($url) . '</title></head>'
            . '<body>Redirecting to <a href="' . $safe . '">' . Escaper::html($url) . '</a>.</body></html>'
        );

        return $this;
    }

    /** Запрос и его сессия: Redirector и Handler подставляют их сами. */
    public function setRequest(Request $request): static
    {
        $this->originalRequest = $request;

        if ($request->hasSession()) {
            $this->sessionStore = $request->session();
        }

        return $this;
    }

    /** Кладёт значение в flash-сессию (доступно только на следующем запросе). */
    public function with(string|array $key, mixed $value = null): static
    {
        $this->session()->flash($key, $value);

        return $this;
    }

    /** Запоминает ввод формы для old(). Пароли и файлы не сохраняются. */
    public function withInput(?array $input = null): static
    {
        $input ??= $this->originalRequest?->except(['password', 'password_confirmation', '_token']) ?? [];

        $this->session()->flash('_old_input', $input);

        return $this;
    }

    /**
     * Ошибки валидации: `$errors` = `['поле' => ['сообщение']]`, значения доступны как session('errors').
     */
    public function withErrors(mixed $provider, string $key = 'default'): static
    {
        $errors = match (true) {
            $provider instanceof \Neo\Information\Validator\ValidationException => $provider->errors(),
            $provider instanceof \Neo\Information\Validator\Validator => $provider->errors(),
            is_array($provider) => $provider,
            default => ['_error' => [(string) $provider]],
        };

        $this->session()->flash($key === 'default' ? 'errors' : 'errors_' . $key, $errors);

        return $this;
    }

    /**
     * Без сессии flash-данные потерялись бы молча, поэтому это ошибка: with()/withInput()/withErrors()
     * работают только в маршрутах с middleware StartSession (группа web).
     */
    protected function session(): SessionManager
    {
        return $this->sessionStore
            ?? throw new LogicException('Для with()/withInput()/withErrors() нужна сессия: используйте группу middleware web.');
    }
}
