<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction\State;

use MigrationAtlas\Operations\ColumnDefinitionDto;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\SourceLocation;

/**
 * The replayed state of one column identity inside a lineage. Unspecified
 * attributes stay null; dropped columns keep their last-known definition
 * with an ABSENT existence and their drop provenance.
 */
final readonly class ColumnState
{
    /**
     * @param  list<string>  $formerNames
     * @param  list<string>  $possibleNames
     * @param  list<mixed>|null  $allowedValues
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?int $length,
        public ?int $precision,
        public ?int $scale,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
        public ?bool $unsigned,
        public ?bool $autoIncrement,
        public ?bool $primary,
        public ?array $allowedValues,
        public ?string $comment,
        public array $formerNames,
        public array $possibleNames,
        public bool $nameCertain,
        public Existence $existence,
        public Fidelity $fidelity,
        public SourceLocation $source,
        public ?SourceLocation $droppedBy = null,
    ) {}

    public static function fromDefinition(ColumnDefinitionDto $definition, Existence $existence): self
    {
        $attributes = $definition->attributes;

        return new self(
            $definition->name,
            $definition->type,
            isset($attributes['length']) && is_int($attributes['length']) ? $attributes['length'] : null,
            isset($attributes['precision']) && is_int($attributes['precision']) ? $attributes['precision'] : null,
            isset($attributes['places']) && is_int($attributes['places']) ? $attributes['places'] : null,
            $definition->nullable,
            $definition->hasDefault,
            $definition->default,
            isset($attributes['unsigned']) && is_bool($attributes['unsigned']) ? $attributes['unsigned'] : null,
            isset($attributes['autoIncrement']) && is_bool($attributes['autoIncrement']) ? $attributes['autoIncrement'] : null,
            isset($attributes['primary']) && is_bool($attributes['primary']) ? $attributes['primary'] : null,
            isset($attributes['allowedValues']) && is_array($attributes['allowedValues'])
                && array_is_list($attributes['allowedValues']) ? $attributes['allowedValues'] : null,
            isset($attributes['comment']) && is_string($attributes['comment']) ? $attributes['comment'] : null,
            [],
            [$definition->name],
            true,
            $existence,
            $definition->fidelity,
            $definition->source,
        );
    }

    /**
     * Apply a declared redefinition; former names, identity uncertainty, and
     * sticky reduced fidelity survive the merge.
     */
    public function withChange(ColumnDefinitionDto $definition, SourceLocation $source): self
    {
        $merged = self::fromDefinition($definition, $this->existence);
        $fidelity = $this->fidelity === Fidelity::Reduced || $definition->fidelity === Fidelity::Reduced
            ? Fidelity::Reduced
            : Fidelity::Full;

        return new self(
            $merged->name,
            $merged->type,
            $merged->length,
            $merged->precision,
            $merged->scale,
            $merged->nullable,
            $merged->hasDefault,
            $merged->default,
            $merged->unsigned,
            $merged->autoIncrement,
            $merged->primary,
            $merged->allowedValues,
            $merged->comment,
            $this->formerNames,
            $this->possibleNames,
            $this->nameCertain,
            $this->existence,
            $fidelity,
            $source,
            null,
        );
    }

    public function withRename(string $to, SourceLocation $source): self
    {
        $formerNames = in_array($this->name, $this->formerNames, true) ? $this->formerNames : [...$this->formerNames, $this->name];
        $possibleNames = in_array($to, $this->possibleNames, true) ? $this->possibleNames : [...$this->possibleNames, $to];

        return new self($to, $this->type, $this->length, $this->precision, $this->scale, $this->nullable,
            $this->hasDefault, $this->default, $this->unsigned, $this->autoIncrement, $this->primary,
            $this->allowedValues, $this->comment, $formerNames, $possibleNames, $this->nameCertain,
            $this->existence, $this->fidelity, $source, $this->droppedBy);
    }

    public function withPossibleName(string $name): self
    {
        $possibleNames = in_array($name, $this->possibleNames, true) ? $this->possibleNames : [...$this->possibleNames, $name];

        return new self($this->name, $this->type, $this->length, $this->precision, $this->scale, $this->nullable,
            $this->hasDefault, $this->default, $this->unsigned, $this->autoIncrement, $this->primary,
            $this->allowedValues, $this->comment, $this->formerNames, $possibleNames, false,
            $this->existence, $this->fidelity, $this->source, $this->droppedBy);
    }

    public function withExistence(Existence $existence, ?SourceLocation $droppedBy = null): self
    {
        return new self($this->name, $this->type, $this->length, $this->precision, $this->scale, $this->nullable,
            $this->hasDefault, $this->default, $this->unsigned, $this->autoIncrement, $this->primary,
            $this->allowedValues, $this->comment, $this->formerNames, $this->possibleNames, $this->nameCertain,
            $existence, $this->fidelity, $this->source, $droppedBy ?? $this->droppedBy);
    }
}
