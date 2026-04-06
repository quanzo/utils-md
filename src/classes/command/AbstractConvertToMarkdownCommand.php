<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\helpers\MarkdownHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function is_readable;
use function mkdir;
use function pathinfo;
use function realpath;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Абстрактная базовая команда преобразования офисных документов в markdown.
 *
 * Инкапсулирует общий пайплайн:
 * - проверка доступности `kreuzberg`;
 * - валидация исходного файла (`docx`/`xlsx`);
 * - извлечение markdown из файла через CLI;
 * - безопасная очистка markdown через {@see MarkdownHelper::safeMarkdownWhitespace()}.
 *
 * Пример использования:
 * `class ConvertToMarkdownCommand extends AbstractConvertToMarkdownCommand`
 */
abstract class AbstractConvertToMarkdownCommand extends Command
{
    /**
     * Проверяет исходный файл и возвращает его канонический путь.
     *
     * @param string          $sourcePath Путь к исходному документу.
     * @param OutputInterface $output     Вывод консоли для сообщений об ошибках.
     *
     * @return string|null Канонический путь к файлу или null при ошибке.
     */
    protected function validateSourcePath(string $sourcePath, OutputInterface $output): ?string
    {
        $sourcePath = trim($sourcePath);
        if ($sourcePath === '') {
            $output->writeln('<error>Не указан путь к исходному файлу.</error>');
            return null;
        }

        $realSourcePath = realpath($sourcePath);
        if ($realSourcePath === false || !is_file($realSourcePath) || !is_readable($realSourcePath)) {
            $output->writeln(sprintf('<error>Файл "%s" не найден или недоступен для чтения.</error>', $sourcePath));
            return null;
        }

        $extension = strtolower((string) pathinfo($realSourcePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['docx', 'xlsx'], true)) {
            $output->writeln('<error>Поддерживаются только файлы с расширениями docx и xlsx.</error>');
            return null;
        }

        return $realSourcePath;
    }

    /**
     * Проверяет наличие CLI-утилиты kreuzberg в окружении запуска.
     *
     * @param OutputInterface $output Вывод консоли для сообщений об ошибках.
     *
     * @return bool true, если `kreuzberg` доступен.
     */
    protected function ensureKreuzbergAvailable(OutputInterface $output): bool
    {
        $commandOutput = [];
        $exitCode = 1;

        exec('command -v kreuzberg >/dev/null 2>&1', $commandOutput, $exitCode);
        if ($exitCode !== 0) {
            $output->writeln('<error>Утилита kreuzberg не найдена. Установите kreuzberg и повторите запуск.</error>');
            return false;
        }

        return true;
    }

    /**
     * Извлекает markdown из документа через kreuzberg и очищает пробельные артефакты.
     *
     * @param string          $sourcePath Абсолютный путь к исходному документу.
     * @param OutputInterface $output     Вывод консоли для сообщений об ошибках.
     *
     * @return string|null Готовый markdown или null при ошибке.
     */
    protected function extractNormalizedMarkdown(string $sourcePath, OutputInterface $output): ?string
    {
        $command = sprintf(
            'kreuzberg extract --output-format markdown --format text %s 2>&1',
            escapeshellarg($sourcePath)
        );

        $commandOutput = [];
        $exitCode = 1;
        exec($command, $commandOutput, $exitCode);

        if ($exitCode !== 0) {
            $errorDetails = trim(implode("\n", $commandOutput));
            if ($errorDetails === '') {
                $errorDetails = 'Неизвестная ошибка выполнения kreuzberg.';
            }

            $output->writeln('<error>Не удалось выполнить конвертацию через kreuzberg.</error>');
            $output->writeln(sprintf('<error>%s</error>', $errorDetails));
            return null;
        }

        $markdown = implode("\n", $commandOutput);
        return MarkdownHelper::safeMarkdownWhitespace($markdown);
    }

    /**
     * Создаёт директорию рекурсивно, если она отсутствует.
     *
     * @param string          $directoryPath Путь к целевой директории.
     * @param OutputInterface $output        Вывод консоли для сообщений об ошибках.
     *
     * @return bool true, если директория существует или успешно создана.
     */
    protected function ensureDirectoryExists(string $directoryPath, OutputInterface $output): bool
    {
        if (is_dir($directoryPath)) {
            return true;
        }

        if (!mkdir($directoryPath, 0775, true) && !is_dir($directoryPath)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию "%s".</error>', $directoryPath));
            return false;
        }

        return true;
    }

    /**
     * Сохраняет текст в файл.
     *
     * @param string          $filePath Путь к целевому файлу.
     * @param string          $content  Содержимое для записи.
     * @param OutputInterface $output   Вывод консоли для сообщений об ошибках.
     *
     * @return bool true, если запись прошла успешно.
     */
    protected function writeMarkdownToFile(string $filePath, string $content, OutputInterface $output): bool
    {
        $bytesWritten = file_put_contents($filePath, $content);
        if ($bytesWritten === false) {
            $output->writeln(sprintf('<error>Не удалось записать файл "%s".</error>', $filePath));
            return false;
        }

        return true;
    }
}
