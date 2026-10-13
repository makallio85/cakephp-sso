# Agent guide — cakephp-sso

A **CakePHP 5 plugin** (Composer package `makallio85/cakephp-sso`, public repository) that signs
users in through the central identity provider with OpenID Connect. It is installed by
every Rock Software CakePHP application, so a change here reaches all of them. The
container defined in `docker-compose.yml` is a development sandbox, not a deployment.

## Quick start

```bash
composer install
vendor/bin/phpunit
composer cs-check
```

## Rules

1. **Never merge a PR.** Open PRs against `dev` only, never `master`.
2. **Security-critical code.** Token validation (`src/Service/OidcClient.php`), the
   callback's state/nonce/PKCE checks, and the redirect allowlist in
   `SsoController::safeRedirect()` each have tests that prove a forged or replayed
   response is refused. Never weaken a check to make a test pass; add a test for every
   new rejection path.
3. **No application knowledge.** The plugin establishes identity and provides the pieces
   every application needs around it (sign-in page, session check, last login, the
   settings page). Roles, permissions, audit logging and an application's own second
   factor stay in the application, reached through the events and `Sso.settingsAccess`.
4. **Texts are translatable.** Every user-facing string goes through `__d('sso', …)` and
   has a Finnish entry in `resources/locales/fi/sso.po` in the same change.
5. **Configuration**: the provider connection lives in `sso_settings` (secret encrypted
   by `SecretCipher`); application shape lives in `Configure::read('Sso')`. Nothing in
   environment variables.
6. **Tests** run on SQLite against `tests/TestCase/FakeProvider.php`. Fixtures hold a
   fresh installation's rows only; `identity_source_types` rows come from the
   migration, and each test creates the users it needs.
7. Do not commit `vendor/`, `composer.lock`, `tmp/` or `.phpunit.cache/`.
