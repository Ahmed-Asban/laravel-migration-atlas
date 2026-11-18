<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction\State;

use MigrationAtlas\Diagnostics\DiagnosticCode;

/**
 * One recorded reason why an identity is incomplete, with the source that
 * produced it.
 */
final readonly class IncompletenessReason
{
    public function __construct(
        public IncompletenessOrigin $origin,
        public DiagnosticCode $code,
        public string $file,
        public ?int $line,
    ) {}

    /**
     * @param  list<IncompletenessReason>  $reasons
     * @return list<IncompletenessReason>
     */
    public static function push(array $reasons, self $reason): array
    {
        foreach ($reasons as $existing) {
            if ($existing->origin === $reason->origin && $existing->code === $reason->code
                && $existing->file === $reason->file && $existing->line === $reason->line) {
                return $reasons;
            }
        }

        $reasons[] = $reason;

        return $reasons;
    }
}
