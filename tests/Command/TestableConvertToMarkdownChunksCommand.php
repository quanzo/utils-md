<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\classes\command\ConvertToMarkdownChunksCommand;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Тестовый наследник чанк-команды с отключаемой проверкой kreuzberg.
 */
final class TestableConvertToMarkdownChunksCommand extends ConvertToMarkdownChunksCommand
{
    private bool $kreuzbergAvailable = true;

    /**
     * Устанавливает состояние доступности kreuzberg для теста.
     *
     * @param bool $isAvailable Флаг доступности.
     *
     * @return $this
     */
    public function setKreuzbergAvailable(bool $isAvailable): self
    {
        $this->kreuzbergAvailable = $isAvailable;
        return $this;
    }

    /**
     * Переопределяет проверку зависимости, чтобы детерминировать тестовый сценарий.
     *
     * @param OutputInterface $output Вывод консоли.
     *
     * @return bool true, если зависимость считается доступной.
     */
    protected function ensureKreuzbergAvailable(OutputInterface $output): bool
    {
        if (!$this->kreuzbergAvailable) {
            $output->writeln(
                '<error>Утилита kreuzberg не найдена. Установите kreuzberg и повторите запуск.</error>'
            );
            return false;
        }

        return true;
    }

    /**
     * Подменяет извлечение markdown предсказуемым тестовым контентом.
     *
     * @param string          $sourcePath Путь к исходному файлу.
     * @param OutputInterface $output     Вывод консоли.
     *
     * @return string
     */
    protected function extractNormalizedMarkdown(string $sourcePath, OutputInterface $output): ?string
    {
        return 'Первое длинное предложение для разбиения. Второе длинное предложение для разбиения.';
    }
}
