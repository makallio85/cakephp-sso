# cakephp-sso

CakePHP 5 plugin that signs users in through the central Rock Software identity
provider (`id.rocksoftware.fi`, Authentik) with OpenID Connect. Each application keeps
its `users` table as the anchor for its foreign keys; the plugin creates and refreshes
those rows from the provider, so no application manages people itself.

Design: [makallio85/rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5). Private repository.

## What it does

| Route | Purpose |
|---|---|
| `GET /sso/login?redirect=/path` | Starts the authorization code flow with PKCE |
| `GET /sso/callback` | Redirect URI registered at the provider |
| `GET\|POST /sso/logout` | Signs out here and at the provider |
| `POST /sso/backchannel-logout` | Back-channel logout URI registered at the provider |
| `GET\|POST /sso/emergency/{token}` | Single-use sign-in from `bin/cake sso emergency_login` |

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

1. **Composer.** The repository is private, so the application needs a VCS repository
   entry and a GitHub token at build time (see the consuming app's Dockerfile and CI):

   ```json
   "repositories": [{"type": "vcs", "url": "https://github.com/makallio85/cakephp-sso"}],
   "require": {"makallio85/cakephp-sso": "dev-master"}
   ```

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
       'loginUrl' => '/users/login',
       'loginRedirect' => '/',
       'layout' => 'auth',
       'sessionsValidFromField' => 'sessions_valid_from',
       'createUsers' => true,
   ],
   ```

6. **Events.** Listen where the application keeps its own side effects:

   | Event | Data | Typical use |
   |---|---|---|
   | `Sso.beforeUserSave` | `user`, `claims`, `isNew` | Fill columns the plugin does not know |
   | `Sso.afterLogin` | `user`, `claims`, `method` | Last login, audit log, session freshness |
   | `Sso.beforeLogout` | `user` | Audit log |
   | `Sso.backchannelLogout` | `user` | Audit log |

7. **Own second factor.** An application with one skips it when
   `\Sso\Session\SsoSession::isSsoSession($session)` is true: the provider already
   required a second factor.

8. **Sign-in button.** Link to `/sso/login` from the login page.

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
