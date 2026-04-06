<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\tools\MarkdownChunkDto;
use app\modules\neuron\classes\dto\tools\MarkdownChunksResultDto;
use InvalidArgumentException;

use function array_values;
use function count;
use function explode;
use function implode;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_split;
use function str_ends_with;
use function trim;
use function in_array;
use function is_string;

/**
 * Вспомогательный класс для семантического разбиения Markdown на чанки и выборок “вокруг якоря”.
 *
 * Этот helper решает типовую проблему: “разрезать markdown так, чтобы не ломать смысл”.
 * Вместо грубого деления по N символов он сначала разбивает текст на **семантические блоки**
 * (таблица целиком, fenced-code целиком, список целиком, заголовок вместе с ближайшим абзацем/таблицей),
 * а затем уже агрегирует эти блоки в чанки или окна вокруг совпадений.
 *
 * ### Ключевые понятия
 *
 * - **Семантический блок**: минимальная единица, которую нельзя разрывать:
 *   - `code_fence` — fenced блок кода (``` ... ``` или ~~~ ... ~~~) целиком
 *   - `table` — таблица целиком (включая header+delimiter+rows)
 *   - `list` — список целиком (маркированный/нумерованный + строки продолжения)
 *   - `heading` — строка заголовка
 *   - `paragraph` — абзац (группа непустых строк, не являющихся списком/таблицей/кодом/заголовком)
 *   - “склеенные” блоки: `heading_with_paragraph`, `heading_with_table`, `paragraph_with_table`
 *
 * - **Чанк**: объединение одного или нескольких блоков, разделённых двойным переводом строки (`\n\n`),
 *   представленное DTO {@see MarkdownChunkDto}.
 *
 * - **Якорь**: строка, совпадающая с паттерном поиска (regex или обычная строка, см. {@see buildLineRegex()}).
 *   Важно: якорь ищется **по строкам**, а возвращаемый результат формируется **по блокам**.
 *
 * ### Зачем два режима (chunk и anchor-window)
 *
 * - `chunkBySemanticBlocks(...)` — когда нужно “порезать весь документ” на чанки целиком.
 * - `chunkAroundAnchorLineRegex(...)` — когда нужно получить один кусок контекста вокруг первого совпадения.
 * - `chunksAroundAllAnchorLineRegex(...)` — когда нужно получить несколько непересекающихся кусков контекста
 *   вокруг всех совпадений, но с лимитами на размер одного чанка и общий объём.
 *
 * Пример использования:
 *
 * <code>
 * $chunks = MarkdownChunckHelper::chunkBySemanticBlocks($markdown, 1200);
 * $one = MarkdownChunckHelper::chunkAroundAnchorLineRegex($markdown, 0, 'TODO:', 2000);
 * $many = MarkdownChunckHelper::chunksAroundAllAnchorLineRegex($markdown, '/^TODO:/u', 1500, 5000);
 * </code>
 */
class MarkdownChunckHelper
{
    /**
     * Максимальное число промежуточных слов между соседними якорями в phrase->regex режиме.
     */
    private const int PHRASE_REGEX_MAX_GAP_WORDS = 5;

    /**
     * Слова длиной <= этого порога считаются короткими и не стеммятся.
     */
    private const int PHRASE_REGEX_SHORT_WORD_MAX_LENGTH = 4;

    /**
     * Минимальная длина основы после обрезки известного окончания.
     */
    private const int PHRASE_REGEX_MIN_STEM_LENGTH = 4;

    /**
     * Минимальная длина основы после fallback-обрезки.
     */
    private const int PHRASE_REGEX_MIN_FALLBACK_STEM_LENGTH = 3;

    /**
     * Явно заданный токен "слово" для Unicode-режима.
     */
    private const string PHRASE_REGEX_WORD_TOKEN = '[\p{L}\p{N}_]+';

