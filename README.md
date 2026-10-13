# cakephp-sso

CakePHP 5 plugin that signs users in through the central Rock Software identity
provider (`id.rocksoftware.fi`, Authentik) with OpenID Connect. Each application keeps
its `users` table as the anchor for its foreign keys; the plugin creates and refreshes
those rows from the provider, so no application manages people itself.

Design: [makallio85/rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5).

## What it does

| Route | Purpose |
|---|---|
| `GET /sso?redirect=/path` | The sign-in page: one "Sign in with Rock Software ID" button, and the reason for a failed sign-in |
| `GET /sso/login?redirect=/path` | Starts the authorization code flow with PKCE |
| `GET /sso/callback` | Redirect URI registered at the provider |
| `GET\|POST /sso/logout` | Signs out here and at the provider |
| `POST /sso/backchannel-logout` | Back-channel logout URI registered at the provider |
| `GET\|POST /sso/emergency/{token}` | Single-use sign-in from `bin/cake sso emergency_login` |
| `GET /sso/settings`, `POST /sso/settings/update`, `POST /sso/settings/test` | Settings › Identity provider: issuer, client ID, write-only secret, required group. Save checks the server first and stores nothing if it does not answer |

It also ships `Sso\Middleware\SessionCheckMiddleware`, which signs out a session whose
user is gone or deactivated, or which started before a back-channel logout, and Finnish
translations of every text (`resources/locales/fi/sso.po`, domain `sso`).

- ID and logout tokens are verified against the provider's JWKS with `firebase/php-jwt`;
  only asymmetric algorithms are accepted. Issuer, audience, nonce, state and PKCE are
  checked by the plugin.
- A user row is matched on `external_id`, then linked on a **verified** email address
  (how existing local accounts move over), else created. Email, name and the active flag
  are rewritten on every sign-in.
- A required provider group (`sso_settings.required_group`) gates access.
- Back-channel logout moves the configured `sessionsValidFromField` forward, which ends
  every session the user had.
- The connection lives in `sso_settings`; the client secret is encrypted with a key
  derived from `Security.salt`.

Authorization stays in the application. The plugin only establishes who the user is.

## Installing in an application

1. **Composer.** Published on Packagist:

   ```bash
   composer require makallio85/cakephp-sso:^1.0
   ```

   Releases are tags on `master` (semantic versioning).

2. **Plugin.** `$this->addPlugin(\Sso\SsoPlugin::class);` in `Application::bootstrap()`.

3. **Migrations.** Run the plugin's before the application's:
   `bin/cake migrations migrate -p Sso`. The application then adds to its users table:

   | Column | Type |
   |---|---|
   | `external_id` | `varchar(64)`, unique, nullable |
   | `identity_source_id` | FK → `identity_source_types.id` (`local` / `sso`) |
   | `synced_at` | datetime, nullable |

   and makes `password` nullable, since provider-backed rows have none.

4. **CSRF.** Exempt the back-channel logout, which is a signed server-to-server POST:

   ```php
   $csrf->skipCheckCallback(fn($request) => \Sso\SsoPlugin::isCsrfExempt($request));
   ```

5. **Configuration** (`config/app.php`, application shape only — no secrets):

   ```php
   'Sso' => [
       'userModel' => 'Users',
       'loginUrl' => '/sso',                 // the plugin's sign-in page
       'loginRedirect' => '/',
       'layout' => 'auth',                   // layout of the sign-in and emergency pages
       'sessionsValidFromField' => 'sessions_valid_from',
       'lastLoginField' => 'last_login_at',  // stamped at every sign-in; null to skip
       'createUsers' => true,
       // Who may open Settings › Identity provider. Without it, nobody may.
       'settingsAccess' => fn(\Authentication\IdentityInterface $identity): bool => true,
   ],
   ```

   Point the Authentication service's `unauthenticatedRedirect` at `/sso` as well.

6. **Session check.** Add the middleware right after `AuthenticationMiddleware`:

   ```php
   ->add(new AuthenticationMiddleware($this))
   ->add(new \Sso\Middleware\SessionCheckMiddleware())
   ```

7. **Menu.** Link to `/sso/settings` (`['plugin' => 'Sso', 'controller' => 'Settings',
   'action' => 'index']`). The page's controller extends the application's own
   `App\Controller\AppController`, so it renders in the application's layout with its menu
   and signed-in user.

8. **Look.** Override `templates/plugin/Sso/Sso/index.php` (sign-in page) or
   `templates/plugin/Sso/Settings/index.php` (Bootstrap 5 markup by default) to restyle
   them. Texts use the `sso` domain; Finnish ships with the plugin.

9. **Events.** Listen where the application keeps its own side effects:

   | Event | Data | Typical use |
   |---|---|---|
   | `Sso.beforeUserSave` | `user`, `claims`, `isNew` | Fill columns the plugin does not know |
   | `Sso.afterLogin` | `user`, `claims`, `method` | Audit log |
   | `Sso.beforeLogout` | `user` | Audit log |
   | `Sso.backchannelLogout` | `user` | Audit log |

10. **Own second factor.** An application with one skips it when
   `\Sso\Session\SsoSession::isSsoSession($session)` is true: the provider already
   required a second factor.


## Connecting to the provider

Each application is an OAuth2/OpenID provider plus an application in Authentik, defined
as a blueprint in `rocksoftware-identity`. Then, in the application container:

```bash
bin/cake sso configure \
    --issuer https://id.rocksoftware.fi/application/o/<slug>/ \
    --client-id <client id> \
    --required-group <app>-staff \
    --enable
# the client secret is asked for interactively
```

## Emergency sign-in

When the provider is unavailable, someone with a shell in the application container runs:

```bash
bin/cake sso emergency_login person@example.com --minutes 15
```

and opens the printed link. It works once, expires, and is logged.

## Development

```bash
composer install
vendor/bin/phpunit
composer cs-check
```

Tests use SQLite and a fake provider (`tests/TestCase/FakeProvider.php`) served through
CakePHP's HTTP client mocks, so no live provider is needed.
