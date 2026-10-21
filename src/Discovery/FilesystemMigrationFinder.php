<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

use MigrationAtlas\Diagnostics\Diagnostic;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;

final class FilesystemMigrationFinder implements MigrationFinder
{
    public function __construct(private readonly MigrationDirectoryReader $directories = new NativeMigrationDirectoryReader) {}

    public function find(DiscoveryOptions $options): MigrationList
    {
        $pathsByName = [];

        foreach ($options->paths as $directory) {
            foreach ($this->directories->files($directory) as $filename) {
                // Match Laravel's non-recursive *_*.php directory pattern.
                if (str_starts_with($filename, '.') || ! str_contains($filename, '_') || ! str_ends_with($filename, '.php')) {
                    continue;
                }

                $pathsByName[substr($filename, 0, -4)] = rtrim($directory, DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/').DIRECTORY_SEPARATOR.$filename;
            }
        }

        ksort($pathsByName, SORT_STRING);
        $files = [];
        $diagnostics = [];

        foreach ($pathsByName as $name => $path) {
            $date = $this->date($name);
            $files[] = new MigrationFile($path, $name, $date, count($files) + 1);

            if ($date === null) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::NonStandardFilename,
                    DiagnosticSeverity::Warning,
                    'Migration filename has no valid YYYY_MM_DD_HHMMSS timestamp: '.$name.'.php',
                    $path,
                );
            }
        }

        return new MigrationList($files, new DiagnosticCollection($diagnostics));
    }

    private function date(string $name): ?string
    {
        if (preg_match('/^([0-9]{4})_([0-9]{2})_([0-9]{2})_([0-9]{2})([0-9]{2})([0-9]{2})_.+$/D', $name, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            return null;
        }

        return $parts[1].'-'.$parts[2].'-'.$parts[3].' '.$parts[4].':'.$parts[5].':'.$parts[6];
    }
}
