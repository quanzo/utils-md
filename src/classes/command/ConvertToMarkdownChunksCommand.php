<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\helpers\MarkdownChunckHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function dirname;
use function pathinfo;
use function sprintf;
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
            ->addArgument('source', InputArgument::REQUIRED, 'Путь к исходному docx/xlsx файлу')
            ->addArgument('directory', InputArgument::OPTIONAL, 'Директория для файлов-чанков')
            ->addArgument('chunk-size', InputArgument::OPTIONAL, 'Размер чанка в символах', '4000');
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
        if (!$this->ensureKreuzbergAvailable($output)) {
            return Command::FAILURE;
        }

        $sourcePath = (string) $input->getArgument('source');
        $realSourcePath = $this->validateSourcePath($sourcePath, $output);
        if ($realSourcePath === null) {
            return Command::FAILURE;
        }

        $chunkSizeRaw = (string) $input->getArgument('chunk-size');
        $chunkSize = (int) trim($chunkSizeRaw);
        if ($chunkSize <= 0) {
            $output->writeln('<error>Размер чанка должен быть положительным целым числом.</error>');
            return Command::FAILURE;
        }

        $markdown = $this->extractNormalizedMarkdown($realSourcePath, $output);
        if ($markdown === null) {
            return Command::FAILURE;
        }

        $directoryArgument = (string) $input->getArgument('directory');
        $targetDirectory = $this->resolveTargetDirectory($realSourcePath, $directoryArgument);
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
