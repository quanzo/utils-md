<?php

$pharFile = 'neuronapp.phar';

if (file_exists($pharFile)) {
    unlink($pharFile);
}

try {
    $phar = new Phar($pharFile, 0, $pharFile);
    $phar->setSignatureAlgorithm(Phar::SHA256);
    $phar->buildFromDirectory(__DIR__ . '/../', '/^(?!.*(?:\.git|tests|build-phar\.php|.*\.phar)).*$/i');
    
    // Создаём заглушку с shebang
    $stub = "#!/usr/bin/env php\n" . $phar->createDefaultStub('bin/console');
    $phar->setStub($stub);

    echo "Phar-архив успешно создан: {$pharFile}\n";

    @chmod($pharFile, 0755);
} catch (Exception $e) {
    echo "Ошибка при создании phar: {$e->getMessage()}\n";
}
