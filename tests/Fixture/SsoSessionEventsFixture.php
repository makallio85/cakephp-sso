<?php
declare(strict_types=1);

namespace Sso\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no sign-in history. The event and method
 * types are the migration's system rows.
 */
class SsoSessionEventsFixture extends TestFixture
{
    public string $table = 'sso_session_events';

    public array $records = [];
}
