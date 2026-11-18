<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli\Render;

use MigrationAtlas\Cli\ExitCode;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\History\HistoryEvent;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\SourceLocation;
use MigrationAtlas\Reconstruction\State\ColumnState;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\IncompletenessReason;
use MigrationAtlas\Reconstruction\State\TableStatus;
use MigrationAtlas\Support\AnalysisResult;
use MigrationAtlas\Support\HistoryData;
use MigrationAtlas\Support\HistoryResult;
use MigrationAtlas\Support\InspectData;
use MigrationAtlas\Support\InspectResult;
use MigrationAtlas\Support\ResultStatus;
use MigrationAtlas\Support\TraceData;
use MigrationAtlas\Support\TraceResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Human-readable, diff-friendly command output per the approved CLI
 * examples: status banners with reasons split by origin, provenance per
 * column, fidelity footnotes, conditional event markers, and clearly
 * labeled dropped, last-known, or candidate definitions.
 */
final class TextRenderer implements Renderer
{
    public function render(OutputInterface $output, AnalysisResult $result, bool $strict = false): int
    {
        foreach ($this->lines($result) as $line) {
            $output->writeln($line);
        }

        return ExitCode::resolve($result, $strict)->value;
    }

    /**
     * @return list<string>
     */
    public function lines(AnalysisResult $result): array
    {
        if ($result instanceof InspectResult) {
            return $result->data === null ? $this->errorLines($result) : $this->inspectLines($result->data, $result);
        }

        if ($result instanceof HistoryResult) {
            return $result->data === null ? $this->errorLines($result) : $this->historyLines($result->data, $result);
        }

        if ($result instanceof TraceResult) {
            return $result->data === null ? $this->errorLines($result) : $this->traceLines($result->data, $result);
        }

        return $this->errorLines($result);
    }

    /**
     * @return list<string>
     */
    private function inspectLines(InspectData $data, AnalysisResult $result): array
    {
        $lines = [
            $data->name.' — expected schema (reconstructed from '
                .$result->meta()->migrationsScanned.' migration files; no database was read)',
            '',
            ...$this->banner($data->status, $data->existence, $data->reasons, $data->droppedBy),
            '',
            'COLUMNS',
        ];

        $width = 0;

        foreach ($data->columns as $column) {
            $width = max($width, strlen($column->name));
        }

        $reduced = false;

        foreach ($data->columns as $column) {
            $marker = $column->fidelity === Fidelity::Reduced ? ' †' : '';
            $reduced = $reduced || $marker !== '';
            $lines[] = '  '.str_pad($column->name, $width).'  '.$this->columnSpec($column).$marker;
            $lines = [...$lines, ...$this->columnProvenance($column, $data->historySummary)];
        }

        if ($data->columns === []) {
            $lines[] = '  (no columns)';
        }

        if ($reduced) {
            $lines = [...$lines, '', '  † reduced fidelity: type captured generically (PARTIALLY SUPPORTED construct)'];
        }

        $lines[] = '';
        $lines[] = 'INDEXES';

        if ($data->indexes === []) {
            $lines[] = '  (none)';
        }

        foreach ($data->indexes as $index) {
            $marker = $index->fidelity === Fidelity::Reduced ? ' †' : '';
            $lines[] = '  '.$index->name.'  '.$index->type.' ('.implode(', ', $index->columns).')  '
                .$this->fileLabel($index->source->migration).':'.$index->source->line.$marker;
        }

        $lines[] = '';
        $lines[] = 'FOREIGN KEYS';

        if ($data->foreignKeys === []) {
            $lines[] = '  (none)';
        }

        foreach ($data->foreignKeys as $key) {
            $marker = $key->fidelity === Fidelity::Reduced ? ' †' : '';
            $actions = '';

            if ($key->onDelete !== null) {
                $actions .= ' · on delete '.$key->onDelete;
            }

            if ($key->onUpdate !== null) {
                $actions .= ' · on update '.$key->onUpdate;
            }

            $lines[] = '  '.$key->name.'  ('.implode(', ', $key->columns).') → '
                .$key->referencedTable.'('.implode(', ', $key->referencedColumns).')'.$actions.'  '
                .$this->fileLabel($key->source->migration).':'.$key->source->line.$marker;
        }

        $lines[] = '';
        $lines[] = 'HISTORY SUMMARY';
        $lines[] = '  '.$this->historySummary($data->historySummary, $result);

        return [...$lines, ...$this->warningsBlock($result)];
    }

