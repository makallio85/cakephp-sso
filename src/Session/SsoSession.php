<?php
declare(strict_types=1);

namespace Sso\Session;

use Cake\Http\Session;

/**
 * What the plugin keeps in the session, and how the application asks about it.
 *
 * An application with its own second factor uses `isSsoSession()` to skip it:
 * the provider already required one, and an emergency link is issued by
 * someone with shell access to the server.
 */
final class SsoSession
{
    /** How the session was established: one of the METHOD_ constants. */
    public const METHOD = 'Sso.method';

    /** The ID token from sign-in, sent back as a hint on logout. */
    public const ID_TOKEN = 'Sso.idToken';

    /** Set when the plugin signed the user in. */
    public const AUTHENTICATED = 'Sso.authenticated';

    /** The authorization request in progress: state, nonce, verifier, redirect. */
    public const FLOW = 'Sso.flow';

    public const METHOD_OIDC = 'oidc';
    public const METHOD_EMERGENCY = 'emergency';

    /**
     * Whether the signed-in user came in through the plugin.
     *
     * @param \Cake\Http\Session|null $session The session.
     * @return bool
     */
    public static function isSsoSession(?Session $session): bool
    {
        if ($session === null) {
            return false;
        }

        return $session->read(self::AUTHENTICATED) === true
            && in_array($session->read(self::METHOD), [self::METHOD_OIDC, self::METHOD_EMERGENCY], true);
    }
}
