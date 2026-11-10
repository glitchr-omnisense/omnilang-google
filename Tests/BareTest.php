<?php

namespace Omnilang\Google\Tests;

use Omnilang\Google\GoogleProvider;
use Omnilang\Testing\BareTestCase;

/** omnilang/google's classes load with omnisense/omnilang, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return GoogleProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
