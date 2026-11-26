<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Engine;

/**
 * Идёт по строкам, на каждой пробует блочные синтаксисы по приоритету.
 * Строки, которые не взял ни один блок, копятся в абзац (синтаксис `paragraph`).
 */
final class BlockParser
{
    /**
     * @param list<string> $lines
     *
     * @return list<array<string, mixed>>
     */
    public function parse(array $lines, Context $context, int $offset = 0): array
    {
        $cursor = new Lines($lines, $offset);
        $definitions = $context->registry()->blocks();

        $elements = [];
        $paragraph = [];
        $paragraphLine = 0;

        $flush = function () use (&$paragraph, &$paragraphLine, &$elements, $context): void {
            if ($paragraph === []) {
                return;
            }

            $text = rtrim(implode("\n", array_map('ltrim', $paragraph)));
            $content = $context->inline($text);

            $elements[] = $context->element(
                'paragraph',
                ['content' => $content, 'text' => $context->plain($content)],
                $paragraphLine,
            );

            $paragraph = [];
        };

        while (!$cursor->eof()) {
            $line = $cursor->current();

            if (Text::blank($line)) {
                $flush();
                $cursor->advance(1);
                continue;
            }

            $cursor->paragraphOpen = $paragraph !== [];

            foreach ($definitions as $definition) {
                $hit = $definition->syntax::matchBlock($cursor, $context);

                if ($hit === null) {
                    continue;
                }

                $flush();
                $elements[] = $context->element($definition->name, $hit->attributes, $cursor->number());
                $cursor->advance(max(1, $hit->length));

                continue 2;
            }

            if ($paragraph === []) {
                $paragraphLine = $cursor->number();
            }

            $paragraph[] = $line;
            $cursor->advance(1);
        }

        $flush();

        return $elements;
    }
}
