<?php

declare(strict_types=1);

use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Tests\Support\NormalizationFixture;

it('matches the ordered normalization contract', function (string $fixture) {
    $source = file_get_contents(dirname(__DIR__, 2).'/Fixtures/normalization/'.$fixture.'.php');
    $parsed = NormalizationFixture::source($source);
    $result = (new OperationNormalizer)->normalize($parsed);

    expect($parsed->parsed)->toBeTrue()
        ->and(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))->toMatchSnapshot()
        ->and(isset($GLOBALS['migration_atlas_normalization_executed']))->toBeFalse();
})->with(['basic', 'uncertainty']);