    /**
     * Типовые русские окончания (от длинных к коротким), используемые в гибридной обрезке.
     *
     * @var list<string>
     */
    private const array PHRASE_REGEX_KNOWN_ENDINGS = [
        'ование',
        'овать',
        'иями',
        'остей',
        'остью',
        'ости',
        'ость',
        'цией',
        'имая',
        'иях',
        'иям',
        'ием',
        'ыми',
        'ими',
        'ого',
        'ему',
        'ому',
        'ями',
        'ами',
        'ий',
        'ая',
        'яя',
        'ое',
        'ее',
        'ые',
        'ие',
        'ой',
        'ей',
        'ам',
        'ям',
        'ах',
        'ях',
        'ом',
        'ем',
        'ов',
        'ев',
        'а',
        'я',
        'ы',
        'и',
        'о',
        'е',
        'у',
        'ю',
    ];
    /**
     * Возвращает список непересекающихся чанков вокруг всех вхождений строки по regex.
     *
     * Это “multi-match режим”: мы ищем **все строки-якоря**, совпадающие с `lineRegex`,
     * и для каждого якоря строим “окно” семантических блоков вокруг него.
     *
     * #### Алгоритм (высокоуровнево)
     *
     * - Разбиваем markdown на строки.
     * - Находим индексы строк, где паттерн совпал (якорные строки).
     * - Токенизируем markdown в семантические блоки с диапазонами строк
     *   ({@see tokenizeMarkdownBlocksWithLineSpans}).
     * - Для каждой якорной строки находим блок, который её содержит.
     * - Вокруг якорного блока выбираем сбалансированное множество соседних блоков,
     *   стараясь удержать итоговый размер <= `maxCharsPerBlock`
     *   ({@see selectBalancedWindowBlockIndexes}).
     * - Проверяем непересечение: если хотя бы один блок уже был использован
     *   в ранее выбранных чанках — текущий кандидат пропускается.
     * - Следим за общим лимитом `maxTotalChars`: при превышении — прекращаем добавление чанков.
     *
     * #### Непересечение (важное условие)
     *
     * “Пересечение” определяется **по индексам семантических блоков**, а не по символам и не по строкам.
     * Это гарантирует целостность: мы никогда не возвращаем один и тот же блок дважды.
     *
     * Пример:
     * - совпадение #1 в абзаце A
     * - совпадение #2 в соседнем абзаце B
     * - при большом `maxCharsPerBlock` окно вокруг совпадения #1 может включить блоки A и B
     * - тогда кандидат для совпадения #2 будет пересекаться (блок B уже использован) и будет пропущен.
     *
     * #### Oversized блоки
     *
     * Если якорь попадает в блок, который сам по себе больше `maxCharsPerBlock`,
     * возвращается этот блок целиком (чанк будет `isOversized=true`).
     * Это осознанный компромисс: мы не разрываем семантический блок даже ради лимита.
     *
     * Важное условие: чанки не должны пересекаться по семантическим блокам.
     * Если кандидатный чанк содержит хотя бы один блок, который уже включён в ранее
     * выбранные чанки, такой кандидат пропускается.
     *
     * Если якорь попадает внутрь семантического блока, чей размер больше $maxCharsPerBlock,
     * возвращается этот блок целиком (с isOversized=true), даже если он превышает лимит.
     *
     * @param string $markdown         Исходный markdown-текст.
     * @param string $lineRegex        Регулярное выражение для поиска по строкам (с разделителями, например "/TODO:/u").
     * @param int    $maxCharsPerBlock Максимальный размер одного чанка в символах.
     * @param int    $maxTotalChars    Максимальный суммарный размер всех чанков в символах.
     *
     * @return MarkdownChunksResultDto Результат со списком чанков (возможно пустым).
     *
     * @throws InvalidArgumentException Если лимиты некорректны или паттерн пустой/некорректный.
     */
    public static function chunksAroundAllAnchorLineRegex(
        string $markdown,
        string|array $lineRegex,
        int $maxCharsPerBlock = 5000,
        int $maxTotalChars = 20000,
    ): MarkdownChunksResultDto {
        if ($maxCharsPerBlock <= 0) {
            throw new InvalidArgumentException('Параметр maxCharsPerBlock должен быть больше 0.');
        }
        if ($maxTotalChars <= 0) {
            throw new InvalidArgumentException('Параметр maxTotalChars должен быть больше 0.');
        }
        $regexes = self::buildLineRegexList($lineRegex);

        $lines = explode("\n", $markdown);
        if ($lines === []) {
            return new MarkdownChunksResultDto($maxCharsPerBlock, []);
        }

        $blocks = self::tokenizeMarkdownBlocksWithLineSpans($markdown);
        if ($blocks === []) {
            return new MarkdownChunksResultDto($maxCharsPerBlock, []);
        }

        $anchorLineIndexes = [];
        foreach ($lines as $lineIndex => $line) {
            if (self::matchesAnyRegex($line, $regexes)) {
                $anchorLineIndexes[$lineIndex] = true;
            }
        }
        $anchorLineIndexes = array_keys($anchorLineIndexes);

        if ($anchorLineIndexes === []) {
            return new MarkdownChunksResultDto($maxCharsPerBlock, []);
        }

        $usedBlockIndexes = [];
        $chunks           = [];
        $totalChars       = 0;

        foreach ($anchorLineIndexes as $anchorLineIndex) {
            $anchorBlockIndex = null;
            foreach ($blocks as $blockIndex => $block) {
                if ($anchorLineIndex >= $block['startLine'] && $anchorLineIndex <= $block['endLine']) {
                    $anchorBlockIndex = $blockIndex;
                    break;
                }
            }
            if ($anchorBlockIndex === null) {
                continue;
            }

            [$selectedBlockIndexes, $oversized] = self::selectBalancedWindowBlockIndexes(
                $blocks,
                $anchorBlockIndex,
                $maxCharsPerBlock,
            );

            $intersects = false;
            foreach ($selectedBlockIndexes as $idx) {
                if (isset($usedBlockIndexes[$idx])) {
                    $intersects = true;
                    break;
                }
            }
            if ($intersects) {
                continue;
            }

            $texts = [];
            $kinds = [];
            foreach ($selectedBlockIndexes as $idx) {
                $texts[]                      = $blocks[$idx]['text'];
                $kinds[$blocks[$idx]['kind']] = $blocks[$idx]['kind'];
            }
            $text = implode("\n\n", $texts);
            $len  = mb_strlen($text);

            if ($totalChars + $len > $maxTotalChars) {
                break;
            }

            $chunkIndex = count($chunks);
            $chunks[]   = new MarkdownChunkDto(
                index      : $chunkIndex,
                text       : $text,
                lengthChars: $len,
                blockKinds : array_values($kinds),
                isOversized: $oversized || $len > $maxCharsPerBlock,
            );

            $totalChars += $len;
            foreach ($selectedBlockIndexes as $idx) {
                $usedBlockIndexes[$idx] = true;
            }
        }

        return new MarkdownChunksResultDto($maxCharsPerBlock, $chunks);
    }

