<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Parsing\NativeMigrationSourceReader;
use MigrationAtlas\Parsing\SourceReadFailure;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
});

afterEach(function () {
    $this->workspace->cleanup();
});

it('reads local migration bytes including sources exactly at the limit', function () {
    $source = "<?php\n// UTF-8: العربية\n";
    $path = $this->workspace->migration('migration.php', $source);
    $file = new MigrationFile($path, 'migration', null, 1);

    expect((new NativeMigrationSourceReader)->read($file, strlen($source)))->toBe($source);
});

it('reads an empty regular file without treating it as an I/O failure', function () {
    $file = new MigrationFile($this->workspace->migration('empty.php', ''), 'empty', null, 1);

    expect((new NativeMigrationSourceReader)->read($file, 1))->toBe('');
});

it('reports a file one byte above the limit with the size root code', function () {
    $file = new MigrationFile($this->workspace->migration('large.php', '12345'), 'large', null, 1);

    try {
        (new NativeMigrationSourceReader)->read($file, 4);
        $this->fail('Expected the source reader to reject an oversized file.');
    } catch (SourceReadFailure $failure) {
        expect($failure->diagnostic)->toBe(DiagnosticCode::FileTooLarge);
    }
});

it('reports missing and disappeared discovered files as unreadable', function (bool $createFirst) {
    $path = $this->workspace->root.'/missing.php';

    if ($createFirst) {
        $path = $this->workspace->migration('missing.php', '<?php');
        unlink($path);
    }

    $file = new MigrationFile($path, 'missing', null, 4);

    try {
        (new NativeMigrationSourceReader)->read($file, 1024);
        $this->fail('Expected the source reader to reject a missing file.');
    } catch (SourceReadFailure $failure) {
        expect($failure->diagnostic)->toBe(DiagnosticCode::MigrationSourceUnreadable);
    }
})->with([false, true]);

it('rejects directories and nonlocal stream paths as unreadable sources', function (string $path) {
    if ($path === 'directory') {
        $path = $this->workspace->directory('directory.php');
    }

    $file = new MigrationFile($path, 'unreadable', null, 1);

    try {
        (new NativeMigrationSourceReader)->read($file, 1024);
        $this->fail('Expected the source reader to reject a nonlocal or nonregular source.');
    } catch (SourceReadFailure $failure) {
        expect($failure->diagnostic)->toBe(DiagnosticCode::MigrationSourceUnreadable);
    }
})->with(['directory', 'php://memory', 'data://text/plain,<?php', 'https://example.com/migration.php', "invalid\0source.php"]);

it('rejects a named pipe before opening it so source reads cannot block on a writer', function () {
    $path = $this->workspace->root.'/source.php';
    expect(posix_mkfifo($path, 0600))->toBeTrue();
    $file = new MigrationFile($path, 'pipe', null, 1);

    try {
        (new NativeMigrationSourceReader)->read($file, 1024);
        $this->fail('Expected the source reader to reject a named pipe.');
    } catch (SourceReadFailure $failure) {
        expect($failure->diagnostic)->toBe(DiagnosticCode::MigrationSourceUnreadable);
    }
})->skip(! function_exists('posix_mkfifo'), 'Named-pipe source safeguards require POSIX support.');
