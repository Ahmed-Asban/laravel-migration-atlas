<?php

declare(strict_types=1);

namespace MigrationAtlas\Tests\Support;

use LogicException;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Parsing\MigrationSourceReader;
use MigrationAtlas\Parsing\PhpParserMigrationParser;
use MigrationAtlas\Reconstruction\FunctionalSchemaReconstructor;
use MigrationAtlas\Reconstruction\ReconstructionResult;
use MigrationAtlas\Reconstruction\State\IncompletenessOrigin;
use MigrationAtlas\Reconstruction\State\TableState;

/**
 * Replays curated migration sources through the full parse-to-replay
 * pipeline for reconstruction scenarios. Each source receives its own
 * migration identity, date, and ordinal; sources are never executed.
 */
final class ReplayFixture
{
    /**
     * @param  list<string>  $sources
     */
    public static function replay(array $sources): ReconstructionResult
    {
        $normalizer = new OperationNormalizer;
        $migrations = [];

        foreach ($sources as $index => $source) {
            $ordinal = $index + 1;
            $name = sprintf('2026_01_%02d_000000_replay_%02d', $ordinal, $ordinal);
            $file = new MigrationFile('/fixtures/'.$name.'.php', $name,
                sprintf('2026-01-%02d 00:00:00', $ordinal), $ordinal);
            $reader = new class($source) implements MigrationSourceReader
            {
                public function __construct(private readonly string $source) {}

                public function read(MigrationFile $file, int $maxFileSize): string
                {
                    return $this->source;
                }
            };
            $migrations[] = $normalizer->normalize(
                (new PhpParserMigrationParser(sources: $reader))->parse($file)
            );
        }

        return (new FunctionalSchemaReconstructor)->replay($migrations);
    }

    /**
     * The first lineage currently named in the partition.
     */
    public static function lineage(ReconstructionResult $result, string $name, string $connection = 'default'): TableState
    {
        $matches = array_values(array_filter(
            $result->registry->partition($connection),
            static fn (TableState $lineage): bool => $lineage->name === $name,
        ));

        if ($matches === []) {
            throw new LogicException("No lineage named '{$name}' in partition '{$connection}'.");
        }

        return $matches[0];
    }

    public static function hasReason(TableState $lineage, IncompletenessOrigin $origin, DiagnosticCode $code): bool
    {
        foreach ($lineage->reasons as $reason) {
            if ($reason->origin === $origin && $reason->code === $code) {
                return true;
            }
        }

        return false;
    }

    public static function hasDiagnostic(ReconstructionResult $result, DiagnosticCode $code): bool
    {
        foreach ($result->diagnostics->items as $diagnostic) {
            if ($diagnostic->code === $code) {
                return true;
            }
        }

        return false;
    }
}