    /**
     * Ищет первую строку, совпадающую с регулярным выражением, начиная с заданной позиции,
     * и возвращает "окно" семантических блоков вокруг найденного якоря.
     *
     * Это “single-match режим”: метод ищет **первое** совпадение после `fromChar` и
     * возвращает один чанк/окно вокруг найденного якоря.
     *
     * #### Поиск якоря
     *
     * - `fromChar` задаётся в **символах** (0-based), чтобы удобно искать “после определённого места”.
     * - Для преобразования `fromChar` в индекс строки строится массив стартовых смещений строк
     *   ({@see buildLineStartCharOffsets}) и выбирается строка, в которую попадает `fromChar`
     *   ({@see findLineIndexByCharOffset}).
     * - Далее выполняется линейный поиск по строкам на совпадение паттерна.
     *
     * #### Построение окна
     *
     * После нахождения якорной строки:
     * - токенизируем markdown в семантические блоки с диапазонами строк;
     * - находим якорный блок (тот, в чьи startLine..endLine попала якорная строка);
     * - если якорный блок превышает `maxChars` — возвращаем его целиком (`isOversized=true`);
     * - иначе добавляем соседние блоки слева/справа, стараясь держать якорь примерно по центру
     *   (балансируем суммарную добавленную длину слева/справа).
     *
     * #### Почему “примерно середина”, а не точная середина
     *
     * Мы работаем с целыми блоками, поэтому точная симметрия невозможна.
     * Балансировка сделана жадно и дешёво: выбираем сторону, у которой накопленная
     * длина меньше, если обе стороны ещё умещаются в лимит.
     *
     * Важное ограничение: если якорь находится внутри семантического блока,
     * длина которого превышает $maxChars, метод возвращает этот блок целиком
     * (с isOversized=true).
     *
     * @param string $markdown   Исходный markdown-текст.
     * @param int    $fromChar   Позиция (0-based) в символах, начиная с которой выполняется поиск.
     * @param string $lineRegex  Regex (с разделителями) или обычная строка. См. {@see buildLineRegex()}.
     * @param int    $maxChars   Максимальный размер возвращаемого текста в символах.
     *
     * @return MarkdownChunkDto|null Возвращает чанк вокруг якоря или null, если совпадений не найдено.
     *
     * @throws InvalidArgumentException Если параметры некорректны.
     */
    public static function chunkAroundAnchorLineRegex(
        string $markdown,
        int $fromChar,
        string|array $lineRegex,
        int $maxChars = 5000,
    ): ?MarkdownChunkDto {
        if ($fromChar < 0) {
            throw new InvalidArgumentException('Параметр fromChar должен быть >= 0.');
        }
        if ($maxChars <= 0) {
            throw new InvalidArgumentException('Параметр maxChars должен быть больше 0.');
        }
        $regexes = self::buildLineRegexList($lineRegex);

        $lines     = explode("\n", $markdown);
        $lineCount = count($lines);
        if ($lineCount === 0) {
            return null;
        }

        $lineStartChars = self::buildLineStartCharOffsets($lines);
        $startLineIndex = self::findLineIndexByCharOffset($lineStartChars, $fromChar);
        if ($startLineIndex >= $lineCount) {
            return null;
        }

        $anchorLineIndex = null;
        for ($i = $startLineIndex; $i < $lineCount; $i++) {
            if (self::matchesAnyRegex($lines[$i], $regexes)) {
                $anchorLineIndex = $i;
                break;
            }
        }
        if ($anchorLineIndex === null) {
            return null;
        }

        $blocks = self::tokenizeMarkdownBlocksWithLineSpans($markdown);
        if ($blocks === []) {
            return null;
        }

        $anchorBlockIndex = null;
        foreach ($blocks as $blockIndex => $block) {
            if ($anchorLineIndex >= $block['startLine'] && $anchorLineIndex <= $block['endLine']) {
                $anchorBlockIndex = $blockIndex;
                break;
            }
        }
        if ($anchorBlockIndex === null) {
            return null;
        }

        $anchorBlock = $blocks[$anchorBlockIndex];
        $anchorLen   = mb_strlen($anchorBlock['text']);
        if ($anchorLen > $maxChars) {
            return new MarkdownChunkDto(
                index      : 0,
                text       : $anchorBlock['text'],
                lengthChars: $anchorLen,
                blockKinds : [$anchorBlock['kind']],
                isOversized: true,
            );
        }

        $selected   = [$anchorBlockIndex => $anchorBlock];
        $leftIndex  = $anchorBlockIndex - 1;
        $rightIndex = $anchorBlockIndex + 1;

        $leftLen      = 0;
        $rightLen     = 0;
        $totalTextLen = $anchorLen;
        $kinds        = [$anchorBlock['kind'] => $anchorBlock['kind']];

        while (true) {
            $canLeft  = $leftIndex >= 0;
            $canRight = $rightIndex < count($blocks);
            if (!$canLeft && !$canRight) {
                break;
            }

            $candidateLeftLen = null;
            if ($canLeft) {
                $sep              = "\n\n";
                $candidateLeftLen = $totalTextLen + mb_strlen($sep) + mb_strlen($blocks[$leftIndex]['text']);
                if ($candidateLeftLen > $maxChars) {
                    $canLeft = false;
                }
            }

            $candidateRightLen = null;
            if ($canRight) {
                $sep               = "\n\n";
                $candidateRightLen = $totalTextLen + mb_strlen($sep) + mb_strlen($blocks[$rightIndex]['text']);
                if ($candidateRightLen > $maxChars) {
                    $canRight = false;
                }
            }

            if (!$canLeft && !$canRight) {
                break;
            }

            $chooseLeft = false;
            if ($canLeft && !$canRight) {
                $chooseLeft = true;
            } elseif (!$canLeft && $canRight) {
                $chooseLeft = false;
            } else {
                  // Стараемся балансировать так, чтобы якорь был примерно в середине.
                $chooseLeft = $leftLen <= $rightLen;
            }

            if ($chooseLeft) {
                $selected[$leftIndex]                = $blocks[$leftIndex];
                $addedLen                            = mb_strlen($blocks[$leftIndex]['text']) + mb_strlen("\n\n");
                $leftLen                            += $addedLen;
                $totalTextLen                       += $addedLen;
                $kinds[$blocks[$leftIndex]['kind']]  = $blocks[$leftIndex]['kind'];
                $leftIndex--;
            } else {
                $selected[$rightIndex]                = $blocks[$rightIndex];
                $addedLen                             = mb_strlen($blocks[$rightIndex]['text']) + mb_strlen("\n\n");
                $rightLen                            += $addedLen;
                $totalTextLen                        += $addedLen;
                $kinds[$blocks[$rightIndex]['kind']]  = $blocks[$rightIndex]['kind'];
                $rightIndex++;
            }
        }

        ksort($selected);
        $texts = [];
        foreach ($selected as $b) {
            $texts[] = $b['text'];
        }
        $text = implode("\n\n", $texts);

        return new MarkdownChunkDto(
            index      : 0,
            text       : $text,
            lengthChars: mb_strlen($text),
            blockKinds : array_values($kinds),
            isOversized: mb_strlen($text) > $maxChars,
        );
    }

