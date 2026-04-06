<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\helpers\MarkdownChunckHelper;
use app\modules\neuron\helpers\MarkdownHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function dirname;
use function file_get_contents;
use function is_file;
use function is_readable;
use function pathinfo;
use function realpath;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Консольная команда конвертации `docx/xlsx` в набор markdown-чанков.
 *
 * Пример использования:
 * `php bin/console convert:markdown-chunks /path/to/source.docx /path/to/target/dir 4000`
 */
class ConvertToMarkdownChunksCommand extends AbstractConvertToMarkdownCommand
{
    /** Имя команды в консоли. */
    protected static $defaultName = 'convert:markdown-chunks';

    /**
     * Настраивает описание команды и входные аргументы.
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Преобразует docx/xlsx в markdown и разбивает на чанки')
            ->addArgument(
                'source',
                InputArgument::OPTIONAL,
                'Путь к исходному docx/xlsx или .md файлу. При --text воспринимается как markdown-текст. Если не указан — читается из STDIN'
            )
            ->addArgument('directory', InputArgument::OPTIONAL, 'Директория для файлов-чанков')
            ->addArgument('chunk-size', InputArgument::OPTIONAL, 'Размер чанка в символах', '4000')
            ->addOption('text', null, InputOption::VALUE_NONE, 'Интерпретировать source как markdown-текст');
    }

    /**
     * Выполняет конвертацию документа в markdown-чанки.
     *
     * @param InputInterface  $input  Ввод консольной команды.
     * @param OutputInterface $output Вывод консольной команды.
     *
     * @return int Код завершения команды.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $chunkSizeRaw = (string) $input->getArgument('chunk-size');
        $chunkSize = (int) trim($chunkSizeRaw);
        if ($chunkSize <= 0) {
            $output->writeln('<error>Размер чанка должен быть положительным целым числом.</error>');
            return Command::FAILURE;
        }

        $directoryArgument = (string) $input->getArgument('directory');
        $normalizedDirectoryArgument = trim($directoryArgument);

        $sourceArgument = $input->getArgument('source');
        $sourceRaw = $sourceArgument === null ? '' : (string) $sourceArgument;
        $sourceRaw = trim($sourceRaw);

        $isTextMode = (bool) $input->getOption('text');
        $markdown = null;
        $targetDirectory = null;

        if ($isTextMode) {
            if ($normalizedDirectoryArgument === '') {
                $output->writeln('<error>Для режима --text требуется указать директорию для чанков (аргумент directory).</error>');
                return Command::FAILURE;
            }

            $markdown = MarkdownHelper::safeMarkdownWhitespace($sourceRaw);
            $targetDirectory = $normalizedDirectoryArgument;
        } elseif ($sourceRaw === '') {
            if ($normalizedDirectoryArgument === '') {
                $output->writeln('<error>При чтении из STDIN требуется указать директорию для чанков (аргумент directory).</error>');
                return Command::FAILURE;
            }

            $stdinMarkdown = $this->readMarkdownFromStdin();
            $markdown = MarkdownHelper::safeMarkdownWhitespace($stdinMarkdown);
            $targetDirectory = $normalizedDirectoryArgument;
        } else {
            $realSourcePath = $this->tryResolveReadableFile($sourceRaw);

            if ($realSourcePath !== null && $this->isMarkdownFile($realSourcePath)) {
                $content = file_get_contents($realSourcePath);
                if ($content === false) {
                    $output->writeln(sprintf('<error>Не удалось прочитать файл "%s".</error>', $sourceRaw));
                    return Command::FAILURE;
                }

                $markdown = MarkdownHelper::safeMarkdownWhitespace($content);
                $targetDirectory = $this->resolveTargetDirectory($realSourcePath, $directoryArgument);
            } else {
                if (!$this->ensureKreuzbergAvailable($output)) {
                    return Command::FAILURE;
                }

                $validatedSourcePath = $this->validateSourcePath($sourceRaw, $output);
                if ($validatedSourcePath === null) {
                    return Command::FAILURE;
                }

                $markdown = $this->extractNormalizedMarkdown($validatedSourcePath, $output);
                if ($markdown === null) {
                    return Command::FAILURE;
                }

                $targetDirectory = $this->resolveTargetDirectory($validatedSourcePath, $directoryArgument);
            }
        }

        if ($targetDirectory === null) {
            $output->writeln('<error>Не удалось определить директорию для чанков.</error>');
            return Command::FAILURE;
        }

        if (!$this->ensureDirectoryExists($targetDirectory, $output)) {
            return Command::FAILURE;
        }

        $chunkResult = MarkdownChunckHelper::chunkBySemanticBlocks($markdown, $chunkSize);
        if ($chunkResult->chunks === []) {
            $output->writeln('<comment>После конвертации получен пустой markdown, чанки не созданы.</comment>');
            return Command::SUCCESS;
        }

        foreach ($chunkResult->chunks as $chunk) {
            $chunkNumber = $chunk->index + 1;
            $chunkPath = sprintf('%s/%d.md', $targetDirectory, $chunkNumber);

            if (!$this->writeMarkdownToFile($chunkPath, $chunk->text, $output)) {
                return Command::FAILURE;
            }
        }

        $output->writeln(sprintf('Чанки сохранены в директорию: %s', $targetDirectory));
        $output->writeln(sprintf('Количество чанков: %d', $chunkResult->getTotalChunks()));
        return Command::SUCCESS;
    }

    /**
     * Считывает markdown из STDIN.
     *
     * @return string Текст из STDIN (может быть пустым).
     */
    protected function readMarkdownFromStdin(): string
    {
        $content = file_get_contents('php://stdin');
        if ($content === false) {
            return '';
        }

        return $content;
    }

    /**
     * Пробует разрешить путь до читаемого файла без требований к расширению.
     *
     * @param string $path Путь к файлу (возможно относительный).
     *
     * @return string|null Канонический путь к файлу или null.
     */
    private function tryResolveReadableFile(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        if (!is_file($real) || !is_readable($real)) {
            return null;
        }

        return $real;
    }

    /**
     * Проверяет, является ли путь markdown-файлом по расширению.
     *
     * @param string $path Абсолютный путь.
     *
     * @return bool
     */
    private function isMarkdownFile(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return $extension === 'md';
    }

    /**
     * Вычисляет директорию для сохранения markdown-чанков.
     *
     * @param string $sourcePath        Абсолютный путь к исходному файлу.
     * @param string $directoryArgument Значение аргумента directory из CLI.
     *
     * @return string Путь к директории с чанк-файлами.
     */
    protected function resolveTargetDirectory(string $sourcePath, string $directoryArgument): string
    {
        $normalizedDirectory = trim($directoryArgument);
        if ($normalizedDirectory !== '') {
            return $normalizedDirectory;
        }

        $sourceDirectory = dirname($sourcePath);
        $filenameWithoutExtension = (string) pathinfo($sourcePath, PATHINFO_FILENAME);
        return sprintf('%s/%s_chunck', $sourceDirectory, $filenameWithoutExtension);
    }
}
