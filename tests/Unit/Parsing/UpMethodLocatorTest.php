<?php

declare(strict_types=1);

use MigrationAtlas\Parsing\MigrationContext;
use MigrationAtlas\Parsing\UpMethodLocator;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

function locateMigrationContextForTest(string $source): ?MigrationContext
{
    $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$source);
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);

    return (new UpMethodLocator)->locate($traverser->traverse($statements ?? []));
}

it('locates named and directly returned anonymous migrations using resolved names', function (string $source) {
    $context = locateMigrationContextForTest($source);

    expect($context)->toBeInstanceOf(MigrationContext::class)
        ->and($context?->up?->name->toString())->toBe('up')
        ->and($context?->up?->stmts)->toBe([])
        ->and($context?->conditional)->toBeFalse()
        ->and($context?->connection)->toBe('default');
})->with([
    'named imported' => ['use Illuminate\\Database\\Migrations\\Migration; class CreateUsers extends Migration { public function up() {} }'],
    'anonymous imported' => ['use Illuminate\\Database\\Migrations\\Migration; return new class extends Migration { public function up() {} };'],
    'named alias' => ['use Illuminate\\Database\\Migrations\\Migration as Base; class CreateUsers extends Base { public function up() {} }'],
    'grouped alias in namespace' => ['namespace App\\Database; use Illuminate\\Database\\Migrations\\{Migration as Base}; return new class extends Base { public function up() {} };'],
    'fully qualified' => ['class CreateUsers extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} }'],
    'case insensitive class' => ['class CreateUsers extends \\illuminate\\DATABASE\\Migrations\\mIGRATION { public function up() {} }'],
    'braced namespace' => ['namespace App\\Database { use Illuminate\\Database\\Migrations\\Migration; class CreateUsers extends Migration { public function up() {} } }'],
    'declare wrapper' => ['declare(ticks=1) { return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} }; }'],
]);

it('detects shouldRun by method presence without executing or evaluating its body', function (string $body) {
    $context = locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration {
        public function up() { throw new \\RuntimeException("Never execute up"); }
        public function ShOuLdRuN() { '.$body.' }
    };');

    expect($context?->conditional)->toBeTrue()
        ->and($context?->up?->stmts)->toHaveCount(1);
})->with(['return true;', 'return false;', 'throw new \\RuntimeException("Never execute shouldRun");']);

it('looks up up methods case insensitively', function () {
    expect(locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function UP() {} };')?->up?->name->toString())
        ->toBe('UP');
});

it('preserves a missing up method rather than pretending the migration is an empty no-op', function () {
    $context = locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function down() {} };');

    expect($context)->toBeInstanceOf(MigrationContext::class)
        ->and($context?->up)->toBeNull();
});

it('preserves bodyless up nodes so the parser can diagnose uninspectable source', function () {
    $context = locateMigrationContextForTest('abstract class Pending extends \\Illuminate\\Database\\Migrations\\Migration { abstract public function up(); }');

    expect($context?->up)->not->toBeNull()
        ->and($context?->up?->stmts)->toBeNull();
});

it('does not search arbitrary nested declarations or non-returned anonymous classes', function (string $source) {
    expect(locateMigrationContextForTest($source))->toBeNull();
})->with([
    'no parent' => ['class Helper { public function up() {} }'],
    'conditional class' => ['if (true) { class Hidden extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} } }'],
    'conditional return' => ['if (true) { return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} }; }'],
    'function' => ['function factory() { return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} }; }'],
    'closure' => ['$factory = function () { return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} }; };'],
    'assigned anonymous class' => ['$migration = new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} };'],
    'down nested candidate' => ['class Helper { public function down() { return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} }; } }'],
    'custom parent without own up' => ['class Missing extends CustomMigration {}'],
]);

