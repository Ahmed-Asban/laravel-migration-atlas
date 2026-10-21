<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Parsing\PhpParserMigrationParser;

require $argv[1] ?? dirname(__DIR__, 2).'/vendor/autoload.php';

$source = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Support\Facades\{Schema as Structure, DB as Database};

$GLOBALS['migration_atlas_runtime_executed'] = true;
throw new RuntimeException('Top-level migration source must never execute.');

return new class extends Migration {
    protected $connection = 'tenant';
    public function shouldRun(): bool { throw new RuntimeException('Never execute shouldRun.'); }
    public function up(): void {
        Structure::connection('archive')->create('users', function (Table $bp) {
            $bp->id();
            $bp->string('email')
                ->nullable()
                ->default('guest');
        });
        Structure::table('users', fn (Table $bp) => $bp->integer('rank')->default(-2));
        Database::unprepared('ALTER TABLE users ADD unsafe INT');
        throw new RuntimeException('Never execute up.');
    }
    public function down(): void { Structure::drop('users'); }
};
PHP;

$path = tempnam(sys_get_temp_dir(), 'migration-atlas-');

if ($path === false) {
    throw new RuntimeException('Could not create the runtime smoke fixture.');
}

try {
    file_put_contents($path, $source);
    $file = new MigrationFile($path, 'runtime_smoke', null, 7);
    $parser = new PhpParserMigrationParser;
    $result = $parser->parse($file);

    if (! $result->parsed || ! $result->conditional || $result->connection !== 'tenant'
        || $result->file !== $file || count($result->diagnostics) !== 1
        || $result->diagnostics->items[0]->code !== DiagnosticCode::ShouldRunDetected
        || array_column($result->calls, 'method') !== ['create', 'table', 'unprepared', null]
        || $result->calls[0]->connection !== 'archive' || $result->calls[1]->connection !== 'tenant'
        || ! $result->calls[2]->rawSql || count($result->calls[0]->blueprintCalls) !== 2) {
        throw new RuntimeException('Production parser extraction failed: '.json_encode($result, JSON_THROW_ON_ERROR));
    }

    $column = $result->calls[0]->blueprintCalls[1];

    if ($column->rootCall->method !== 'string' || array_column($column->modifiers, 'method') !== ['nullable', 'default']
        || $column->modifiers[0]->line !== 16 || $column->modifiers[1]->line !== 17
        || isset($GLOBALS['migration_atlas_runtime_executed'])) {
        throw new RuntimeException('Production parser provenance or non-execution checks failed.');
    }

    // UTF-8 raw DTOs serialize without leaking mutable AST objects. Binary PHP
    // strings retain their original bytes; renderer encoding is a later layer.
    json_encode($result, JSON_THROW_ON_ERROR);
    file_put_contents($path, '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() { syntax error; } };');
    $broken = $parser->parse($file);

    if ($broken->parsed || $broken->calls !== [] || $broken->file !== $file
        || count($broken->diagnostics) !== 1 || $broken->diagnostics->items[0]->code !== DiagnosticCode::MigrationSyntaxError) {
        throw new RuntimeException('Production parser syntax-failure preservation failed.');
    }

    $oversized = (new PhpParserMigrationParser(16))->parse($file);

    if ($oversized->parsed || $oversized->calls !== [] || $oversized->diagnostics->items[0]->code !== DiagnosticCode::FileTooLarge) {
        throw new RuntimeException('Production parser size safeguard failed.');
    }

    echo 'PASS: production parser, aliases, connection routing, fluent calls, provenance, source guards and non-execution.', PHP_EOL;
} finally {
    unlink($path);
}
