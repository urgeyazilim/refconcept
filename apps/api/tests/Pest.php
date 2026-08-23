<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case bindings
|--------------------------------------------------------------------------
| Feature tests and domain tests hit the real PostgreSQL test database.
| Unit tests stay framework-light and must not touch the database.
|
| The safety check that the connection really points at a test database lives in
| Tests\TestCase::setUp(), because this file is evaluated before PHPUnit applies
| the <php><env> block from phpunit.xml.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../app/Domains');

pest()->extend(TestCase::class)->in('Unit');
