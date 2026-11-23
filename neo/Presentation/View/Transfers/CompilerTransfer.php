<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Transfers;

use Closure;
use Neo\Information\Transfer\Transfer;
use Neo\Information\Validator\Rule;
use Neo\Presentation\View\Directives\BasicDirective;

/**
 * Настройки компилятора.
 *
 * $compiler = new CompilerTransfer($config['compiler']);
 *
 * Сразу после создания объекта свойства заполнены и провалидированы.
 * Ключ 'dependency_injection' автоматически ложится в $dependencyInjection.
 */
class CompilerTransfer extends Transfer
{
    private(set) array $brackets = ['{{', '}}'];
    private(set) array $directives = [];
    private(set) array $dependencyInjection = [];

    /**
     * Метод получает Rule, уже привязанный к значению поля.
     * На момент валидации все свойства объекта уже заполнены.
     * Возвращать нужно Rule (или true/false для ручной проверки).
     */
    public function validateBrackets(Rule $rule): Rule
    {
        // size(2) — ровно два элемента: открывающий и закрывающий.
        return $rule->array()->list()->size(2)
            ->values(fn(Rule $v) => $v->string()->notEmpty())
            ->custom(function (string $attribute, array $brackets, Closure $fail): void {
                if ($brackets[0] === $brackets[1]) {
                    $fail(':attribute: открывающий и закрывающий разделители не должны совпадать');
                }
            });
    }

    /**
     * Список классов директив: каждый — существующий наследник
     * BasicDirective.
     */
    public function validateDirectives(Rule $rule): Rule
    {
        return $rule->array()->list()
            ->values(fn(Rule $v) => $v->string()->classString()->instanceOf(BasicDirective::class));
    }

    /**
     * [
     *      Директива::class => [
     *          Контракт::class => Реализация::class
     *      ]
     * ]
     */
    public function validateDependencyInjection(Rule $rule): Rule
    {
        return $rule->array()->associative()
            ->keys(fn(Rule $k) => $k->string()->classString()->instanceOf(BasicDirective::class))
            ->values(fn(Rule $v) => $v->array()->associative()
                ->keys(fn(Rule $k) => $k->string()->classString())
                ->values(fn(Rule $c) => $c->string()->classString())
                ->custom(function (string $attribute, array $map, Closure $fail): void {
                    foreach ($map as $contract => $implementation) {
                        if (!is_a($implementation, $contract, true)) {
                            $fail(":attribute: {$implementation} не реализует {$contract}");
                        }
                    }
                }));
    }
}