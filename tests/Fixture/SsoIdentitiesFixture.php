<?php
declare(strict_types=1);

namespace Sso\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no identities.
 */
class SsoIdentitiesFixture extends TestFixture
{
    public string $table = 'sso_identities';

    public array $records = [];
}
