<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\classes\command\ConvertToMarkdownCommand;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Тестовый наследник одиночной конвертации с отключаемой проверкой kreuzberg.
 */
final class TestableConvertToMarkdownCommand extends ConvertToMarkdownCommand
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
        return "Первая строка.\n\nВторая строка.";
    }
}