    /**
     * @return list<string>
     */
    private function historyLines(HistoryData $data, AnalysisResult $result): array
    {
        $touching = 0;
        $files = [];

        foreach ($data->lineages as $lineage) {
            foreach ($lineage->events as $event) {
                $files[$event->file] = true;
            }
        }

        $touching = count($files);
        $lines = [$data->table.' — migration history ('.$touching.' migrations touch this identity)', ''];
        $conditional = false;
        $number = 0;

        foreach ($data->lineages as $index => $lineage) {
            if (count($data->lineages) > 1) {
                $heading = $lineage->existence === Existence::Absent
                    ? 'EARLIER LINEAGE — dropped'
                    : ($index === count($data->lineages) - 1 ? 'PRIMARY LINEAGE' : 'COMPETING LINEAGE');

                if ($lineage->droppedBy !== null) {
                    $heading .= ' by '.$this->fileLabel($lineage->droppedBy->migration).':'.$lineage->droppedBy->line;
                }

                $lines[] = $heading;
            }

            foreach ($lineage->events as $event) {
                $number++;
                $marker = $event->conditional ? '  [conditional ‡]' : '';
                $conditional = $conditional || $event->conditional;
                $lines[] = '  '.$number.'. '.($event->date ?? 'unknown date').'  '.$event->summary.$marker;
                $lines[] = '     '.$this->fileLabel($event->file).':'.$event->line;
            }

            if ($index < count($data->lineages) - 1) {
                $lines[] = '';
            }
        }

        if ($conditional) {
            $lines[] = '';
            $lines[] = '  ‡ migration defines shouldRun() — whether it executed is not statically determinable';
        }

        $lines[] = '';
        $primary = $data->lineages[count($data->lineages) - 1];
        $lines[] = 'Status: '.$this->historyStatus($result, $primary->existence);

        if ($result->meta()->status === TableStatus::Incomplete) {
            $lines = [...$lines, ...$this->reasonLines($data->reasons)];
        }

        return [...$lines, ...$this->warningsBlock($result)];
    }

    /**
     * @return list<string>
     */
    private function traceLines(TraceData $data, AnalysisResult $result): array
    {
        $resolved = $data->currentTable !== null && $data->currentColumn !== null
            ? 'resolved to current name: '.$data->currentTable.'.'.$data->currentColumn
            : 'current name uncertain — observed: '.implode(', ', $data->possibleTableNames)
                .' / '.implode(', ', $data->possibleColumnNames);

        $lines = [
            $data->requestedTable.'.'.$data->requestedColumn.' — column trace ('.$resolved.')',
            '',
        ];

        $conditional = false;

        foreach ($data->events as $event) {
            $marker = $event->conditional ? '  [conditional ‡]' : '';
            $conditional = $conditional || $event->conditional;
            $lines[] = '  '.($event->date ?? 'unknown date').'  '.$event->summary.$marker;
            $lines[] = '     '.$this->fileLabel($event->file).':'.$event->line;
        }

        if ($data->events === []) {
            $lines[] = '  (no events)';
        }

        if ($conditional) {
            $lines[] = '';
            $lines[] = '  ‡ migration defines shouldRun() — whether it executed is not statically determinable';
        }

        $lines[] = '';

        if ($data->definition !== null) {
            $label = match (true) {
                $data->exists === true => 'Current definition',
                $data->exists === false => 'Last known definition',
                default => 'Candidate definition',
            };

            $lines[] = $label.': '.$this->columnSpec($data->definition)
                .($data->definition->fidelity === Fidelity::Reduced ? ' †' : '');
        }

        $lines[] = 'Status: '.$this->traceStatus($data, $result);

        return [...$lines, ...$this->warningsBlock($result)];
    }

