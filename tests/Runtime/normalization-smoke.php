<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\AddForeignKey;
use MigrationAtlas\Operations\ChangeColumn;
use MigrationAtlas\Operations\CreateTable;
use MigrationAtlas\Operations\ExecutionCertainty;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\RenameTable;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Parsing\MigrationSourceReader;
use MigrationAtlas\Parsing\PhpParserMigrationParser;
use MigrationAtlas\Reconstruction\FunctionalSchemaReconstructor;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\TableStatus;

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
            $bp->foreignId('user_id')->constrained();
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
$foreignColumn = $create->operations[4] ?? null;
$foreign = $create->operations[5] ?? null;
$change = $create->operations[6] ?? null;

if (! $result->parsed || ! $create instanceof CreateTable || count($create->columns) !== 5
    || $create->connection !== 'tenant' || $create->execution !== ExecutionCertainty::Conditional
    || $create->columns[0]->type !== 'bigint' || $create->columns[0]->fidelity !== Fidelity::Full
    || $create->columns[1]->default !== 'guest' || ! $create->columns[1]->nullable
    || $create->columns[3]->name !== 'updated_at'
    || ! $foreignColumn instanceof AddColumn || $foreignColumn->column->type !== 'foreignId'
    || $foreignColumn->column->fidelity !== Fidelity::Reduced
    || ! $foreign instanceof AddForeignKey || $foreign->key->name !== 'users_user_id_foreign'
    || $foreign->key->referencedTable !== 'users' || $foreign->key->referencedColumns !== ['id']
    || $foreign->key->fidelity !== Fidelity::Full
    || ! $change instanceof ChangeColumn || $change->column->name !== 'old'
    || $change->column->fidelity !== Fidelity::Reduced
    || ! $rename instanceof RenameTable || $rename->connection !== 'archive'
    || $rename->source->position !== [9, 9] || count($result->diagnostics) !== 3
    || isset($GLOBALS['migration_atlas_normalization_smoke_executed'])) {
    throw new RuntimeException('Production normalization smoke failed.');
}

$replayed = (new FunctionalSchemaReconstructor)->replay([$result]);
$users = $replayed->registry->byName('tenant', 'users');

if (count($users) !== 1 || $users[0]->status !== TableStatus::Incomplete
    || $users[0]->existence !== Existence::Unknown
    || count($users[0]->columns) !== 5 || $users[0]->columns['id']->type !== 'bigint'
    || count($users[0]->foreignKeys) !== 1 || $users[0]->foreignKeys[0]->name !== 'users_user_id_foreign'
    || $replayed->migrationsAnalyzed !== 1 || $replayed->operationsApplied !== 9) {
    throw new RuntimeException('Production reconstruction smoke failed.');
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
