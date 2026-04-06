<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\classes\command\ConvertToMarkdownChunksCommand;
use app\modules\neuron\classes\command\ConvertToMarkdownCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты команд конвертации документов в markdown.
 */
final class MarkdownConvertCommandsTest extends TestCase
{
    /**
     * Проверяет, что команда convert:markdown содержит ожидаемые аргументы.
     */
    public function testConvertToMarkdownCommandHasExpectedArguments(): void
    {
        $command = new ConvertToMarkdownCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('source'));
        $this->assertTrue($definition->hasArgument('target'));
    }

    /**
     * Проверяет, что команда convert:markdown-chunks содержит ожидаемые аргументы и дефолт chunk-size.
     */
    public function testConvertToMarkdownChunksCommandHasExpectedArgumentsAndDefaultChunkSize(): void
    {
        $command = new ConvertToMarkdownChunksCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('source'));
        $this->assertTrue($definition->hasArgument('directory'));
        $this->assertTrue($definition->hasArgument('chunk-size'));
        $this->assertSame('4000', $definition->getArgument('chunk-size')->getDefault());
    }

    /**
     * Проверяет сохранение markdown в путь по умолчанию рядом с исходным файлом.
     */
    public function testConvertToMarkdownWritesResultToDefaultPath(): void
    {
        $baseDir = $this->createTempDir();
        $sourcePath = $baseDir . '/sample.docx';
        file_put_contents($sourcePath, 'binary');

        $command = new TestableConvertToMarkdownCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source' => $sourcePath,
        ]);

        $targetPath = $baseDir . '/sample.md';
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue(file_exists($targetPath));
    }

    /**
     * Проверяет сохранение markdown в явно указанный целевой путь.
     */
    public function testConvertToMarkdownWritesResultToExplicitPath(): void
    {
        $baseDir = $this->createTempDir();
        $sourcePath = $baseDir . '/sample.docx';
        $targetPath = $baseDir . '/result/custom.md';
        file_put_contents($sourcePath, 'binary');

        $command = new TestableConvertToMarkdownCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source' => $sourcePath,
            'target' => $targetPath,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue(file_exists($targetPath));
    }

    /**
     * Проверяет ошибку при неподдерживаемом расширении входного файла.
     */
    public function testConvertToMarkdownFailsForUnsupportedExtension(): void
    {
        $baseDir = $this->createTempDir();
        $sourcePath = $baseDir . '/sample.txt';
        file_put_contents($sourcePath, 'text');

        $command = new TestableConvertToMarkdownCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source' => $sourcePath,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Поддерживаются только файлы с расширениями docx и xlsx',
            $tester->getDisplay()
        );
    }

    /**
     * Проверяет ошибку при недоступном kreuzberg в команде одиночной конвертации.
     */
    public function testConvertToMarkdownFailsWhenKreuzbergUnavailable(): void
    {
        $baseDir = $this->createTempDir();
        $sourcePath = $baseDir . '/sample.docx';
        file_put_contents($sourcePath, 'binary');

        $command = new TestableConvertToMarkdownCommand();
        $command->setKreuzbergAvailable(false);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source' => $sourcePath,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Утилита kreuzberg не найдена', $tester->getDisplay());
    }

    /**
     * Проверяет создание директории по умолчанию с суффиксом _chunck.
     */
    public function testConvertToMarkdownChunksCreatesDefaultDirectory(): void
    {
        $baseDir = $this->createTempDir();
        $sourcePath = $baseDir . '/table.xlsx';
        file_put_contents($sourcePath, 'binary');

        $command = new TestableConvertToMarkdownChunksCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source' => $sourcePath,
        ]);

        $targetDirectory = $baseDir . '/table_chunck';
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue(is_dir($targetDirectory));
    }

    /**
     * Проверяет нумерацию и запись chunk-файлов как 1.md, 2.md, ...
     */
    public function testConvertToMarkdownChunksWritesNumberedFiles(): void
    {
        $baseDir = $this->createTempDir();
        $sourcePath = $baseDir . '/long.docx';
        $targetDirectory = $baseDir . '/chunks';
        file_put_contents($sourcePath, 'binary');

        $command = new TestableConvertToMarkdownChunksCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source' => $sourcePath,
            'directory' => $targetDirectory,
            'chunk-size' => '20',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue(file_exists($targetDirectory . '/1.md'));
        $this->assertTrue(file_exists($targetDirectory . '/2.md'));
    }

    /**
     * Проверяет ошибку при некорректном значении размера чанка.
     */
    public function testConvertToMarkdownChunksFailsForInvalidChunkSize(): void
    {
        $baseDir = $this->createTempDir();
        $sourcePath = $baseDir . '/long.docx';
        file_put_contents($sourcePath, 'binary');

        $command = new TestableConvertToMarkdownChunksCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source' => $sourcePath,
            'chunk-size' => '0',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Размер чанка должен быть положительным целым числом', $tester->getDisplay());
    }

    /**
     * Проверяет ошибку при недоступном kreuzberg в команде чанкования.
     */
    public function testConvertToMarkdownChunksFailsWhenKreuzbergUnavailable(): void
    {
        $baseDir = $this->createTempDir();
        $sourcePath = $baseDir . '/table.xlsx';
        file_put_contents($sourcePath, 'binary');

        $command = new TestableConvertToMarkdownChunksCommand();
        $command->setKreuzbergAvailable(false);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source' => $sourcePath,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Утилита kreuzberg не найдена', $tester->getDisplay());
    }

    /**
     * Создаёт уникальную временную директорию для тестового сценария.
     *
     * @return string Путь к созданной директории.
     */
    private function createTempDir(): string
    {
        $directory = sprintf('%s/neuronapp_test_%s', sys_get_temp_dir(), uniqid());
        mkdir($directory, 0775, true);
        return $directory;
    }
}
