<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Contracts;

use Neo\Transfer\Transfer;

/**
 * Синтаксис — это тройка: класс синтаксиса + Transfer + blade-шаблон.
 * Каждый элемент разметки (заголовок, ссылка, жирный, таблица…) — отдельный синтаксис.
 *
 * Как синтаксис находит себя в тексте, определяют два дополнительных контракта:
 *  - BlockSyntaxContract  — блок из целых строк (заголовок, список, директива);
 *  - InlineSyntaxContract — фрагмент внутри строки (ссылка, жирный, код).
 * Синтаксис может реализовать оба (так сделан ImageSyntax).
 */
interface SyntaxContract
{
    /**
     * Уникальное имя. Повторная регистрация того же имени ЗАМЕНЯЕТ синтаксис.
     * По умолчанию: HeaderOneSyntax => `header-one`.
     */
    public static function name(): string;

    /**
     * Порядок проверки: чем меньше число, тем раньше.
     */
    public static function priority(): int;

    /**
     * Шаблон по умолчанию (абсолютный путь). Переопределяется при регистрации.
     */
    public static function template(): string;

    /**
     * Transfer по умолчанию. Переопределяется при регистрации.
     *
     * @return class-string<Transfer>
     */
    public static function transfer(): string;

    public function config(): Transfer;

    /**
     * Данные элемента (публичные свойства Transfer) для форматов collection/array.
     *
     * @return array<string, mixed>
     */
    public function data(): array;
}
