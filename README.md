# cakephp-sso

CakePHP 5 plugin that signs users in through the central Rock Software identity
provider (`id.rocksoftware.fi`, Authentik) with OpenID Connect. Each application keeps
its `users` table as the anchor for its foreign keys; the plugin creates and refreshes
those rows from the provider, so no application manages people itself. Installing it
is three steps and no code.

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

Loading the plugin wires it into the application: its middleware hook places the
back-channel logout before CSRF, provides session authentication when the application
has none, adds `SessionCheckMiddleware` (signs out a session whose user is gone or
deactivated, or which started before a back-channel logout) and requires sign-in for
every route except the sign-in routes and `Sso.publicPaths`. Every text has a Finnish
translation (`resources/locales/fi/sso.po`, domain `sso`).

- ID and logout tokens are verified against the provider's JWKS with `firebase/php-jwt`;
  only asymmetric algorithms are accepted. Issuer, audience, nonce, state and PKCE are
  checked by the plugin.
- The link between a user and the provider lives in the plugin's `sso_identities` table
  (`user_id`, `external_id`, `synced_at`, `last_login_at`, `sessions_valid_from`), so
  the application's `users` table needs no columns for it.
- A user is found through their identity, else linked on a **verified** email address
  (how existing local accounts move over), else created. Email, and name and active flag
  when the table has them, are rewritten on every sign-in. A new row's NOT NULL
  `password` gets an unusable value.
- A required provider group (`sso_settings.required_group`) gates access.
- The connection lives in `sso_settings`; the client secret is encrypted with a key
  derived from `Security.salt`.

Authorization stays in the application. The plugin only establishes who the user is.

## Installing in an application

The application needs a `users` table with `id` and `email`; `name` and `is_active` are
written when present.

1. **Composer.** `composer require makallio85/cakephp-sso:^1.2` (Packagist; releases are
   tags on `master`).
2. **Plugin.** `$this->addPlugin(\Sso\SsoPlugin::class);` in `Application::bootstrap()`.
3. **Migrations.** `bin/cake migrations migrate -p Sso`.

That is all. Then connect it to the provider (below) and sign in at `/sso`.

### Settings

Every setting has a default; override them under `Sso` in `config/app.php`. None is a
secret.

| Setting | Default | |
|---|---|---|
| `appName` | `null` | Shown on the plugin's sign-in page |
| `loginRedirect` | `'/'` | Where a person lands after signing in |
| `publicPaths` | `[]` | `fnmatch` patterns reachable without signing in, e.g. `'/health'`, `'/public-api/*'` |
| `settingsAccess` | `null` | Who may open `/sso/settings`: `fn($identity, $request): bool`. Null means anyone signed in, who is already in the required group |
| `layout` | `'Sso.sso'` | Layout of the sign-in and emergency pages; name the application's own to restyle them |
| `userModel` | `'Users'` | The application's users table |
| `fields` | `[]` | Column names when they differ: `['email' => …, 'name' => …, 'is_active' => …]`; null skips one |
| `createUsers` | `true` | Create a user on first sign-in when none matches |
| `authentication` | `'auto'` | Provide authentication when the application has no `AuthenticationMiddleware`; `true`/`false` force it |
| `requireSignIn` | `'auto'` | Require sign-in everywhere when the plugin provides authentication; `true`/`false` force it |
| `loginUrl` | `'/sso'` | The sign-in page |
| `cacheConfig` | `'default'` | Cache for the provider's discovery document and keys |
| `passwordSignIn` | `false` | `true` when the application has a password form of its own: `/sso/settings` then offers to turn it off and on (see below) |
| `provisioning` | see below | `POST /sso/provision`; `false` switches it off |

An application with its own `AuthenticationMiddleware` keeps it, and keeps deciding which
of its pages are public; point its `unauthenticatedRedirect` at `/sso`.

### Optional

- **Menu.** Link to `/sso/settings` (`['plugin' => 'Sso', 'controller' => 'Settings',
  'action' => 'index']`). Its controller extends the application's own
  `App\Controller\AppController`, so it renders in the application's layout.
