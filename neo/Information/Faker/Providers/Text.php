<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Exceptions\FakerException;
use Neo\Information\Faker\Provider;

/**
 * Words, sentences, paragraphs and text of a limited length.
 */
final class Text extends Provider
{
    public function word(): string
    {
        return (string) $this->pickLang('text.words');
    }

    /**
     * @return array<int, string>|string
     */
    public function words(int $count = 3, bool $asText = false): array|string
    {
        $words = $this->rng()->pickMany($this->listLang('text.words'), $count, true);

        return $asText ? implode(' ', $words) : $words;
    }

    /**
     * One sentence of about $words words (+-40% when $variable).
     */
    public function sentence(int $words = 6, bool $variable = true): string
    {
        if ($variable) {
            $words = $this->rng()->int(max(1, (int) round($words * 0.6)), max(1, (int) round($words * 1.4)));
        }

        $text = (string) $this->words(max(1, $words), true);

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1) . '.';
    }

    /**
     * @return array<int, string>|string
     */
    public function sentences(int $count = 3, bool $asText = false): array|string
    {
        $sentences = [];

        for ($i = 0; $i < $count; $i++) {
            $sentences[] = $this->sentence();
        }

        return $asText ? implode(' ', $sentences) : $sentences;
    }

    public function paragraph(int $sentences = 3, bool $variable = true): string
    {
        if ($variable) {
            $sentences = $this->rng()->int(max(1, (int) round($sentences * 0.6)), max(1, (int) round($sentences * 1.4)));
        }

        return (string) $this->sentences($sentences, true);
    }

    /**
     * @return array<int, string>|string
     */
    public function paragraphs(int $count = 3, bool $asText = false): array|string
    {
        $paragraphs = [];

        for ($i = 0; $i < $count; $i++) {
            $paragraphs[] = $this->paragraph();
        }

        return $asText ? implode("\n\n", $paragraphs) : $paragraphs;
    }

    /**
     * Text that is never longer than $maxChars characters.
     */
    public function text(int $maxChars = 200): string
    {
        if ($maxChars < 5) {
            throw new FakerException('text() needs at least 5 characters.');
        }

        $text = '';

        while (true) {
            $sentence = $this->sentence();
            $candidate = $text === '' ? $sentence : $text . ' ' . $sentence;

            if (mb_strlen($candidate) > $maxChars) {
                break;
            }

            $text = $candidate;
        }

        return $text !== ''
            ? $text
            : rtrim(mb_substr($sentence, 0, $maxChars - 1)) . '.';
    }
}
