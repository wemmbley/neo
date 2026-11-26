<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Transfer;

use Closure;
use Neo\Information\Markdown\Contracts\SyntaxContract;
use Neo\Transfer\Transfer;
use Neo\Validator\Rule;

class MarkdownTransfer extends Transfer
{
    public const FORMAT_VIEW = 'view';
    public const FORMAT_COLLECTION = 'collection';
    public const FORMAT_ARRAY = 'array';

    # Формат вывода: view, collection, array.
    #
    public string $format = self::FORMAT_VIEW;

    # Режимы вывода.
    #
    public ModesTransfer $modes;

    # Кэширование.
    #
    public CacheTransfer $cache;

    # Дополнительные синтаксисы к встроенным. Две формы записи:
    #
    #   VideoSyntax::class,                                   — всё по умолчанию
    #   HeaderOneSyntax::class => [                           — своё
    #       'transfer' => MyHeaderTransfer::class,               (необязательно)
    #       'template' => '/abs/path/h1.blade.php',              (необязательно)
    #   ],
    #
    # Синтаксис с тем же name(), что и у встроенного, заменяет его.
    #
    # @var array<int|class-string, class-string|array{transfer?: class-string, template?: string}>
    #
    public array $syntax = [];

    public function validateFormat(Rule $rule): Rule
    {
        return $rule
            ->required()
            ->string()
            ->in([self::FORMAT_VIEW, self::FORMAT_COLLECTION, self::FORMAT_ARRAY]);
    }

    public function validateSyntax(Rule $rule): Rule
    {
        return $rule
            ->array()
            ->custom(function (string $attribute, mixed $value, Closure $fail): void {
                foreach ($value as $key => $item) {
                    [$class, $options] = is_int($key) ? [$item, []] : [$key, $item];

                    if (!is_string($class) || !is_subclass_of($class, SyntaxContract::class)) {
                        $fail('Поле :attribute: ' . (is_string($class) ? $class : gettype($class)) . ' должен реализовывать ' . SyntaxContract::class);

                        return;
                    }

                    if (!is_array($options) || array_diff(array_keys($options), ['transfer', 'template']) !== []) {
                        $fail('Поле :attribute: настройки ' . $class . ' допускают только ключи transfer и template');

                        return;
                    }

                    if (isset($options['transfer']) && !is_subclass_of($options['transfer'], Transfer::class)) {
                        $fail('Поле :attribute: transfer синтаксиса ' . $class . ' должен наследовать ' . Transfer::class);

                        return;
                    }

                    if (isset($options['template']) && !is_file((string) $options['template'])) {
                        $fail('Поле :attribute: шаблон синтаксиса ' . $class . ' не найден: ' . $options['template']);

                        return;
                    }
                }
            });
    }
}