- **Look.** Override `templates/plugin/Sso/Sso/index.php` (sign-in page) or
  `templates/plugin/Sso/Settings/index.php` (Bootstrap 5 markup by default).
- **Events.**

  | Event | Data | Typical use |
  |---|---|---|
  | `Sso.beforeUserSave` | `user`, `claims`, `isNew` | Fill columns the plugin does not know |
  | `Sso.afterLogin` | `user`, `claims`, `method` | Audit log |
  | `Sso.beforeLogout` | `user` | Audit log |
  | `Sso.backchannelLogout` | `user` | Audit log |
  | `Sso.provisioned` | `issuer`, `clientId`, `requiredGroup` | Audit log; tell the administrators |
  | `Sso.passwordSignInChanged` | `user`, `enabled` | Audit log |

- **Own second factor.** Skip it when `\Sso\Session\SsoSession::isSsoSession($session)`
  is true: the provider already required a second factor.

### Upgrading from 1.1

The 1.2 migration copies every `users` row with an `external_id` into `sso_identities`.
The wiring 1.1 asked for (CSRF exemption, `SessionCheckMiddleware`) can stay; the plugin
adds neither twice. Once it is removed, drop `external_id`, `identity_source_id`,
`synced_at`, `sessions_valid_from` and `last_login_at` from `users` at your own pace;
until then the plugin keeps them in step when `sessionsValidFromField` /
`lastLoginField` name them.

## Connecting to the provider

`new-id-app.sh` in infra-docs registers the application in Rock Software ID and pushes
the connection to `POST /sso/provision`, so nothing is typed into the application. The
design is in [rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5),
*Connecting applications automatically*.

The request carries the issuer, client ID, client secret and required group, and a
bearer token the provider issued through token exchange for that client. The plugin
accepts it only when:

- the application has no connection yet, or an administrator pressed *Allow Rock
  Software ID to connect again* on `/sso/settings` within the last hour; every
  provisioning closes it again;
- the issuer is directly under `provisioning.issuerPrefix`
  (`https://id.rocksoftware.fi/application/o/`), checked before anything is fetched,
  and the provider's keys are on the same host;
- the token is signed with that issuer's key, its `iss` and `aud` equal the issuer and
  client ID it delivers, it was issued to `provisioning.subject` (`onboarding`) at most
  `provisioning.maxAge` (300) seconds ago, and its `jti` was never used before.

Any refusal is a bare 401 (400 for a malformed request, 429 over
`provisioning.rateLimit` per `provisioning.rateWindow`), with the reason in the log and
never the request body. Each provisioning is kept in `sso_provisionings`.

By hand, register the application in Authentik's admin UI (the steps are in
[rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5)),
then enter the issuer, client ID, secret and required group on `/sso/settings`, or in
the application container:

```bash
bin/cake sso configure \
    --issuer https://id.rocksoftware.fi/application/o/<slug>/ \
    --client-id <client id> \
    --required-group <app>-staff \
    --enable
# the client secret is asked for interactively
```

## Password sign-in beside the provider

An application moving over from its own password form sets `passwordSignIn` to `true`.
Settings › Identity provider then shows *Turn password sign-in off / on*. The plugin
stores the switch (`sso_settings.password_sign_in_enabled`, with who changed it and when)
and the application enforces it on its own form:

```php
if (!$this->fetchTable('Sso.SsoSettings')->passwordSignInAllowed()) {
    // hide the form, and refuse a password POST like a wrong password
}
```

Turning it off also ends open password sessions: the plugin's session check signs out
any browser session it did not establish on its next request. Provider sessions,
emergency links and token requests are untouched.

It is on until someone turns it off, and always on before the first connection. Turning
it **off** needs a session signed in through the provider (not a password session, not an
emergency link) and a connection that answers, so nobody locks themselves out; turning it
**on** works from any session allowed on the page.

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