    /**
     * Семантически разбивает markdown-текст на чанки по целевому размеру.
     *
     * Этот метод предназначен для “нарезки всего документа”: он сохраняет целостность
     * таблиц/кода/списков и затем собирает чанки жадным алгоритмом, ориентируясь на targetChars.
     *
     * Если некоторый блок неделим и сам по себе больше targetChars — он попадёт в отдельный чанк
     * и будет помечен как `isOversized=true`.
     *
     * @param string $markdown    Исходный markdown-текст.
     * @param int    $targetChars Целевой размер чанка в символах.
     *
     * @return MarkdownChunksResultDto Результат разбиения с метаданными.
     *
     * @throws InvalidArgumentException Если targetChars <= 0.
     */
    public static function chunkBySemanticBlocks(string $markdown, int $targetChars): MarkdownChunksResultDto
    {
        if ($targetChars <= 0) {
            throw new InvalidArgumentException('Параметр targetChars должен быть больше 0.');
        }

        $blocks = self::tokenizeMarkdownBlocks($markdown);
        if ($blocks === []) {
            return new MarkdownChunksResultDto($targetChars, []);
        }

        $normalizedBlocks = self::splitLongTextBlocksBySentences($blocks, $targetChars);
        return self::buildChunkResult($normalizedBlocks, $targetChars);
    }

    /**
     * Разбивает markdown на атомарные блоки для дальнейшей агрегации в чанки.
     *
     * @param string $markdown Исходный markdown-текст.
     *
     * @return array<int, array{kind: string, text: string}>
     */
    private static function tokenizeMarkdownBlocks(string $markdown): array
    {
        $lines     = explode("\n", $markdown);
        $lineCount = count($lines);
        $index     = 0;
        $blocks    = [];

        while ($index < $lineCount) {
            $line = $lines[$index];

            if (trim($line) === '') {
                $index++;
                continue;
            }

            if (self::isFenceLine($line)) {
                // Fenced code block: читаем до закрывающего fence (или до конца текста, если fence не закрыт).
                $fencedLines = [$line];
                $index++;

                while ($index < $lineCount) {
                    $fencedLines[] = $lines[$index];
                    if (self::isFenceLine($lines[$index])) {
                        $index++;
                        break;
                    }
                    $index++;
                }

                $blocks[] = ['kind' => 'code_fence', 'text' => implode("\n", $fencedLines)];
                continue;
            }

            if (self::isPotentialTableStart($lines, $index)) {
                // Таблица: собираем header + delimiter + последующие table-row строки.
                $tableLines = [$lines[$index], $lines[$index + 1]];
                $index += 2;

                while ($index < $lineCount && self::isTableRow($lines[$index])) {
                    $tableLines[] = $lines[$index];
                    $index++;
                }

                $tableText = implode("\n", $tableLines);

                if ($blocks !== [] && $blocks[count($blocks) - 1]['kind'] === 'heading') {
                    // Склеиваем заголовок с таблицей, чтобы они не разъезжались по разным чанкам.
                    $previousHeading = $blocks[count($blocks) - 1]['text'];
                    $blocks[count($blocks) - 1] = [
                        'kind' => 'heading_with_table',
                        'text' => $previousHeading . "\n\n" . $tableText,
                    ];
                    continue;
                }

                if ($blocks !== [] && $blocks[count($blocks) - 1]['kind'] === 'paragraph') {
                    // Склеиваем абзац с таблицей: часто таблица является продолжением пояснения.
                    $previousParagraph = $blocks[count($blocks) - 1]['text'];
                    $blocks[count($blocks) - 1] = [
                        'kind' => 'paragraph_with_table',
                        'text' => $previousParagraph . "\n\n" . $tableText,
                    ];
                    continue;
                }

                $blocks[] = ['kind' => 'table', 'text' => $tableText];
                continue;
            }

            if (self::isHeadingLine($line)) {
                // Заголовок: отдельный блок. Может быть позже склеен с абзацем/таблицей.
                $blocks[] = ['kind' => 'heading', 'text' => $line];
                $index++;
                continue;
            }

            if (self::isListLine($line)) {
                // Список: собираем подряд идущие list-строки и строки продолжения.
                $listLines = [$line];
                $index++;
                while ($index < $lineCount && trim($lines[$index]) !== '') {
                    if (!self::isListLine($lines[$index]) && !self::isListContinuationLine($lines[$index])) {
                        break;
                    }
                    $listLines[] = $lines[$index];
                    $index++;
                }

                $blocks[] = ['kind' => 'list', 'text' => implode("\n", $listLines)];
                continue;
            }

            // Абзац: группа непустых строк до следующего “структурного” блока.
            $paragraphLines = [$line];
            $index++;
            while ($index < $lineCount && trim($lines[$index]) !== '') {
                if (
                    self::isFenceLine($lines[$index])
                    || self::isHeadingLine($lines[$index])
                    || self::isPotentialTableStart($lines, $index)
                    || self::isListLine($lines[$index])
                ) {
                    break;
                }

                $paragraphLines[] = $lines[$index];
                $index++;
            }

            $paragraphText = implode("\n", $paragraphLines);

            if ($blocks !== [] && $blocks[count($blocks) - 1]['kind'] === 'heading') {
                // Склеиваем заголовок и следующий абзац: это одна смысловая секция.
                $previousHeading = $blocks[count($blocks) - 1]['text'];
                $blocks[count($blocks) - 1] = [
                    'kind' => 'heading_with_paragraph',
                    'text' => $previousHeading . "\n\n" . $paragraphText,
                ];
                continue;
            }

            $blocks[] = ['kind' => 'paragraph', 'text' => $paragraphText];
        }

        return $blocks;
    }

