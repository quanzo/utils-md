<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function dirname;
use function pathinfo;
use function sprintf;
use function trim;

/**
 * Консольная команда конвертации `docx/xlsx` файла в единый markdown-файл.
 *
 * Пример использования:
 * `php bin/console convert:markdown /path/to/source.docx /path/to/result.md`
 */
class ConvertToMarkdownCommand extends AbstractConvertToMarkdownCommand
{
    /** Имя команды в консоли. */
    protected static $defaultName = 'convert:markdown';

    /**
     * Настраивает описание команды и входные аргументы.
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Преобразует docx/xlsx в markdown-файл')
            ->addArgument('source', InputArgument::REQUIRED, 'Путь к исходному docx/xlsx файлу')
            ->addArgument('target', InputArgument::OPTIONAL, 'Путь к результирующему markdown-файлу');
    }

    /**
     * Выполняет конвертацию документа в markdown.
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

        $markdown = $this->extractNormalizedMarkdown($realSourcePath, $output);
        if ($markdown === null) {
            return Command::FAILURE;
        }

        $targetArgument = (string) $input->getArgument('target');
        $targetPath = $this->resolveTargetPath($realSourcePath, $targetArgument);

        if (!$this->ensureDirectoryExists(dirname($targetPath), $output)) {
            return Command::FAILURE;
        }

        if (!$this->writeMarkdownToFile($targetPath, $markdown, $output)) {
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Markdown сохранён: %s', $targetPath));
        return Command::SUCCESS;
    }

    /**
     * Вычисляет путь к итоговому markdown-файлу.
     *
     * @param string $sourcePath     Абсолютный путь к исходному файлу.
     * @param string $targetArgument Значение аргумента target из CLI.
     *
     * @return string Путь к итоговому markdown-файлу.
     */
    protected function resolveTargetPath(string $sourcePath, string $targetArgument): string
    {
        $normalizedTarget = trim($targetArgument);
        if ($normalizedTarget !== '') {
            return $normalizedTarget;
        }

        $directory = dirname($sourcePath);
        $filenameWithoutExtension = (string) pathinfo($sourcePath, PATHINFO_FILENAME);
        return sprintf('%s/%s.md', $directory, $filenameWithoutExtension);
    }
}
