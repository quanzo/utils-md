<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces;

/**
 * Объект поддердивает преобразование в массив
 */
interface IArrayable
{
    /**
     * Преобразует объект в массив
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
