<?php

declare(strict_types=1);

namespace MigrationAtlas\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class DiscoveryWorkspace
{
    public readonly string $root;

    private readonly Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem;
        $this->root = dirname(__DIR__, 2).'/.cache/tests/discovery/'.bin2hex(random_bytes(8));
        $this->filesystem->ensureDirectoryExists($this->root);
    }

    public function directory(string $relative): string
    {
        $path = $this->root.'/'.$relative;
        $this->filesystem->ensureDirectoryExists($path);

        return $path;
    }

    public function migration(string $relative, string $source = '<?php throw new RuntimeException("Migration source must never execute.");'): string
    {
        $path = $this->root.'/'.$relative;
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $source);

        return $path;
    }

    public function cleanup(): void
    {
        $target = realpath($this->root);
        $parent = realpath(dirname(__DIR__, 2).'/.cache/tests/discovery');

        if ($target === false || $parent === false || ! str_starts_with($target, $parent.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing to remove a test directory outside the discovery workspace.');
        }

        $this->filesystem->deleteDirectory($target);
    }
}
