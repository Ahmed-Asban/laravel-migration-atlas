<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use InvalidArgumentException;
use MigrationAtlas\Diagnostics\Diagnostic;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\Discovery\MigrationFile;
use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;
use UnexpectedValueException;

final class PhpParserMigrationParser implements MigrationParser
{
    private readonly Parser $parser;

    public function __construct(
        private readonly int $maxFileSize = 1024 * 1024,
        ?Parser $parser = null,
        private readonly MigrationSourceReader $sources = new NativeMigrationSourceReader,
        private readonly UpMethodLocator $locator = new UpMethodLocator,
        private readonly SchemaCallExtractor $extractor = new SchemaCallExtractor,
    ) {
        if ($maxFileSize < 1 || $maxFileSize === PHP_INT_MAX) {
            throw new InvalidArgumentException('The maximum migration file size must be a positive integer below PHP_INT_MAX.');
        }

        $this->parser = $parser ?? (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function parse(MigrationFile $file): ParsedMigration
    {
        try {
            $source = $this->sources->read($file, $this->maxFileSize);

            // Keep the limit at the parser boundary as well as in the native
            // reader, so an injected reader cannot bypass the safeguard.
            if (strlen($source) > $this->maxFileSize) {
                return $this->failure($file, DiagnosticCode::FileTooLarge, 'Migration source exceeds the configured maximum file size.');
            }
        } catch (SourceReadFailure $failure) {
            return $this->failure($file, $failure->diagnostic, $failure->getMessage());
        } catch (Throwable) {
            return $this->failure($file, DiagnosticCode::MigrationSourceUnreadable, 'Migration source could not be read reliably.');
        }

        try {
            $statements = $this->parser->parse($source);

            if ($statements === null) {
                throw new UnexpectedValueException('The PHP parser did not return an AST.');
            }

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver(options: ['preserveOriginalNames' => true]));
            $resolved = $traverser->traverse($statements);

            foreach ($resolved as $statement) {
                if (! $statement instanceof Stmt) {
                    throw new UnexpectedValueException('Name resolution did not preserve statement nodes.');
                }
            }

            $context = $this->locator->locate($resolved);

            if ($context === null) {
                return $this->failure($file, DiagnosticCode::MigrationClassNotFound, 'No unambiguous inspectable migration class was found.');
            }

            if ($context->up === null || $context->up->stmts === null) {
                return $this->failure($file, DiagnosticCode::UpMethodMissing, 'The migration has no inspectable up() body.', NodeHelpers::line($context->class), $context);
            }

            $calls = $this->extractor->extract($context->up, $source, $context->connection);
            $diagnostics = $context->conditional ? [new Diagnostic(
                DiagnosticCode::ShouldRunDetected,
                DiagnosticSeverity::Warning,
                'The migration defines shouldRun(); its schema effects are conditional.',
                $file->absolutePath,
                NodeHelpers::line($context->class->getMethod('shouldRun') ?? $context->class),
            )] : [];

            return new ParsedMigration($file, $calls, $context->conditional, $context->connection, new DiagnosticCollection($diagnostics), true);
        } catch (Error $error) {
            return $this->failure($file, DiagnosticCode::MigrationSyntaxError, $error->getRawMessage(), $error->getStartLine() > 0 ? $error->getStartLine() : null);
        } catch (Throwable) {
            return $this->failure($file, DiagnosticCode::MigrationParseFailure, 'Migration source could not be analyzed safely.');
        }
    }

    private function failure(MigrationFile $file, DiagnosticCode $code, string $message, ?int $line = null, ?MigrationContext $context = null): ParsedMigration
    {
        return new ParsedMigration(
            $file,
            [],
            $context === null ? false : $context->conditional,
            $context?->connection,
            new DiagnosticCollection([new Diagnostic($code, DiagnosticSeverity::Warning, $message, $file->absolutePath, $line)]),
            false,
        );
    }
}
