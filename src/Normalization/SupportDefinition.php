<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Operations\Fidelity;

final readonly class SupportDefinition
{
    public ?Fidelity $fidelity;

    public function __construct(
        public string $category,
        public string $method,
        public SupportLevel $support,
        public ?int $tier,
        public ?string $normalizer,
        public bool $implemented,
    ) {
        $this->fidelity = match ($support) {
            SupportLevel::Supported => Fidelity::Full,
            SupportLevel::Partial => Fidelity::Reduced,
            SupportLevel::Deferred, SupportLevel::NotAnalyzed => null,
        };
    }
}
