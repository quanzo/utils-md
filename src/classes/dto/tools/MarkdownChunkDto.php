<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO одного семантического markdown-чанка.
 *
 * Содержит текст чанка и базовые метаданные, описывающие
 * размер и типы блоков, которые вошли в данный чанк.
 *
 * Пример использования:
 * `$chunkDto = new MarkdownChunkDto(0, 'Text', 4, ['paragraph'], false);`
 */
final class MarkdownChunkDto implements IArrayable
{
    /**
     * @param int      $index      Индекс чанка (0-based)
     * @param string   $text       Текст чанка
     * @param int      $lengthChars Длина чанка в символах
     * @param string[] $blockKinds Типы блоков внутри чанка
     * @param bool     $isOversized Флаг чанка, превышающего целевой размер
     */
    public function __construct(
        public readonly int $index,
        public readonly string $text,
        public readonly int $lengthChars,
        public readonly array $blockKinds,
        public readonly bool $isOversized,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{
     *     index: int,
     *     text: string,
     *     lengthChars: int,
     *     blockKinds: string[],
     *     isOversized: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'text' => $this->text,
            'lengthChars' => $this->lengthChars,
            'blockKinds' => $this->blockKinds,
            'isOversized' => $this->isOversized,
        ];
    }
}