    /**
     * Разбивает markdown на атомарные блоки и возвращает блоки с диапазонами строк.
     *
     * Нужен для операций, которые требуют привязки к исходным строкам (например,
     * выборка окна вокруг якорной строки).
     *
     * @param string $markdown
     *
     * @return array<int, array{kind:string, text:string, startLine:int, endLine:int}>
     */
    private static function tokenizeMarkdownBlocksWithLineSpans(string $markdown): array
    {
        $lines     = explode("\n", $markdown);
        $lineCount = count($lines);
        $index     = 0;
        $blocks    = [];

        while ($index < $lineCount) {
            $line = $lines[$index];

            if (trim($line) === '') {
                $index++;
                continue;
            }

            if (self::isFenceLine($line)) {
                $start       = $index;
                $fencedLines = [$line];
                $index++;

                while ($index < $lineCount) {
                    $fencedLines[] = $lines[$index];
                    if (self::isFenceLine($lines[$index])) {
                        $index++;
                        break;
                    }
                    $index++;
                }

                $end      = $index - 1;
                $blocks[] = [
                    'kind'      => 'code_fence',
                    'text'      => implode("\n", $fencedLines),
                    'startLine' => $start,
                    'endLine'   => $end,
                ];
                continue;
            }

            if (self::isPotentialTableStart($lines, $index)) {
                $start       = $index;
                $tableLines  = [$lines[$index], $lines[$index + 1]];
                $index      += 2;

                while ($index < $lineCount && self::isTableRow($lines[$index])) {
                    $tableLines[] = $lines[$index];
                    $index++;
                }

                $end       = $index - 1;
                $tableText = implode("\n", $tableLines);

                if ($blocks !== [] && $blocks[count($blocks) - 1]['kind'] === 'heading') {
                    $prevIndex          = count($blocks) - 1;
                    $previousHeading    = $blocks[$prevIndex]['text'];
                    $blocks[$prevIndex] = [
                        'kind'      => 'heading_with_table',
                        'text'      => $previousHeading . "\n\n" . $tableText,
                        'startLine' => $blocks[$prevIndex]['startLine'],
                        'endLine'   => $end,
                    ];
                    continue;
                }

                if ($blocks !== [] && $blocks[count($blocks) - 1]['kind'] === 'paragraph') {
                    $prevIndex          = count($blocks) - 1;
                    $previousParagraph  = $blocks[$prevIndex]['text'];
                    $blocks[$prevIndex] = [
                        'kind'      => 'paragraph_with_table',
                        'text'      => $previousParagraph . "\n\n" . $tableText,
                        'startLine' => $blocks[$prevIndex]['startLine'],
                        'endLine'   => $end,
                    ];
                    continue;
                }

                $blocks[] = [
                    'kind'      => 'table',
                    'text'      => $tableText,
                    'startLine' => $start,
                    'endLine'   => $end,
                ];
                continue;
            }

            if (self::isHeadingLine($line)) {
                $blocks[] = [
                    'kind'      => 'heading',
                    'text'      => $line,
                    'startLine' => $index,
                    'endLine'   => $index,
                ];
                $index++;
                continue;
            }

            if (self::isListLine($line)) {
                $start     = $index;
                $listLines = [$line];
                $index++;
                while ($index < $lineCount && trim($lines[$index]) !== '') {
                    if (!self::isListLine($lines[$index]) && !self::isListContinuationLine($lines[$index])) {
                        break;
                    }
                    $listLines[] = $lines[$index];
                    $index++;
                }
                $end = $index - 1;

                $blocks[] = [
                    'kind'      => 'list',
                    'text'      => implode("\n", $listLines),
                    'startLine' => $start,
                    'endLine'   => $end,
                ];
                continue;
            }

            $start = $index;
            $paragraphLines = [$line];
            $index++;
            while ($index < $lineCount && trim($lines[$index]) !== '') {
                if (
                    self::isFenceLine($lines[$index])
                    || self::isHeadingLine($lines[$index])
                    || self::isPotentialTableStart($lines, $index)
                    || self::isListLine($lines[$index])
                ) {
                    break;
                }

                $paragraphLines[] = $lines[$index];
                $index++;
            }

            $end           = $index - 1;
            $paragraphText = implode("\n", $paragraphLines);

            if ($blocks !== [] && $blocks[count($blocks) - 1]['kind'] === 'heading') {
                $prevIndex          = count($blocks) - 1;
                $previousHeading    = $blocks[$prevIndex]['text'];
                $blocks[$prevIndex] = [
                    'kind'      => 'heading_with_paragraph',
                    'text'      => $previousHeading . "\n\n" . $paragraphText,
                    'startLine' => $blocks[$prevIndex]['startLine'],
                    'endLine'   => $end,
                ];
                continue;
            }

            $blocks[] = [
                'kind'      => 'paragraph',
                'text'      => $paragraphText,
                'startLine' => $start,
                'endLine'   => $end,
            ];
        }

        return $blocks;
    }

