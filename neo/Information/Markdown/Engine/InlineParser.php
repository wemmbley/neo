<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Engine;

/**
 * Идёт по строке, на символах-триггерах пробует inline-синтаксисы по приоритету.
 * Всё остальное — обычный текст (Context::text()).
 */
final class InlineParser
{
    public function parse(string $text, Context $context): string
    {
        $triggers = $context->registry()->triggers();

        $out = '';
        $buffer = '';
        $i = 0;
        $n = strlen($text);

        while ($i < $n) {
            $char = $text[$i];

            if ($char === '\\' && $i + 1 < $n) {
                $next = $text[$i + 1];

                if ($next === "\n") {
                    $out .= $context->text($buffer) . "<br>\n";
                    $buffer = '';
                    $i += 2;
                    continue;
                }

                if (ctype_punct($next)) {
                    $buffer .= $next;
                    $i += 2;
                    continue;
                }
            }

            if ($char === "\n" && str_ends_with($buffer, '  ')) {
                $out .= $context->text(rtrim($buffer, ' ')) . "<br>\n";
                $buffer = '';
                $i++;
                continue;
            }

            if (isset($triggers[$char])) {
                foreach ($triggers[$char] as $definition) {
                    $hit = $definition->syntax::matchInline($text, $i, $context);

                    if ($hit === null || $hit->length < 1) {
                        continue;
                    }

                    $out .= $context->text($buffer) . $context->html($definition->name, $hit->attributes);
                    $buffer = '';
                    $i += $hit->length;

                    continue 2;
                }
            }

            $buffer .= $char;
            $i++;
        }

        return $out . $context->text($buffer);
    }
}