    /**
     * @return list<string>
     */
    private function errorLines(AnalysisResult $result): array
    {
        $lines = [];

        foreach ($result->diagnostics()->items as $diagnostic) {
            $label = $diagnostic->severity === DiagnosticSeverity::Error
                ? $diagnostic->message
                : '⚠ '.$diagnostic->code->value.': '.$diagnostic->message;

            if ($diagnostic->file !== null) {
                $label .= $this->sourceSuffix(basename($diagnostic->file), $diagnostic->line);
            }

            $lines[] = $label;
        }

        return $lines;
    }

    /**
     * @param  list<IncompletenessReason>  $reasons
     * @return list<string>
     */
    private function banner(ResultStatus $status, Existence $existence, array $reasons, ?SourceLocation $droppedBy): array
    {
        if ($status === ResultStatus::Dropped) {
            $lines = ['Status: DROPPED — showing last known state before the drop'];

            if ($droppedBy !== null) {
                $lines[] = '  dropped by '.$this->fileLabel($droppedBy->migration).':'.$droppedBy->line;
            }

            return $lines;
        }

        if ($status === ResultStatus::Complete) {
            return ['Status: complete'];
        }

        return ['Status: INCOMPLETE · existence: '.$existence->value, ...$this->reasonLines($reasons)];
    }

    /**
     * @param  list<IncompletenessReason>  $reasons
     * @return list<string>
     */
    private function reasonLines(array $reasons): array
    {
        $lines = [];

        foreach ($reasons as $reason) {
            $lines[] = '  ⚠ '.$reason->origin->value.': '
                .$this->fileLabel($reason->file).':'.$reason->line.' '.$this->reasonText($reason->code->value);
        }

        return $lines;
    }

    private function reasonText(string $code): string
    {
        return match ($code) {
            'UNRESOLVED_DYNAMIC_EXPRESSION' => 'uses a dynamic expression',
            'UNATTRIBUTABLE_OPERATION' => 'unattributable effect — any table live at that point may be affected',
            'RAW_SQL_DETECTED' => 'contains raw SQL (unattributable effect)',
            'UNRESOLVED_UNKNOWN_METHOD' => 'uses an unsupported method',
            'UNRESOLVED_CONDITIONAL_REGION' => 'contains schema operations inside control flow',
            'SHOULD_RUN_DETECTED' => 'conditional migration — whether it executed is not statically determinable',
            'DUPLICATE_TABLE_CREATE' => 'duplicate table create',
            'DUPLICATE_COLUMN' => 'duplicate column add',
            'ALTER_UNKNOWN_TABLE' => 'alter without an analyzed create (unknown origin)',
            'DROP_UNKNOWN_TABLE' => 'drop without an identifiable live target',
            'DROP_UNKNOWN_COLUMN' => 'operation target not present at replay time',
            'RENAME_UNKNOWN_TABLE' => 'rename without an identifiable live source',
            'RENAME_UNKNOWN_COLUMN' => 'renamed column not identifiable at replay time',
            'RENAME_TARGET_CONFLICT' => 'rename target already live — rename not applied',
            default => 'uncertain effect',
        };
    }

    /**
     * @param  list<HistoryEvent>  $events
     * @return list<string>
     */
    private function columnProvenance(ColumnState $column, array $events): array
    {
        $identities = [$column->name, ...$column->formerNames];
        $lines = [];

        foreach ($events as $event) {
            if ($event->columns === [] || array_intersect($event->columns, $identities) === []) {
                continue;
            }

            $conditional = $event->conditional ? ' (conditional)' : '';

            if ($event->operation === 'add_column') {
                $lines[] = '      added by '.$this->fileLabel($event->file).':'.$event->line.$conditional;
            } elseif ($event->operation === 'rename_column' && count($event->columns) === 2 && $event->columns[1] === $column->name) {
                $lines[] = '      renamed from '.$event->columns[0].' by '
                    .$this->fileLabel($event->file).':'.$event->line.$conditional;
            } elseif ($event->operation === 'change_column') {
                $lines[] = '      changed by '.$this->fileLabel($event->file).':'.$event->line.$conditional;
            } elseif ($event->operation === 'drop_column') {
                $lines[] = '      dropped by '.$this->fileLabel($event->file).':'.$event->line.$conditional;
            }
        }

        if ($lines === []) {
            $lines[] = '      defined by '.$this->fileLabel($column->source->migration).':'.$column->source->line;
        }

        return $lines;
    }