    /**
     * Строит массив стартовых позиций строк в символах.
     *
     * @param string[] $lines
     *
     * @return int[] positions where each line starts (0-based char offset)
     */
    private static function buildLineStartCharOffsets(array $lines): array
    {
        $offsets = [];
        $pos = 0;
        foreach ($lines as $i => $line) {
            $offsets[$i] = $pos;
            $pos += mb_strlen($line) + 1; // + "\n"
        }
        return $offsets;
    }

    /**
     * Находит индекс строки по смещению в символах.
     *
     * @param int[] $lineStartChars
     * @param int   $charOffset
     *
     * @return int
     */
    private static function findLineIndexByCharOffset(array $lineStartChars, int $charOffset): int
    {
        $count = count($lineStartChars);
        if ($count === 0) {
            return 0;
        }

        if ($charOffset <= 0) {
            return 0;
        }

        for ($i = 0; $i < $count; $i++) {
            $start = $lineStartChars[$i];
            $next = $i + 1 < $count ? $lineStartChars[$i + 1] : PHP_INT_MAX;
            if ($charOffset >= $start && $charOffset < $next) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * Выбирает сбалансированное окно семантических блоков вокруг якорного блока.
     *
     * @param array<int, array{kind:string, text:string, startLine:int, endLine:int}> $blocks
     * @param int $anchorBlockIndex
     * @param int $maxCharsPerBlock
     *
     * @return array{0:int[],1:bool} [индексы выбранных блоков (по возрастанию), isOversized]
     */
    private static function selectBalancedWindowBlockIndexes(
        array $blocks,
        int $anchorBlockIndex,
        int $maxCharsPerBlock,
    ): array {
        /**
         * Важно: это жадный (greedy) алгоритм, работающий в терминах **целых блоков**.
         * Он не пытается найти глобально оптимальное распределение вокруг якоря —
         * цель другая: быстро (O(n) по числу соседних блоков) собрать “разумное” окно.
         *
         * Балансировка:
         * - мы считаем, сколько “контекста” уже добавили слева и справа (leftLen/rightLen);
         * - если обе стороны ещё могут поместиться в лимит — выбираем сторону с меньшей накопленной длиной;
         * - если одна сторона уже не помещается — берём другую.
         */
        $anchorBlock = $blocks[$anchorBlockIndex];
        $anchorLen = mb_strlen($anchorBlock['text']);

        if ($anchorLen > $maxCharsPerBlock) {
            return [[$anchorBlockIndex], true];
        }

        $selected   = [$anchorBlockIndex => true];
        $leftIndex  = $anchorBlockIndex - 1;
        $rightIndex = $anchorBlockIndex + 1;

        $leftLen      = 0;
        $rightLen     = 0;
        $totalTextLen = $anchorLen;

        while (true) {
            $canLeft = $leftIndex >= 0;
            $canRight = $rightIndex < count($blocks);
            if (!$canLeft && !$canRight) {
                break;
            }

            if ($canLeft) {
                $candidate = $totalTextLen + mb_strlen("\n\n") + mb_strlen($blocks[$leftIndex]['text']);
                if ($candidate > $maxCharsPerBlock) {
                    $canLeft = false;
                }
            }

            if ($canRight) {
                $candidate = $totalTextLen + mb_strlen("\n\n") + mb_strlen($blocks[$rightIndex]['text']);
                if ($candidate > $maxCharsPerBlock) {
                    $canRight = false;
                }
            }

            if (!$canLeft && !$canRight) {
                break;
            }

            $chooseLeft = false;
            if ($canLeft && !$canRight) {
                $chooseLeft = true;
            } elseif (!$canLeft && $canRight) {
                $chooseLeft = false;
            } else {
                $chooseLeft = $leftLen <= $rightLen;
            }

            if ($chooseLeft) {
                $selected[$leftIndex]  = true;
                $addedLen              = mb_strlen($blocks[$leftIndex]['text']) + mb_strlen("\n\n");
                $leftLen              += $addedLen;
                $totalTextLen         += $addedLen;
                $leftIndex--;
            } else {
                $selected[$rightIndex]  = true;
                $addedLen               = mb_strlen($blocks[$rightIndex]['text']) + mb_strlen("\n\n");
                $rightLen              += $addedLen;
                $totalTextLen          += $addedLen;
                $rightIndex++;
            }
        }

        $indexes = array_keys($selected);
        sort($indexes);

        return [$indexes, false];
    }

    /**
     * Преобразует входной паттерн в корректное регулярное выражение для поиска по строкам.
     *
     * Если вход уже является корректным regex (с разделителями), он используется как есть.
     * Если нет — вход трактуется как фраза и преобразуется в "расстояниевый" regex.
     *
     * @param string $lineRegexOrText
     * @param bool   $strict          Строгий режим для plain-text: строить literal-regex "как есть".
     *
     * @return string
     */
    public static function buildLineRegex(string $lineRegexOrText, bool $strict = false): string
    {
        /**
         * Этот метод вводит “двухрежимный” паттерн:
         * - если вход выглядит как regex (например, "/TODO:/u"), то мы обязаны валидировать его как regex;
         * - если вход не выглядит как regex:
         *   - strict=true: строим literal-regex "как есть";
         *   - strict=false: трактуем как фразу и строим regex с допуском расстояния
         *     между нормализованными словами.
         *
         * Это важно для UX инструментов: LLM/пользователь может передать просто "TODO:",
         * не думая о синтаксисе регулярных выражений.
         */
        if ($lineRegexOrText === '') {
            throw new InvalidArgumentException('Параметр lineRegex не должен быть пустым.');
        }

        if (self::isRegexp($lineRegexOrText)) { // регулярка опознана - вернем ее
            return $lineRegexOrText;
        }
        if (self::looksLikeRegex($lineRegexOrText)) {
            throw new InvalidArgumentException('Параметр lineRegex должен быть корректным регулярным выражением.');
        }

        $regex = $strict
            ? self::buildStrictLineRegex($lineRegexOrText)
            : self::buildPhraseLineRegex($lineRegexOrText);
        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException('Не удалось построить регулярное выражение из lineRegex.');
        }

        return $regex;
    }

    /**
     * Распознаем в строке регулярку
     *
     * @param string $lineRegexOrText
     * @return boolean
     */
    public static function isRegexp(string $lineRegexOrText): bool
    {
        if (self::looksLikeRegex($lineRegexOrText)) {
            if (@preg_match($lineRegexOrText, '') === false) {
                return false;
            } else {
                return true;
            }
        }
        return false;
    }

    /**
     * Эвристика: похоже ли значение на regex с разделителями.
     *
     * Нужна, чтобы различать "текст для поиска" и "regex". Если строка выглядит как regex,
     * но является невалидной — это ошибка, а не текст.
     *
     * @param string $value
     *
     * @return bool
     */
    public static function looksLikeRegex(string $value): bool
    {
        if ($value === '' || mb_strlen($value) < 3) {
            return false;
        }

        $delim = $value[0];
        if (!in_array($delim, ['/', '#', '~'], true)) {
            return false;
        }

        $endPos = null;
        $len = mb_strlen($value);
        for ($i = $len - 1; $i >= 1; $i--) {
            if ($value[$i] !== $delim) {
                continue;
            }

            $slashes = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($value[$j] === '\\') {
                    $slashes++;
                } else {
                    break;
                }
            }
            if (($slashes % 2) === 0) {
                $endPos = $i;
                break;
            }
        }

        return $endPos !== null && $endPos > 0;
    }

