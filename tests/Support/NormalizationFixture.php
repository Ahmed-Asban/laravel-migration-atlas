<?php

declare(strict_types=1);

namespace MigrationAtlas\Tests\Support;

use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Normalization\NormalizedMigration;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Parsing\MigrationSourceReader;
use MigrationAtlas\Parsing\ParsedMigration;
use MigrationAtlas\Parsing\PhpParserMigrationParser;

final class NormalizationFixture
{
    public static function parse(string $body, string $members = '', int $ordinal = 7): ParsedMigration
    {
        return self::source('<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends \Illuminate\Database\Migrations\Migration {
'.$members.'
public function up(): void {
'.$body.'
}
};', $ordinal);
    }

    public static function source(string $source, int $ordinal = 7): ParsedMigration
    {
        $reader = new class($source) implements MigrationSourceReader
        {
            public function __construct(private readonly string $source) {}

            public function read(MigrationFile $file, int $maxFileSize): string
            {
                return $this->source;
            }
        };

        return (new PhpParserMigrationParser(sources: $reader))->parse(
            new MigrationFile('/fixtures/2026_10_22_000000_example.php', '2026_10_22_000000_example', '2026-10-22 00:00:00', $ordinal),
        );
    }

    public static function normalize(string $body, string $members = '', int $ordinal = 7): NormalizedMigration
    {
        return (new OperationNormalizer)->normalize(self::parse($body, $members, $ordinal));
    }
}
