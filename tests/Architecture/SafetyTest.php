<?php

declare(strict_types=1);

arch('package code has no database dependencies')
    ->expect('MigrationAtlas')
    ->not->toUse([
        'Illuminate\Database',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Schema',
        'PDO',
        'mysqli',
    ])->ignoring('MigrationAtlas\Tests');

arch('package code cannot execute migration source or shell commands')
    ->expect('MigrationAtlas')
    ->not->toUse([
        'eval',
        'include',
        'include_once',
        'require',
        'require_once',
        'assert',
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
    ])->ignoring('MigrationAtlas\Tests');

arch('normalization operates on data without parsing or reading source')
    ->expect('MigrationAtlas\Normalization')
    ->not->toUse([
        'PhpParser',
        'MigrationAtlas\Parsing\PhpParserMigrationParser',
        'MigrationAtlas\Parsing\MigrationSourceReader',
        'file_get_contents',
        'fopen',
        'scandir',
    ]);

arch('reconstruction replays pure data without parsing, discovery or filesystem access')
    ->expect('MigrationAtlas\Reconstruction')
    ->not->toUse([
        'Illuminate\Database',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Schema',
        'PDO',
        'MigrationAtlas\Parsing',
        'file_get_contents',
        'fopen',
        'scandir',
        'eval',
        'include',
        'include_once',
        'require',
        'require_once',
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
    ]);

arch('history events remain framework and pipeline independent')
    ->expect('MigrationAtlas\History')
    ->not->toUse([
        'Illuminate',
        'PhpParser',
        'MigrationAtlas\Parsing',
        'MigrationAtlas\Discovery',
        'MigrationAtlas\Normalization',
    ]);

arch('schema operations do not depend on framework or pipeline services')
    ->expect('MigrationAtlas\Operations')
    ->not->toUse([
        'Illuminate',
        'PhpParser',
        'MigrationAtlas\Normalization',
        'MigrationAtlas\Parsing',
        'MigrationAtlas\Discovery',
    ]);
