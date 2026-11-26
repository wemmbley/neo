<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Engine;

/**
 * Якоря заголовков: транслитерация кириллицы, уникальность в пределах документа.
 */
final class Slugger
{
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye',
        'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k',
        'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'ł' => 'l', 'đ' => 'd',
    ];

    /** @var array<string, true> */
    private array $used = [];

    public function make(string $text): string
    {
        $base = self::slug($text);
        $base = $base === '' ? 'section' : $base;
        $slug = $base;

        for ($i = 2; isset($this->used[$slug]); $i++) {
            $slug = $base . '-' . $i;
        }

        $this->used[$slug] = true;

        return $slug;
    }

    public static function slug(string $text): string
    {
        // Сначала собственная карта (ї, й и т.д. ещё не разложены), потом снятие диакритики.
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::MAP);

        if (class_exists(\Normalizer::class)) {
            $text = (string) preg_replace('~\p{Mn}+~u', '', \Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text);
        }

        return trim((string) preg_replace('~[^a-z0-9]+~', '-', $text), '-');
    }
}
