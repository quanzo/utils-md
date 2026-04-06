## Консольные команды

`utils-md` — небольшой CLI-инструмент (на базе Symfony Console) для конвертации офисных документов в markdown.

### Общие правила

- **Точка входа**: `bin/console.php` (Symfony Console Application).
- **Поддерживаемые форматы**: `docx`, `xlsx`.
- **Зависимость**: требуется установленная CLI-утилита `kreuzberg` и доступность её в `PATH` (команда `command -v kreuzberg` должна возвращать 0).

### Команда `convert:markdown`

Класс: `ConvertToMarkdownCommand` (базовый класс: `AbstractConvertToMarkdownCommand`).

**Назначение**: преобразовать `docx/xlsx` в один markdown-файл.

Аргументы:

- `source` (обязательно) — путь к исходному `docx/xlsx` файлу;
- `target` (опционально) — путь к результирующему `.md` файлу.

Поведение:

- при запуске проверяется доступность `kreuzberg`;
- валидируется входной файл (существует, читаем, расширение `docx|xlsx`);
- markdown извлекается через `kreuzberg extract --output-format markdown --format text`;
- результат очищается через `MarkdownHelper::safeMarkdownWhitespace()`;
- если `target` не указан, рядом с исходным файлом создаётся `<имя_файла>.md`.

Примеры:

- `php bin/console convert:markdown ./docs/report.docx`
- `php bin/console convert:markdown ./docs/report.xlsx ./output/report.md`

### Команда `convert:markdown-chunks`

Класс: `ConvertToMarkdownChunksCommand` (базовый класс: `AbstractConvertToMarkdownCommand`).

**Назначение**: преобразовать `docx/xlsx` в markdown и разбить результат на семантические чанки.

Аргументы:

- `source` (обязательно) — путь к исходному `docx/xlsx` файлу;
- `directory` (опционально) — целевая директория для chunk-файлов;
- `chunk-size` (опционально, по умолчанию `4000`) — размер чанка в символах.

Поведение:

- использует тот же общий пайплайн конвертации, что и `convert:markdown`;
- для разбивки применяет `MarkdownChunckHelper::chunkBySemanticBlocks($markdown, $chunkSize)`;
- если `directory` не задан, создаётся директория рядом с исходным файлом: `<имя_файла>_chunck`;
- чанки сохраняются как `1.md`, `2.md`, `3.md` и т.д.

Примеры:

- `php bin/console convert:markdown-chunks ./docs/report.docx`
- `php bin/console convert:markdown-chunks ./docs/report.xlsx ./chunks 3500`

