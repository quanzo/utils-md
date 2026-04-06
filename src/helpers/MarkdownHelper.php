<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use function implode;
use function explode;
use function preg_match;
use function preg_replace;
use function rtrim;
use function strlen;

/**
 * Вспомогательный класс для markdown
 *
 * Пример использования:
 * `MarkdownHelper::safeMarkdownWhitespace($markdown);`
 */
class MarkdownHelper
{
    /**
     * Безопасно очищает Markdown от лишних пробелов, сохраняя форматирование.
     *
     * @param string $markdown Исходный текст Markdown.
     * @return string Обработанный текст.
     */
    public static function safeMarkdownWhitespace(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $inFenced = false;          // флаг: внутри fenced-блока кода
        $result = [];

        foreach ($lines as $line) {
            // Определяем начало/конец fenced-блока (``` или ~~~)
            if (preg_match('/^(?P<fence>`{3,}|~{3,})\s*$/', $line, $matches)) {
                $result[] = $line;          // fence оставляем без изменений
                $inFenced = !$inFenced;
                continue;
            }

            // Внутри fenced-блока строки не трогаем
            if ($inFenced) {
                $result[] = $line;
                continue;
            }

            // --- Обработка обычного текста (не код) ---
            // Жёсткий перенос markdown должен сохраняться только при ровно двух
            // завершающих пробелах, а не при 3+.
            $trailingSpacesCount = strlen($line) - strlen(rtrim($line, ' '));
            $hasTrailingDoubleSpace = ($trailingSpacesCount === 2);

            // Убираем конечные пробелы для последующей обработки (ведущие остаются!)
            $trimmed = rtrim($line);

            // Заменяем 3 и более пробелов подряд внутри строки на один пробел
            $processed = preg_replace('/ {3,}/', ' ', $trimmed);

            // Восстанавливаем два пробела в конце, если они были
            if ($hasTrailingDoubleSpace) {
                $processed .= '  ';
            }

            $result[] = $processed;
        }

        return implode("\n", $result);
    }
}
