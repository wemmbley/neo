<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use InvalidArgumentException;
use JsonSerializable;

/** JSON-ответ. По умолчанию `<`, `>`, `&` и кавычки кодируются в \uXXXX (безопасно при вставке в HTML). */
class JsonResponse extends Response
{
    public const int DEFAULT_ENCODING_OPTIONS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

    protected mixed $data = null;

    protected int $encodingOptions;

    /**
     * @param bool $json true, если $data уже готовая JSON-строка
     */
    public function __construct(
        mixed $data = null,
        int $status = 200,
        array $headers = [],
        int $options = 0,
        bool $json = false,
    ) {
        parent::__construct('', $status, $headers);

        $this->encodingOptions = $options === 0 ? self::DEFAULT_ENCODING_OPTIONS : $options;

        if ($json && is_string($data)) {
            json_decode($data, false, 512, JSON_THROW_ON_ERROR);
            $this->data = $data;
            $this->content = $data;
            $this->headers->set('Content-Type', 'application/json');

            return;
        }

        $this->setData($data ?? new \ArrayObject());
    }

    public function setData(mixed $data = []): static
    {
        if (is_object($data) && !$data instanceof JsonSerializable && method_exists($data, 'toArray')) {
            $data = $data->toArray();
        }

        try {
            $this->content = json_encode($data, $this->encodingOptions | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Не удалось закодировать JSON: ' . $e->getMessage(), 0, $e);
        }

        $this->data = $data;
        $this->headers->set('Content-Type', 'application/json');

        return $this;
    }

    public function getData(bool $assoc = false): mixed
    {
        return json_decode($this->content, $assoc);
    }

    public function setEncodingOptions(int $options): static
    {
        $this->encodingOptions = $options;

        return $this->setData($this->data);
    }
}
