<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Discovery\MigrationFile;

final class NativeMigrationSourceReader implements MigrationSourceReader
{
    public function read(MigrationFile $file, int $maxFileSize): string
    {
        $path = $file->absolutePath;

        if (str_contains($path, '://') || str_contains($path, "\0")) {
            throw $this->unreadable();
        }

        clearstatcache(true, $path);

        if (! is_file($path) || ! is_readable($path)) {
            throw $this->unreadable();
        }

        $pathStat = @stat($path);

        // Reject special files before opening: opening a FIFO can block.
        if ($pathStat === false || ($pathStat['mode'] & 0170000) !== 0100000) {
            throw $this->unreadable();
        }

        if ($pathStat['size'] > $maxFileSize) {
            throw $this->oversized();
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw $this->unreadable();
        }

        try {
            $before = @fstat($handle);

            if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
                throw $this->unreadable();
            }

            if ($before['size'] > $maxFileSize) {
                throw $this->oversized();
            }

            // Bounded even if the file grows after the initial stat.
            $source = @stream_get_contents($handle, $maxFileSize + 1);
            $after = @fstat($handle);

            if ($source === false || $after === false) {
                throw $this->unreadable();
            }

            if (strlen($source) > $maxFileSize || $after['size'] > $maxFileSize) {
                throw $this->oversized();
            }

            if (strlen($source) !== $before['size'] || $after['size'] !== $before['size']
                || $after['mtime'] !== $before['mtime'] || $after['ctime'] !== $before['ctime']) {
                throw $this->unreadable();
            }

            return $source;
        } finally {
            fclose($handle);
        }
    }

    private function unreadable(): SourceReadFailure
    {
        return new SourceReadFailure(DiagnosticCode::MigrationSourceUnreadable, 'Migration source could not be read reliably.');
    }

    private function oversized(): SourceReadFailure
    {
        return new SourceReadFailure(DiagnosticCode::FileTooLarge, 'Migration source exceeds the configured maximum file size.');
    }
}
