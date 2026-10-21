<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

use InvalidArgumentException;

final readonly class DiscoveryOptions
{
    /** @var list<string> */
    public array $paths;

    /** @param array<array-key, mixed> $paths */
    public function __construct(array $paths, string $basePath)
    {
        if (! self::isLocalPath($basePath) || ! self::isAbsolutePath($basePath)) {
            throw new InvalidArgumentException('The project root must be an absolute local directory path.');
        }

        $resolved = [];

        foreach ($paths as $path) {
            if (! is_string($path) || ! self::isLocalPath($path)) {
                throw new InvalidArgumentException('Migration paths must be non-empty local directory paths.');
            }

            // A drive-relative path (C:folder) depends on process state on Windows.
            if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[a-zA-Z]:/', $path) === 1 && ! self::isAbsolutePath($path)) {
                throw new InvalidArgumentException('Drive-relative migration paths are not supported.');
            }

            if (DIRECTORY_SEPARATOR === '\\' && (str_starts_with($path, '/') || str_starts_with($path, '\\')) && ! self::isAbsolutePath($path)) {
                throw new InvalidArgumentException('Root-relative migration paths must include a drive or UNC share.');
            }

            $resolved[] = self::isAbsolutePath($path)
                ? $path
                : rtrim($basePath, DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/').DIRECTORY_SEPARATOR.$path;
        }

        $this->paths = $resolved;
    }

    private static function isLocalPath(string $path): bool
    {
        return trim($path) !== '' && ! str_contains($path, "\0") && ! str_contains($path, '://');
    }

    private static function isAbsolutePath(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return str_starts_with($path, '\\\\') || str_starts_with($path, '//')
                || preg_match('~^[a-zA-Z]:[/\\\\]~', $path) === 1;
        }

        return str_starts_with($path, '/');
    }
}