it('rejects ambiguous migration candidates', function () {
    expect(locateMigrationContextForTest('use Illuminate\\Database\\Migrations\\Migration;
        class First extends Migration { public function up() {} }
        return new class extends Migration { public function up() {} };'))
        ->toBeNull();
});

it('retains custom inheritance as an inspectable up body with unresolved routing', function (string $source) {
    $context = locateMigrationContextForTest($source);

    expect($context)->toBeInstanceOf(MigrationContext::class)
        ->and($context?->up)->not->toBeNull()
        ->and($context?->connection)->toBeNull();
})->with([
    'named custom' => ['class CreateUsers extends CustomMigration { protected $connection = "archive"; public function up() {} }'],
    'anonymous custom' => ['return new class extends CustomMigration { protected $connection = "archive"; public function up() {} };'],
    'global bare Migration' => ['class CreateUsers extends Migration { public function up() {} }'],
    'namespaced bare Migration' => ['namespace App; class CreateUsers extends Migration { public function up() {} }'],
]);

it('reads literal routing declarations and Laravel falsy string fallbacks', function (string $declaration, ?string $expected) {
    $context = locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration { '.$declaration.' public function up() {} };');

    expect($context?->connection)->toBe($expected);
})->with([
    'absent' => ['', 'default'],
    'uninitialized' => ['protected $connection;', 'default'],
    'null' => ['protected $connection = null;', 'default'],
    'uppercase null' => ['protected $connection = NULL;', 'default'],
    'empty string' => ['protected $connection = "";', 'default'],
    'zero string' => ['protected $connection = "0";', 'default'],
    'named' => ['protected $connection = "archive";', 'archive'],
    'public property' => ['public $connection = "archive";', 'archive'],
    'multiple declaration' => ['protected $unused, $connection = "archive";', 'archive'],
    'whitespace is truthy' => ['protected $connection = " ";', ' '],
    'nonzero string' => ['protected $connection = "00";', '00'],
    'case sensitive property' => ['protected $Connection = "archive";', 'default'],
    'bool false is not a supported literal' => ['protected $connection = false;', null],
    'integer zero is not a supported literal' => ['protected $connection = 0;', null],
    'class constant' => ['protected $connection = self::ARCHIVE;', null],
    'concatenation' => ['protected $connection = "arch" . "ive";', null],
    'private shadow' => ['private $connection = "archive";', null],
    'static property' => ['protected static $connection = "archive";', null],
]);

it('invalidates routing for writes anywhere in migration methods', function (string $method) {
    $context = locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration {
        protected $connection = "archive";
        public function up() {}
        '.$method.'
    };');

    expect($context?->connection)->toBeNull();
})->with([
    'constructor write' => ['public function __construct() { $this->connection = "other"; }'],
    'helper method write' => ['public function mutate() { $this->connection = "other"; }'],
    'shouldRun write' => ['public function shouldRun() { $this->connection = "other"; return true; }'],
    'compound write' => ['public function mutate() { $this->connection .= "_suffix"; }'],
    'preincrement' => ['public function mutate() { ++$this->connection; }'],
    'postdecrement' => ['public function mutate() { $this->connection--; }'],
    'unset' => ['public function mutate() { unset($this->connection); }'],
    'reference source' => ['public function mutate() { $alias =& $this->connection; }'],
    'reference array item' => ['public function mutate() { $aliases = [&$this->connection]; }'],
    'reference target' => ['public function mutate() { $this->connection =& $alias; }'],
    'array offset' => ['public function mutate() { $this->connection[0] = "x"; }'],
    'array destructure' => ['public function mutate() { [$this->connection] = ["other"]; }'],
    'list destructure' => ['public function mutate() { list($this->connection) = ["other"]; }'],
    'dynamic property' => ['public function mutate() { $this->{$name} = "other"; }'],
    'closure write' => ['public function mutate() { $call = function () { $this->connection = "other"; }; }'],
    'constructor promotion' => ['public function __construct(protected $connection = "archive") {}'],
    'foreach value write' => ['public function mutate() { foreach ($items as $this->connection) {} }'],
    'direct instance alias' => ['public function mutate() { $alias = $this; $alias->connection = "other"; }'],
    'conditional instance alias' => ['public function mutate() { $alias = $condition ? $this : $other; }'],
    'instance in an array alias' => ['public function mutate() { $aliases = [$this]; $aliases[0]->connection = "other"; }'],
    'instance passed to helper' => ['public function mutate() { mutate_connection($this); }'],
    'instance returned from helper' => ['public function instance() { return $this; }'],
    'foreach key write' => ['public function mutate() { foreach ($items as $this->connection => $value) {} }'],
]);

it('invalidates routing for writes within up itself', function () {
    expect(locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration {
        public function up() { $this->connection = "archive"; }
    };')?->connection)->toBeNull();
});

it('does not visit down bodies when resolving up routing', function (string $method) {
    $context = locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration {
        protected $connection = "archive";
        public function up() {}
        '.$method.'
    };');

    expect($context?->connection)->toBe('archive');
})->with([
    'down write' => ['public function down() { $this->connection = "other"; }'],
    'case insensitive down' => ['public function DOWN() { $this->{$name} = "other"; }'],
]);

it('invalidates routing when inheritance composition or accessors cannot be inspected', function (string $member) {
    expect(locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration {
        protected $connection = "archive";
        public function up() {}
        '.$member.'
    };')?->connection)->toBeNull();
})->with([
    'trait' => ['use CustomRouting;'],
    'getConnection override' => ['public function getConnection() { return "archive"; }'],
    'case insensitive override' => ['public function GETCONNECTION() { return "archive"; }'],
]);

it('does not inherit trait methods or run trait loading to find up', function () {
    $context = locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration { use CustomUp; };');

    expect($context)->toBeInstanceOf(MigrationContext::class)
        ->and($context?->up)->toBeNull()
        ->and($context?->conditional)->toBeFalse()
        ->and($context?->connection)->toBeNull();
});

it('keeps readable routing when another object or a nested class owns the write', function () {
    $context = locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration {
        protected $connection = "archive";
        public function up() {
            $read = $this->connection;
            $this->unrelated = "value";
            $object->connection = "other";
            $object = new class { public function mutate() { $this->connection = "other"; } };
        }
    };');

    expect($context?->connection)->toBe('archive');
});

it('treats connection property hooks as unresolved routing', function () {
    expect(locateMigrationContextForTest('return new class extends \\Illuminate\\Database\\Migrations\\Migration {
        protected string $connection { get => "archive"; }
        public function up() {}
    };')?->connection)->toBeNull();
})->skip(! property_exists(Property::class, 'hooks'), 'Requires PHP 8.4 AST property-hook support.');
