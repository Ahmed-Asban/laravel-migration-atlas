<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Operations\CreateTable;
use MigrationAtlas\Operations\ExecutionCertainty;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\RenameTable;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Parsing\MigrationSourceReader;
use MigrationAtlas\Parsing\PhpParserMigrationParser;

require $argv[1] ?? dirname(__DIR__, 2).'/vendor/autoload.php';

$source = <<<'PHP'
<?php
use Illuminate\Support\Facades\{Schema as Structure, DB};
$GLOBALS['migration_atlas_normalization_smoke_executed'] = true;
throw new RuntimeException('Never execute source.');
return new class extends \Illuminate\Database\Migrations\Migration {
    protected $connection = 'tenant';
    public function shouldRun() { throw new RuntimeException('Never execute shouldRun.'); }
    public function up() {
        Structure::create('users', function ($bp) {
            $bp->id();
            $bp->string('name', 80)->nullable()->default('guest');
            $bp->timestamps();
            $bp->string('old')->change();
        });
        Structure::connection('archive')->rename('users', 'members');
        DB::unprepared('DROP TABLE arbitrary');
    }
};
PHP;

$reader = new class($source) implements MigrationSourceReader
{
    public function __construct(public string $source) {}

    public function read(MigrationFile $file, int $maxFileSize): string
    {
        return $this->source;
    }
};
$file = new MigrationFile('/fixtures/runtime-normalization.php', 'runtime_normalization', null, 9);
$parser = new PhpParserMigrationParser(sources: $reader);
$normalizer = new OperationNormalizer;
$result = $normalizer->normalize($parser->parse($file));
$create = $result->operations[0] ?? null;
$rename = $result->operations[1] ?? null;

if (! $result->parsed || ! $create instanceof CreateTable || count($create->columns) !== 4
    || $create->connection !== 'tenant' || $create->execution !== ExecutionCertainty::Conditional
    || $create->columns[0]->type !== 'bigint' || $create->columns[0]->fidelity !== Fidelity::Full
    || $create->columns[1]->default !== 'guest' || ! $create->columns[1]->nullable
    || $create->columns[3]->name !== 'updated_at' || ! $create->operations[4] instanceof UnresolvedOperation
    || ! $rename instanceof RenameTable || $rename->connection !== 'archive'
    || $rename->source->position !== [9, 7] || count($result->diagnostics) !== 4
    || isset($GLOBALS['migration_atlas_normalization_smoke_executed'])) {
    throw new RuntimeException('Production normalization smoke failed.');
}

$reader->source = '<?php syntax error;';
$failed = $normalizer->normalize($parser->parse($file));
$marker = $failed->operations[0] ?? null;

if ($failed->parsed || ! $marker instanceof UnresolvedOperation || ! $marker->wholeFileUnknown
    || $marker->source->position !== [9, 0] || $marker->reason !== DiagnosticCode::MigrationSyntaxError
    || count($failed->operations) !== 1 || count($failed->diagnostics) !== 1) {
    throw new RuntimeException('Production failed-file normalization smoke failed.');
}

echo "Production normalization smoke passed.\n";
