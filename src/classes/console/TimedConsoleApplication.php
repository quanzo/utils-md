<?php

declare(strict_types=1);

namespace app\utilsmd\classes\console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Консольное приложение с выводом длительности выполнения выбранной команды.
 *
 * Замер покрывает работу {@see Command::run()} (включая merge/binding определения
 * внутри Symfony), но не включает поиск команды по имени и разбор глобальных опций
 * приложения. Строка с временем пишется в stderr (если доступен отдельный поток),
 * чтобы не портить перенаправление основного вывода в pipe.
 *
 * Пример:
 *
 * ```php
 * $app = new TimedConsoleApplication('app', '0.0.1');
 * $app->add(new HelloCommand());
 * $app->run();
 * ```
 */
class TimedConsoleApplication extends Application
{
    /**
     * Запускает команду и по завершении (успех или исключение) выводит затраченное время.
     *
     * Делегирует родителю и в блоке finally дописывает строку длительности.
     *
     * @param Command $command Выполняемая команда.
     * @param InputInterface $input Входные аргументы и опции.
     * @param OutputInterface $output Поток вывода.
     *
     * @return int Код завершения команды (0 — успех).
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        $startNs = hrtime(true);
        try {
            return parent::doRunCommand($command, $input, $output);
        } finally {
            $this->writeExecutionDuration($output, $startNs);
        }
    }

    /**
     * Форматирует и выводит длительность выполнения команды.
     *
     * При тихом режиме вывода (-q / VERBOSITY_QUIET) строка не печатается.
     *
     * @param OutputInterface $output Целевой поток вывода.
     * @param int|float|false $startNs Значение {@see hrtime()} в начале выполнения.
     *
     * @return void
     */
    private function writeExecutionDuration(OutputInterface $output, int|float|false $startNs): void
    {
        if ($output->isQuiet()) {
            return;
        }

        if ($startNs === false) {
            return;
        }

        $endNs = hrtime(true);
        if ($endNs === false) {
            return;
        }

        $seconds = ((float) $endNs - (float) $startNs) / 1_000_000_000.0;
        $line = sprintf('<comment>Время выполнения: %.3f с</comment>', $seconds);

        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln($line);
    }
}
