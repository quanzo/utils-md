<?php

require __DIR__ . '/../vendor/autoload.php';

use app\utilsmd\classes\command\ConvertToMarkdownChunksCommand;
use app\utilsmd\classes\command\ConvertToMarkdownCommand;
use app\utilsmd\classes\console\TimedConsoleApplication;

define('APP_ID', 'utils-md');

$app = new TimedConsoleApplication(APP_ID, '0.0.1');
$arDirs = [];

/**
 * Директория в папке старта
 * 
 * Если в папке старта приложения есть папка `.neauronapp` то считаем эту папку приоритетной
 */
$startDir = !defined('APP_START_DIR') ? getcwd() . DIRECTORY_SEPARATOR . '.' . APP_ID : APP_START_DIR;
if (is_dir($startDir)) {
    $arDirs['APP_START_DIR'] = $startDir;
}

/**
 * Директория приложения в домашней папке пользователя
 */
if (!defined('APP_WORK_DIR')) {
    $homeDir = getenv('HOME');

    if ($homeDir === false || $homeDir === '') {
        $homeDir = $_SERVER['HOME'] ?? '';
    }

    if ($homeDir === '' || !is_dir($homeDir)) {
        fwrite(STDERR, "Unable to determine user home directory.\n");
        exit(1);
    }

    $workDir = $homeDir . DIRECTORY_SEPARATOR . '.' . APP_ID;
    if (!is_dir($workDir)) {
        if (!mkdir($workDir, 0777, true) && !is_dir($workDir)) {
            fwrite(STDERR, sprintf("Unable to create application directory: %s\n", $workDir));
            exit(1);
        }
    }
} else {
    $workDir = APP_WORK_DIR;
    if (!is_dir($workDir)) {
        fwrite(STDERR, sprintf("Unable to finde application directory: %s\n", $workDir));
        exit(1);
    }
}
$arDirs['APP_WORK_DIR'] = $workDir;

// Регистрируем команды
$app->add(new ConvertToMarkdownCommand());
$app->add(new ConvertToMarkdownChunksCommand());

// Можно также добавить встроенную команду list, которая уже есть в Symfony,
// поэтому отдельная HelpCommand не требуется, но при желании можно добавить.

$app->run();
