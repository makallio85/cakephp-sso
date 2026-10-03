<?php
declare(strict_types=1);

namespace Sso\Service;

use RuntimeException;

/**
 * A sign-in that cannot complete. The message is written for the operator's
 * log, never shown to the person signing in.
 */
class SsoException extends RuntimeException
{
}
