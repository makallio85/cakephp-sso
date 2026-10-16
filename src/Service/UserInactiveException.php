<?php
declare(strict_types=1);

namespace Sso\Service;

/**
 * The person is known but deactivated in this application: an administrator
 * closed their access here, which signing in through the provider does not
 * undo.
 */
class UserInactiveException extends SsoException
{
}
