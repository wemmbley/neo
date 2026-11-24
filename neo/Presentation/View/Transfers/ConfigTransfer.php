<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Transfers;

use Closure;
use Neo\Information\Transfer\Transfer;
use Neo\Information\Validator\Rule;

/**
 * Корневой конфиг движка шаблонов.
 *
 * $config = new ConfigTransfer($rawConfig);
 *
 * Ключ 'basic_path' ложится в $basicPath. Массивы 'cache' и 'compiler'
 * собираются во вложенные CacheTransfer и CompilerTransfer со своей валидацией.
 *
 * Для debug метода validate нет намеренно: достаточно типа bool.
 * Для components тоже: пока это заготовка на будущее.
 */
class ConfigTransfer extends Transfer
{
    public string $basicPath; // без значения: обязательное поле
    public array $resources = [];
    public array $namespaces = [];
    public CacheTransfer $cache;
    public bool $debug = false;
    public array $extensions = ['.blade.php'];
    public string $encoding = 'UTF-8';
    public array $components = [];
    public CompilerTransfer $compiler;

    /**
     * Базовая папка с шаблонами: должна существовать.
     */
    public function validateBasicPath(Rule $rule): Rule
    {
        return $rule->string()->notEmpty()->directory();
    }

    /**
     * ['имя' => '/путь/к/папке']. Имя пространства — идентификатор,
     * путь — существующая директория.
     */
    public function validateNamespaces(Rule $rule): Rule
    {
        return $rule->array()->associative()
            ->keys(fn (Rule $k) => $k->string()->regex('/^[a-z][a-z0-9_-]*$/i'))
            ->values(fn (Rule $v) => $v->string()->notEmpty()->directory());
    }

    /**
     * ['.blade.php', '.php']: непустой список расширений, каждое начинается с точки.
     */
    public function validateExtensions(Rule $rule): Rule
    {
        return $rule->array()->list()->min(1)
            ->values(fn (Rule $v) => $v->string()->regex('/^\.[\w.]+$/'));
    }

    /**
     * Кодировка должна быть известна mbstring.
     */
    public function validateEncoding(Rule $rule): Rule
    {
        return $rule->string()->custom(function (string $attribute, string $value, Closure $fail): void {
            $known = array_map('strtoupper', mb_list_encodings());

            if (!in_array(strtoupper($value), $known, true)) {
                $fail(":attribute: неизвестная кодировка «{$value}»");
            }
        });
    }
}