    /**
     * Строит строгий regex из plain-text строки "как есть".
     *
     * @param string $lineText
     *
     * @return string
     */
    private static function buildStrictLineRegex(string $lineText): string
    {
        return '/' . preg_quote($lineText, '/') . '/iu';
    }

    /**
     * Преобразует фразу в regex для "разреженного" поиска по словам.
     *
     * @param string $phrase
     *
     * @return string
     */
    private static function buildPhraseLineRegex(string $phrase): string
    {
        $words = self::extractRegexWords($phrase);
        if ($words === []) {
            throw new InvalidArgumentException('Параметр lineRegex не содержит валидных слов для построения паттерна.');
        }

        $stems = [];
        foreach ($words as $word) {
            $stems[] = self::stemWordForRegex($word);
        }

        return self::buildDistanceRegexFromStems($stems);
    }

    /**
     * Извлекает слова из произвольной фразы и отбрасывает одиночные буквы.
     *
     * @param string $phrase
     *
     * @return list<string>
     */
    private static function extractRegexWords(string $phrase): array
    {
        $matches = [];
        preg_match_all('/' . self::PHRASE_REGEX_WORD_TOKEN . '/u', $phrase, $matches);
        $words = $matches[0] ?? [];
        if ($words === []) {
            return [];
        }

        $result = [];
        foreach ($words as $word) {
            $word = mb_strtolower(trim($word));
            if ($word === '' || mb_strlen($word) <= 1) {
                continue;
            }
            $result[] = $word;
        }

        return $result;
    }

    /**
     * Ставит слово в "поисковую основу":
     * короткие слова (<=4) не режем, длинные режем гибридно.
     *
     * @param string $word
     *
     * @return string
     */
    private static function stemWordForRegex(string $word): string
    {
        if (mb_strlen($word) <= self::PHRASE_REGEX_SHORT_WORD_MAX_LENGTH) {
            return $word;
        }

        foreach (self::PHRASE_REGEX_KNOWN_ENDINGS as $ending) {
            if (!str_ends_with($word, $ending)) {
                continue;
            }

            $candidate = mb_substr($word, 0, mb_strlen($word) - mb_strlen($ending));
            if (mb_strlen($candidate) >= self::PHRASE_REGEX_MIN_STEM_LENGTH) {
                return $candidate;
            }
        }

        if ((bool) preg_match('/[аяыиоеуюёэьй]$/u', $word)) {
            $fallback = mb_substr($word, 0, mb_strlen($word) - 1);
            if ($fallback !== '' && mb_strlen($fallback) >= self::PHRASE_REGEX_MIN_FALLBACK_STEM_LENGTH) {
                return $fallback;
            }
        }

        return $word;
    }

    /**
     * Строит итоговый regex по списку слов-основ.
     *
     * @param list<string> $stems
     *
     * @return string
     */
    private static function buildDistanceRegexFromStems(array $stems): string
    {
        $parts = [];
        $count = count($stems);
        foreach ($stems as $index => $stem) {
            $parts[] = preg_quote($stem, '/') . '[\p{L}\p{N}_]*';
            if ($index < $count - 1) {
                $parts[] = '(?:[\s\pP]+' . self::PHRASE_REGEX_WORD_TOKEN . '){0,' . self::PHRASE_REGEX_MAX_GAP_WORDS . '}[\s\pP]+';
            }
        }

        return '/' . implode('', $parts) . '/ui';
    }

    /**
     * Нормализует входной паттерн/паттерны к списку корректных regex.
     *
     * Позволяет принимать:
     * - строку (regex или обычный текст);
     * - массив строк (каждая — regex или обычный текст).
     *
     * @param string|array<int,string> $lineRegexOrTexts
     *
     * @return list<string>
     */
    private static function buildLineRegexList(string|array $lineRegexOrTexts): array
    {
        if (is_string($lineRegexOrTexts)) {
            return [self::buildLineRegex($lineRegexOrTexts)];
        }

        if ($lineRegexOrTexts === []) {
            throw new InvalidArgumentException('Параметр lineRegex не должен быть пустым.');
        }

        $result = [];
        foreach ($lineRegexOrTexts as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Параметр lineRegex должен быть строкой или массивом строк.');
            }
            $result[] = self::buildLineRegex($item);
        }

