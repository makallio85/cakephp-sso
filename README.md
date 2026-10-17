# rocksoftware-cakephp-users

CakePHP 5 plugin for everything about signing in to a Rock Software application. It
signs users in through the central Rock Software identity provider
(`id.rocksoftware.fi`, Authentik) with OpenID Connect, and provides password sign-in,
session limits, the sign-in history, the users page and the user menu. No application
handles sign-in itself.

Formerly `makallio85/cakephp-sso` (plugin `Sso`, routes `/sso/*`). 2.0 renamed
everything; an application moving over reconnects to Rock Software ID (below).

| | Name |
|---|---|
| Composer package | `makallio85/rocksoftware-cakephp-users` |
| CakePHP plugin, namespace | `RockSoftware/Users`, `RockSoftware\Users\` |
| Routes | `/auth/*` |
| Settings, events, session keys | `RockSoftwareUsers.*` |
| Tables | `rock_software_users_*` |
| Translation domain | `rock_software_users` |
| Commands | `bin/cake rock_software_users …` | Each application keeps
its `users` table as the anchor for its foreign keys; the plugin creates and refreshes
those rows from the provider, so no application manages people itself. Installing it
is three steps and no code.

Design: [makallio85/rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5).

## What it does

| Route | Purpose |
|---|---|
| `GET /auth?redirect=/path` | The sign-in page: "Sign in with Rock Software ID", the email and password form while password sign-in is on, and the reason for a failed sign-in |
| `GET /auth/start?redirect=/path` | Starts the authorization code flow with PKCE |
| `POST /auth/password` | The sign-in page's email and password form |
| `GET /auth/callback` | Redirect URI registered at the provider |
| `GET\|POST /auth/sign-out` | Signs out here and at the provider |
| `POST /auth/backchannel-logout` | Back-channel logout URI registered at the provider |
| `GET\|POST /auth/emergency/{token}` | Single-use sign-in from `bin/cake rock_software_users emergency_sign_in` |
| `GET /auth/settings`, `POST /auth/settings/update`, `POST /auth/settings/test` | Settings › Identity provider: issuer, client ID, write-only secret, required group. Save checks the server first and stores nothing if it does not answer |
| `POST /auth/settings/password-sign-in`, `POST /auth/settings/sessions` | The password sign-in switch; the session limits and the sign-in history's retention |
| `GET /auth/users`, `GET /auth/users/{id}`, `POST …/deactivate`, `POST …/activate`, `POST …/end-sessions` | The users page: who uses the application, how they last signed in, each user's sign-in history |

Loading the plugin wires it into the application: its middleware hook places the
back-channel logout before CSRF, provides session authentication when the application
has none, adds `SessionCheckMiddleware` (signs out a session whose user is gone or
deactivated, or which started before a back-channel logout) and requires sign-in for
every route except the sign-in routes and `RockSoftwareUsers.publicPaths`. Every text has a Finnish
translation (`resources/locales/fi/rock_software_users.po`, domain `rock_software_users`).

- ID and logout tokens are verified against the provider's JWKS with `firebase/php-jwt`;
  only asymmetric algorithms are accepted. Issuer, audience, nonce, state and PKCE are
  checked by the plugin.
- The link between a user and the provider lives in the plugin's `rock_software_users_identities` table
  (`user_id`, `external_id`, `synced_at`, `last_login_at`, `sessions_valid_from`), so
  the application's `users` table needs no columns for it.
- A user is found through their identity, else linked on a **verified** email address
  (how existing local accounts move over), else created. Email, and name when the table
  has it, are rewritten on every sign-in. A new row's NOT NULL `password` gets an
  unusable value. A user deactivated in the application (`is_active` off) is refused:
  the provider does not reopen access an administrator closed here (1.6).
- A required provider group (`rock_software_users_settings.required_group`) gates access.
- The connection lives in `rock_software_users_settings`; the client secret is encrypted with a key
  derived from `Security.salt`.
- After every `bin/cake migrations migrate` or `rollback`, the plugin empties the schema
  cache (`cacheMetadata`) of the application's connections, so a migration that changes a
  table (moving over drops `users.password`, for one) never leaves queries reading the old
  layout.

Authorization stays in the application. The plugin only establishes who the user is.

## Installing in an application

The application needs a `users` table with `id` and `email`; `name` and `is_active` are
written when present.

1. **Composer.** `composer require makallio85/rocksoftware-cakephp-users:^2.0` (Packagist; releases are
   tags on `master`).
2. **Plugin.** `$this->addPlugin(\RockSoftware\Users\UsersPlugin::class);` in `Application::bootstrap()`.
3. **Migrations.** `bin/cake migrations migrate -p RockSoftware/Users`.

That is all. Then connect it to the provider (below) and sign in at `/auth`.

### Settings

Every setting has a default; override them under `RockSoftwareUsers` in `config/app.php`. None is a
secret.

| Setting | Default | |
|---|---|---|
| `appName` | `null` | Shown on the plugin's sign-in page |
| `signInRedirect` | `'/'` | Where a person lands after signing in |
| `publicPaths` | `[]` | `fnmatch` patterns reachable without signing in, e.g. `'/health'`, `'/public-api/*'` |
| `settingsAccess` | `null` | Who may open `/auth/settings`: `fn($identity, $request): bool`. Null means anyone signed in, who is already in the required group |
| `usersAccess` | `null` | Who may open `/auth/users`, the same kind of callable. Null means whoever may open the settings page |
| `layout` | `'RockSoftware/Users.sign_in'` | Layout of the sign-in and emergency pages; name the application's own to restyle them |
| `userModel` | `'Users'` | The application's users table |
| `fields` | `[]` | Column names when they differ: `['email' => …, 'name' => …, 'is_active' => …, 'password' => …]`; null skips one |
| `createUsers` | `true` | Create a user on first sign-in when none matches |
| `authentication` | `'auto'` | Provide authentication when the application has no `AuthenticationMiddleware`; `true`/`false` force it |
| `requireSignIn` | `'auto'` | Require sign-in everywhere when the plugin provides authentication; `true`/`false` force it |
| `signInUrl` | `'/auth'` | The sign-in page |
| `cacheConfig` | `'default'` | Cache for the provider's discovery document and keys |
| `passwordSignIn` | `false` | `true` when people may also sign in with a password from the users table: the sign-in page shows the form and `/auth/settings` the switch (see below) |
| `provisioning` | see below | `POST /auth/provision`; `false` switches it off |

An application with its own `AuthenticationMiddleware` keeps it, and keeps deciding which
of its pages are public; point its `unauthenticatedRedirect` at `/auth`.

### Optional

- **Menu.** Link to `/auth/settings` (`['plugin' => 'RockSoftware/Users', 'controller' => 'Settings',
  'action' => 'index']`) and `/auth/users` (`'controller' => 'Users'`). Their
  controllers extend the application's own `App\Controller\AppController`, so they
  render in the application's layout. Put the user menu in the layout (below).
- **Look.** Override `templates/plugin/RockSoftware/Users/SignIn/index.php` (sign-in page),
  `templates/plugin/RockSoftware/Users/Settings/index.php` or `templates/plugin/RockSoftware/Users/Users/*.php`
  (Bootstrap 5 markup by default).
- **Events.**

  | Event | Data | Typical use |
  |---|---|---|
  | `RockSoftwareUsers.beforeUserSave` | `user`, `claims`, `isNew` | Fill columns the plugin does not know |
  | `RockSoftwareUsers.afterSignIn` | `user`, `claims`, `method` | Audit log |
  | `RockSoftwareUsers.beforeSignOut` | `user` | Audit log |
  | `RockSoftwareUsers.backchannelLogout` | `user` | Audit log |
  | `RockSoftwareUsers.provisioned` | `issuer`, `clientId`, `requiredGroup` | Audit log; tell the administrators |
  | `RockSoftwareUsers.passwordSignInChanged` | `user`, `enabled` | Audit log |
  | `RockSoftwareUsers.sessionLimitsChanged` | `user`, `limits` | Audit log |
  | `RockSoftwareUsers.userDeactivated`, `RockSoftwareUsers.userActivated`, `RockSoftwareUsers.sessionsEnded` | `user`, `actor` | Audit log |
  | `RockSoftwareUsers.userListColumns` | `columns` (`ArrayObject`), `users` | Add columns to the users page: `['label' => …, 'value' => fn($user): string]` |
  | `RockSoftwareUsers.userViewSections` | `sections` (`ArrayObject`), `user` | Add a section to a user's page: `['title' => …, 'element' => …, 'data' => [...]]`, rendered with the application's element and `$user` |

- **Own second factor.** Skip it when `\RockSoftware\Users\Session\SignInSession::isProviderSession($session)`
  is true: the provider already required a second factor. A plugin password session is
  not one; an application with its own second factor keeps its own password form.

### Moving from cakephp-sso 1.x

1. `composer remove makallio85/cakephp-sso && composer require makallio85/rocksoftware-cakephp-users:^2.0`.
2. `addPlugin('RockSoftware/Users')`; migrations `-p RockSoftware/Users`; settings move
   from `Sso` to `RockSoftwareUsers` (`loginUrl` → `signInUrl`, `loginRedirect` →
   `signInRedirect`); events and links per the table above.
3. Drop the old `sso_*` tables and `sso_phinxlog` when convenient; nothing reads them.
4. Deploy, then reconnect: `new-id-app.sh --update --rotate-secret` (it reads
   `/auth/client-metadata` and pushes to `/auth/provision`). Each person's account is
   linked again on their next sign-in by verified email address.

## Connecting to the provider

`new-id-app.sh` in infra-docs registers the application in Rock Software ID and pushes
the connection to `POST /auth/provision`, so nothing is typed into the application. The
design is in [rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5),
*Connecting applications automatically*.

The request carries the issuer, client ID, client secret and required group, and a
bearer token the provider issued through token exchange for that client. The plugin
accepts it only when:

- the application has no connection yet, or an administrator pressed *Allow Rock
  Software ID to connect again* on `/auth/settings` within the last hour; every
  provisioning closes it again;
- the issuer is directly under `provisioning.issuerPrefix`
  (`https://id.rocksoftware.fi/application/o/`), checked before anything is fetched,
  and the provider's keys are on the same host;
- the token is signed with that issuer's key, its `iss` and `aud` equal the issuer and
  client ID it delivers, it was issued to `provisioning.subject` (`onboarding`) at most
  `provisioning.maxAge` (300) seconds ago, and its `jti` was never used before.

Any refusal is a bare 401 (400 for a malformed request, 429 over
`provisioning.rateLimit` per `provisioning.rateWindow`), with the reason in the log and
never the request body. Each provisioning is kept in `rock_software_users_provisionings`.

By hand, register the application in Authentik's admin UI (the steps are in
[rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5)),
then enter the issuer, client ID, secret and required group on `/auth/settings`, or in
the application container:

```bash
bin/cake rock_software_users configure \
    --issuer https://id.rocksoftware.fi/application/o/<slug>/ \
    --client-id <client id> \
    --required-group <app>-staff \
    --enable
# the client secret is asked for interactively
```

## Password sign-in

With `passwordSignIn` set to `true`, the sign-in page shows an email and password form
under the Rock Software ID button, checked against the users table's password column
(`fields.password`) with CakePHP's default hasher, so existing passwords keep working;
an outdated hash is replaced on the next sign-in. Refused attempts are recorded in the
sign-in history and throttled from it: 5 per address or 20 per client address within
15 minutes. There is no self-service reset; an administrator sets a password with

```bash
bin/cake rock_software_users set_password person@example.com   # reads the password from standard input
```

or the person signs in through Rock Software ID.

Settings › Identity provider shows *Turn password sign-in off / on*. The switch is stored
in `rock_software_users_settings.password_sign_in_enabled`, with who changed it and when. Off hides the
form and ends open password sessions on their next request: the plugin's own, and any
session an application's own password form established. Provider sessions, emergency
links and token requests are untouched.

It is on until someone turns it off, and always on before the first connection. Turning
it **off** needs a session signed in through the provider (not a password session, not an
emergency link) and a connection that answers, so nobody locks themselves out; turning it
**on** works from any session allowed on the page.

An application that still has a password form of its own (while it moves over;
applications do not handle sign-in themselves) enforces the switch there:

```php
if (!$this->fetchTable('RockSoftware/Users.Settings')->passwordSignInAllowed()) {
    // hide the form, and refuse a password POST like a wrong password
}
```

## Users page and sign-in history

`/auth/users` lists the application's users with whether they are linked to Rock
Software ID and when and how they last signed in, as cards at phone width. A user's page
shows their sign-in history (successful and refused sign-ins, sign-outs, back-channel
logouts, administrators' actions, with the client's IP address) and lets an
administrator:

- **Deactivate / activate** the user in this application (`is_active`). Deactivating
  ends their sessions on their next request, and signing in, through the provider too,
  is refused until they are activated again. Nobody deactivates themselves.
- **End sessions**: every session of the user in this application ends on its next
  request.

Who may use the application at all is still decided in Rock Software ID; roles stay the
application's, added to the page through `RockSoftwareUsers.userListColumns` and `RockSoftwareUsers.userViewSections`.

The history lives in `rock_software_users_session_events` (types in `rock_software_users_session_event_types` and
`rock_software_users_sign_in_method_types`) and is kept for 180 days unless Settings › Identity provider
says otherwise.

## Sessions

Settings › Identity provider › Sessions sets the longest session (hours) and the idle
time after which a person is signed out (minutes). Both are off by default. They apply
to every session the plugin established.

## User menu

The signed-in person's menu, for the application's layout. A ready dropdown (no script,
colours from `--rsu-menu-*` CSS variables):

```php
<?= $this->element('RockSoftware/Users.user_menu') ?>
```

Or the same entries as data, to render in the application's own menu:

```php
$this->loadHelper('RockSoftware/Users.UserMenu');
foreach ($this->UserMenu->items() as $item) {
    // header (label, detail) · app (label, url, current) · link (label, url)
    // · sign_out (label, url, method = post: render with $this->Form->postLink())
}
```

The applications listed are the ones the person may open, from the provider's `apps`
claim (rocksoftware-identity's profile scope mapping), kept in the session at sign-in.
Nothing is listed after a password or emergency sign-in.

## Emergency sign-in

When the provider is unavailable, someone with a shell in the application container runs:

```bash
bin/cake rock_software_users emergency_sign_in person@example.com --minutes 15
```

and opens the printed link. It works once, expires, and is logged.


`--create` also creates the user when no one has the address, for an empty
development database such as a pull request preview, where AI agents sign in this
way. It is refused when the application runs with `debug` off.

## For automation

| What | Use |
|---|---|
| `GET /auth/client-metadata` | The addresses the identity provider must register (callback, sign-out return paths from `signInUrl`, back-channel logout, provisioning) and the plugin version. Public, answered before the application's authentication; `new-id-app.sh` reads it |
| `bin/cake rock_software_users status` | The stored connection as JSON (configured, enabled, issuer, client ID, required group), never the secret |

## Development

```bash
composer install
vendor/bin/phpunit
composer cs-check
```

Tests use SQLite and a fake provider (`tests/TestCase/FakeProvider.php`) served through
CakePHP's HTTP client mocks, so no live provider is needed.
