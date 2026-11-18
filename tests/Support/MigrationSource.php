<?php

declare(strict_types=1);

namespace MigrationAtlas\Tests\Support;

/**
 * Builds anonymous-class migration sources for pipeline tests. The source
 * is never executed.
 */
final class MigrationSource
{
    public static function up(string $up, bool $conditional = false): string
    {
        $shouldRun = $conditional ? '
    public function shouldRun(): bool
    {
        return true;
    }' : '';

        return '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{'.$shouldRun.'
    public function up(): void
    {
'.$up.'
    }
};';
    }
}