        return $result;
    }

    /**
     * Проверяет совпадение строки хотя бы с одним regex из списка.
     *
     * @param string $line
     * @param list<string> $regexes
     */
    private static function matchesAnyRegex(string $line, array $regexes): bool
    {
        foreach ($regexes as $regex) {
            if (@preg_match($regex, $line) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Делит слишком длинные текстовые блоки на группы предложений.
     *
     * @param array<int, array{kind: string, text: string}> $blocks      Исходные блоки.
     * @param int                                           $targetChars Целевой размер чанка.
     *
     * @return array<int, array{kind: string, text: string}>
     */
    private static function splitLongTextBlocksBySentences(array $blocks, int $targetChars): array
    {
        $result = [];

        foreach ($blocks as $block) {
            if (!in_array($block['kind'], ['paragraph', 'list'], true)) {
                $result[] = $block;
                continue;
            }

            if (mb_strlen($block['text']) <= $targetChars) {
                $result[] = $block;
                continue;
            }

            $parts = self::splitTextIntoSentenceGroups($block['text'], $targetChars);
            foreach ($parts as $part) {
                $result[] = [
                    'kind' => $block['kind'],
                    'text' => $part,
                ];
            }
        }

        return $result;
    }

    /**
     * Собирает итоговые чанки из атомарных блоков жадным алгоритмом.
     *
     * @param array<int, array{kind: string, text: string}> $blocks      Список блоков.
     * @param int                                           $targetChars Целевой размер чанка.
     *
     * @return MarkdownChunksResultDto
     */
    private static function buildChunkResult(array $blocks, int $targetChars): MarkdownChunksResultDto
    {
        $chunks = [];
        $currentTexts = [];
        $currentKinds = [];
        $currentLength = 0;
        $chunkIndex = 0;

        foreach ($blocks as $block) {
            $blockText = $block['text'];
            $blockLength = mb_strlen($blockText);
            $separator = $currentTexts === [] ? '' : "\n\n";
            $projectedLength = $currentLength + mb_strlen($separator) + $blockLength;

            if ($currentTexts !== [] && $projectedLength > $targetChars) {
                $text = implode("\n\n", $currentTexts);
                $chunks[] = new MarkdownChunkDto(
                    index: $chunkIndex,
                    text: $text,
                    lengthChars: mb_strlen($text),
                    blockKinds: array_values($currentKinds),
                    isOversized: mb_strlen($text) > $targetChars
                );
                $chunkIndex++;
                $currentTexts = [];
                $currentKinds = [];
                $currentLength = 0;
            }

            $currentTexts[] = $blockText;
            $currentKinds[$block['kind']] = $block['kind'];
            $currentLength = mb_strlen(implode("\n\n", $currentTexts));
        }

        if ($currentTexts !== []) {
            $text = implode("\n\n", $currentTexts);
            $chunks[] = new MarkdownChunkDto(
                index: $chunkIndex,
                text: $text,
                lengthChars: mb_strlen($text),
                blockKinds: array_values($currentKinds),
                isOversized: mb_strlen($text) > $targetChars
            );
        }

        return new MarkdownChunksResultDto($targetChars, $chunks);
    }

    /**
     * Делит текст на группы предложений, не разрывая слова.
     *
     * @param string $text        Текст для деления.
     * @param int    $targetChars Целевой размер группы.
     *
     * @return string[] Массив текстовых частей.
     */
    private static function splitTextIntoSentenceGroups(string $text, int $targetChars): array
    {
        $sentences = preg_split('/(?<=[.!?…])\s+/u', trim($text)) ?: [];
        if ($sentences === []) {
            return [$text];
        }

        $groups = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $candidate = $current === '' ? $sentence : $current . ' ' . $sentence;
            if ($current !== '' && mb_strlen($candidate) > $targetChars) {
                $groups[] = $current;
                $current = $sentence;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $groups[] = $current;
        }

        return $groups === [] ? [$text] : $groups;
    }

    /**
     * Проверяет, является ли строка маркером fenced-кода.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isFenceLine(string $line): bool
    {
        return (bool) preg_match('/^\s*(`{3,}|~{3,})/', $line);
    }

    /**
     * Проверяет, является ли строка markdown-заголовком.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isHeadingLine(string $line): bool
    {
        return (bool) preg_match('/^\s{0,3}#{1,6}\s+\S/u', $line);
    }

    /**
     * Проверяет, является ли строка элементом списка.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isListLine(string $line): bool
    {
        return (bool) preg_match('/^\s*(?:[-*+]\s+|\d+\.\s+)/u', $line);
    }

    /**
     * Проверяет, является ли строка продолжением элемента списка.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isListContinuationLine(string $line): bool
    {
        return (bool) preg_match('/^\s{2,}\S/u', $line);
    }

    /**
     * Проверяет, может ли текущая строка быть началом таблицы.
     *
     * @param string[] $lines Список строк markdown.
     * @param int      $index Индекс текущей строки.
     *
     * @return bool
     */
    private static function isPotentialTableStart(array $lines, int $index): bool
    {
        if (!isset($lines[$index + 1])) {
            return false;
        }

        return self::isTableRow($lines[$index]) && self::isTableDelimiterRow($lines[$index + 1]);
    }

    /**
     * Проверяет, похожа ли строка на строку таблицы markdown.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isTableRow(string $line): bool
    {
        return (bool) preg_match('/\|/', $line);
    }

    /**
     * Проверяет, является ли строка разделителем заголовка таблицы markdown.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isTableDelimiterRow(string $line): bool
    {
        return (bool) preg_match('/^\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*$/', $line);
    }
}