    private function columnSpec(ColumnState $column): string
    {
        $type = $column->type;

        if ($column->unsigned === true) {
            $type .= ' unsigned';
        }

        if ($column->length !== null) {
            $type .= '('.$column->length.')';
        }

        $parts = [$type, $column->nullable ? 'nullable' : 'not null'];

        if ($column->autoIncrement === true) {
            $parts[] = 'auto-increment';
        }

        if ($column->primary === true) {
            $parts[] = 'primary';
        }

        if ($column->hasDefault) {
            $parts[] = 'default '.$this->valueText($column->default);
        }

        if ($column->allowedValues !== null) {
            $parts[] = 'one of ('.implode(', ', array_map($this->valueText(...), $column->allowedValues)).')';
        }

        return implode(', ', $parts);
    }

    private function valueText(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return '['.implode(', ', array_map($this->valueText(...), $value)).']';
        }

        if (is_string($value)) {
            return "'".$value."'";
        }

        return "'".json_encode($value)."'";
    }

    /**
     * @param  list<HistoryEvent>  $events
     */
    private function historySummary(array $events, AnalysisResult $result): string
    {
        if ($events === []) {
            return '(no operations)';
        }

        $files = [];
        $first = null;
        $last = null;

        foreach ($events as $event) {
            $files[$event->file] = true;
            $first ??= $event->file;
            $last = $event->file;
        }

        return count($events).' operations across '.count($files).' migrations · first: '
            .$this->fileLabel($first).' · last: '.$this->fileLabel($last);
    }

    private function historyStatus(AnalysisResult $result, Existence $existence): string
    {
        $suffix = ' · '.$result->meta()->migrationsScanned.' migration files scanned';

        if ($result->meta()->status === TableStatus::Complete) {
            return 'complete'.$suffix;
        }

        return 'INCOMPLETE · existence: '.$existence->value.$suffix;
    }

    private function traceStatus(TraceData $data, AnalysisResult $result): string
    {
        $analysis = $result->meta()->status === TableStatus::Complete ? 'analysis complete' : 'analysis incomplete';

        if ($data->exists === true) {
            return 'exists · '.$analysis;
        }

        if ($data->exists === false && $data->droppedBy !== null) {
            return 'dropped (by '.$this->fileLabel($data->droppedBy->migration).':'.$data->droppedBy->line.') · '.$analysis;
        }

        if ($data->exists === false) {
            return 'dropped · '.$analysis;
        }

        return 'unknown · '.$analysis;
    }

    /**
     * @return list<string>
     */
    private function warningsBlock(AnalysisResult $result): array
    {
        $warnings = [];

        foreach ($result->diagnostics()->items as $diagnostic) {
            if ($diagnostic->severity !== DiagnosticSeverity::Warning) {
                continue;
            }

            $warnings[] = '  ⚠ '.$diagnostic->code->value.': '.$diagnostic->message
                .($diagnostic->file !== null ? $this->sourceSuffix(basename($diagnostic->file), $diagnostic->line) : '');
        }

        return $warnings === [] ? [] : ['', 'WARNINGS', ...$warnings];
    }

    private function sourceSuffix(string $file, ?int $line): string
    {
        return ' ('.$file.($line === null ? '' : ':'.$line).')';
    }

    private function fileLabel(string $migration): string
    {
        return str_ends_with($migration, '.php') ? $migration : $migration.'.php';
    }
}
