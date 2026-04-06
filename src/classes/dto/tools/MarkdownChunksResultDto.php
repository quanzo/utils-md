<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

use function array_map;

/**
 * DTO результата семантического разбиения markdown на чанки.
 *
 * Хранит целевой размер и список получившихся чанков
 * с агрегированными метаданными по всему результату.
 *
 * Пример использования:
 * `$result = new MarkdownChunksResultDto(800, [$chunkDto]);`
 */
final class MarkdownChunksResultDto implements IArrayable
{
    /**
     * @param int                $targetChars Целевой размер чанка в символах
     * @param MarkdownChunkDto[] $chunks      Список чанков
     */
    public function __construct(
        public readonly int $targetChars,
        public readonly array $chunks,
    ) {
    }

    /**
     * Возвращает количество чанков.
     *
     * @return int
     */
    public function getTotalChunks(): int
    {
        return count($this->chunks);
    }

    /**
     * Возвращает суммарную длину всех чанков в символах.
     *
     * @return int
     */
    public function getTotalChars(): int
    {
        $total = 0;
        foreach ($this->chunks as $chunk) {
            $total += $chunk->lengthChars;
        }

        return $total;
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{
     *     targetChars: int,
     *     totalChunks: int,
     *     totalChars: int,
     *     chunks: array<int, array{
     *         index: int,
     *         text: string,
     *         lengthChars: int,
     *         blockKinds: string[],
     *         isOversized: bool
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'targetChars' => $this->targetChars,
            'totalChunks' => $this->getTotalChunks(),
            'totalChars' => $this->getTotalChars(),
            'chunks' => array_map(
                static fn (MarkdownChunkDto $chunk): array => $chunk->toArray(),
                $this->chunks
            ),
        ];
    }
}
