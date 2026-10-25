# AGENTS.md

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Conventions

These rules are project decisions; the design document in the separate
documentation repository (`recherches/specs/2026-10-24-stubandgo-design.md`,
§ 11) explains why.

### Architecture

- Hexagonal, one hexagon per business context (screaming architecture):
  `src/<Context>/Core/Application/` (commands, queries, handlers, published
  language), `src/<Context>/Core/Domain/` (aggregates, entities, value
  objects, ports), `src/<Context>/Infrastructure/` (adapters),
  `src/<Context>/UI/` (HTTP, CLI). Contexts: `Catalog`, `Sales`, `Access`.
- `src/Shared/` is the shared kernel: technical value objects, identifiers
  used by more than one context, bus interfaces, synchronous domain events and
  the value objects they carry. Nothing else.
  `src/Shared/Infrastructure/` holds cross-cutting adapters (buses,
  transaction middleware, outbox) and never depends on a context.
- Dependencies are enforced by Deptrac (`make deptrac`, `deptrac.yaml`): a
  domain sees only `Shared`, PSR, `moneyphp/money` and `symfony/uid`; `UI`
  never touches `Domain`; a context reaches another one only through that
  context's `Core/Application` from its own `Infrastructure`.
- Exceptions live in an `Exception/` directory, value objects in a
  `ValueObject/` directory.

### Naming

Symfony naming conventions
(https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions):
interfaces suffixed `Interface`, traits `Trait`, exceptions `Exception`,
abstract classes prefixed `Abstract`; no `Class`/`Enum` suffix.

### Domain model

- No primitive obsession in a domain: every business concept is a value
  object.
- Value objects are `final readonly`, built through a named constructor
  (private constructor), validate themselves (throwing
  `App\Shared\Exception\InvalidValueException`) and expose
  `equals(self $other): bool`.
- Identifiers are ULIDs stored in canonical uppercase base32
  (`App\Shared\Identity\AbstractUlidId`).
- Money is `Money\Money` (EUR, integer cents), never a float. Time comes from
  an injected `Psr\Clock\ClockInterface`; dates are stored as UTC
  `timestamptz`.

### CQS and buses

- Four Messenger buses: `command.bus` (one DBAL transaction per command,
  opened by `DbalTransactionMiddleware`), `query.bus`, `event.bus`
  (synchronous domain events, run inside the command transaction) and
  `async.bus` (default bus).
- Command handlers return `void`; the caller generates the ULID of a new
  aggregate. Commands and queries carry only `string`, `int`, `bool`,
  `float` and `array`; handlers rebuild value objects on entry. Queries
  return read DTOs defined in `Core/Application`.
- Queries implement `App\Shared\Bus\QueryInterface` with
  `@implements QueryInterface<ResultType>`, so `QueryBusInterface::ask()`
  returns a typed result (no `@var` at call sites).
- Commands implement `App\Shared\Bus\CommandInterface`, so
  `CommandBusInterface::dispatch()` only accepts commands.
- Never open a transaction by hand in a handler.

### Asynchronous messages

- Asynchronous messages implement
  `App\Shared\Infrastructure\Outbox\AsyncMessageInterface`, carry simple
  types only and are written to the outbox (`OutboxWriterInterface`) in the
  same transaction as the state change; the scheduled relay publishes them to
  RabbitMQ. Never dispatch to RabbitMQ directly from business code.
- Delivery is at least once: consumers are idempotent.
- Constructor guards of messages never echo their input in exception
  messages (the message ends up in logs and in `outbox_message.failure_reason`).

### Database

- Doctrine DBAL only, no ORM. Migrations are written by hand in
  `migrations/`; never edit a migration once it has been released.
- Every table and every column carries an English PostgreSQL comment
  describing its usage (`COMMENT ON TABLE` / `COMMENT ON COLUMN`), set by the
  migration that creates or changes it. `DatabaseSchemaCommentsTest` fails
  otherwise.

### HTTP

Error responses follow RFC 9457 Problem Details
(`application/problem+json`, members `type`, `title`, `status`, `detail`,
`instance`); `type` is `/problems/<error_code>`.

Dates in API responses are UTC, RFC 3339 with a `Z` suffix; conversion to
Europe/Paris happens only for display (front, emails).

### Authentication

- SecurityBundle, firewall `api` (`^/api`, stateless) in
  `config/packages/security.yaml`: the `access_token` authenticator with the
  OIDC token handler validates Keycloak access tokens (RS256, audience
  `stubandgo-api`, issuers of the realms `stubandgo` and `stubandgo-staff`).
  Dev and prod read the signing keys through the discovery on
  `KEYCLOAK_INTERNAL_URL`, cached in `cache.app` and fetched on the first
  token only (never while the container is built); the test environment
  trusts a static key set instead and never contacts Keycloak.
- The realm exports under `docker/keycloak/` pin each realm's RS256 signing
  key (`rsa` KeyProvider `rsa-demo-signing`, demo-only material): a
  recreated Keycloak container keeps the `kid` the API has cached. Never
  remove it, or every token fails until the cached key set expires.
- Routes are anonymous unless they ask for a user (`#[IsGranted]`); there is
  no global `access_control`. A presented token is always validated: an
  invalid one answers 401 even on a public route.
- The authenticated user is
  `App\Shared\Infrastructure\Security\AuthenticatedMember` (`realm()`,
  `hasRealmRole()`, `keycloakOrganizationId()`), built from the validated
  claims by `AuthenticatedMemberProvider`; nothing is loaded from the
  database. Get it with `#[CurrentUser] AuthenticatedMember $member`.
- 401 is rendered as problem details `/problems/unauthorized` with a
  `WWW-Authenticate: Bearer` challenge (`BearerTokenChallenge`).
- An authenticated member without the right gets 403 `/problems/forbidden`
  (Symfony's `AccessDeniedHttpException`, logged as a warning). The scan
  route asks `#[IsGranted('ACCESS_SCAN_EVENT', subject: 'eventId')]`,
  decided by `App\Access\Infrastructure\Security\ScanEventVoter` from the
  Catalog OHS `EventOrganizationProviderInterface`, before the dispatch: a
  refused scan is never recorded.
- In a test, sign a token with
  `App\Tests\Support\Security\TestTokenIssuer::issue()` (a Rock Asso
  controller by default; each override replaces a claim, `null` removes it)
  and send it as `Authorization: Bearer <token>`. `issueWithForeignKey()`
  signs with a key the API does not trust. The RSA keys under
  `tests/Support/Security/` are test material only.

### Keycloak administration and event locks (L3)

- Catalog's domain port `OrganizationDirectoryInterface` expresses the business
  operations on Keycloak (get-or-create the organization of a slug, invite a
  member), never HTTP calls. Its adapter is
  `App\Catalog\Infrastructure\Keycloak\KeycloakOrganizationDirectory`
  (service account token cached by `ServiceAccountTokenProvider`); it is
  tested alone with `MockHttpClient`, every step failing with its
  compensation.
- In the test environment the port is aliased to the in-memory double
  `App\Tests\Support\Catalog\InMemoryOrganizationDirectory`
  (`when@test` in `config/services.yaml`): HTTP tests never contact Keycloak.
  Unbound in prod it fails `make lint-container`.
- Event commands (update, publish, unpublish) load the event with
  `EventRepositoryInterface::getForUpdate()` (`SELECT ... FOR UPDATE`).
  `findForSale()`, used by the sale-terms OHS read by `PlaceOrder`, takes
  `FOR KEY SHARE` held until the order transaction ends: it blocks a
  concurrent unpublish without blocking the lock flag update of another
  order (`FOR SHARE` would deadlock two first orders). Never swap either.
- The realm exports are read by tests (`DemoRealmAlignmentTest`); a change
  to them needs `make keycloak-reset`.

### Tooling and commits

- The `Dockerfile` is generated by `dockerfile-gen` from
  `.devtools/dockerfile.json`: edit the JSON, then run `dockerfile-gen -a`.
  Never edit the `Dockerfile` by hand.
- Composer constraints are explicit carets (`^x.y`) except Symfony packages
  (`8.1.*`).
- Commits follow Conventional Commits, in English, without a
  `Co-Authored-By` trailer.

## Testing

PHPUnit 13, configured in `phpunit.dist.xml`. Tests live in `tests/`, under the
`App\Tests\` namespace (PSR-4, `autoload-dev`); `tests/bootstrap.php` loads
`.env` through Dotenv. `symfony/browser-kit` and `symfony/css-selector` are
installed, so `KernelTestCase` and `WebTestCase` are available.

Tests run in the `php` service of `compose.test.yaml`, with `APP_ENV=test` and
Xdebug in coverage mode:

- `make test`: the whole suite (`bin/phpunit`).
- `make crap`: the suite with Crap4j coverage in `var/coverage/crap4j.xml`, then
  `bin/crap-check`, which fails on any method with CRAP >= 9.
- `make mutation`: Infection on `src/` with the default mutators. It requires
  100% MSI and covered MSI (`infection.json5`); the log is in
  `var/infection/infection.log`, the HTML report in `var/infection/infection.html`.
  Every Infection thread uses the single `app_test` database; the target then
  fails if the test database logged any `FATAL` during the run (a test that
  cannot reach the database would otherwise count its mutants as killed).
- `make test-down`: stop and remove the test stack.

`phpunit.dist.xml` sets `failOnDeprecation`, `failOnNotice` and
`failOnWarning`: a deprecation, notice or warning triggered by code in `src/`
fails the suite.

Coverage, CRAP and mutations are measured on `src/` only.

Tests mirror `src/` under `tests/`. Test data is unique per test (derive
slugs and ids from a fresh ULID) so Infection can run tests in parallel
against the same database. Concurrency tests carry
`#[Group('concurrency')]`: `make test` runs them, Infection excludes them.

## Feature workflow

Every change or addition of a feature follows these steps, in order. Don't skip
one because the change looks small.

1. **Test first.** Write the tests that describe the expected behaviour, run
   `make test`, and confirm they fail for the right reason (missing behaviour,
   not a typo or a broken fixture).
2. **Implement** the minimum code that makes them pass. Run `make test` until
   the whole suite is green.
3. **CRAP < 9.** Run `make crap`. Every method must have a CRAP score strictly
   below 9; the target exits non-zero and lists the offenders otherwise. Fix an
   offender by refactoring (extract methods, remove branches) and/or covering
   the untested paths, then re-run `make test` and `make crap`.
4. **Mutation testing.** Run `make mutation` and read
   `var/infection/infection.log`. `infection.json5` requires 100% MSI and
   covered MSI, so the target fails on any escaped or uncovered mutant: kill
   each one with a sharper test. If a mutant is genuinely
   equivalent, say so explicitly with the reason instead of ignoring it.
5. **OpenAPI.** Any change to the HTTP API (routes, payloads, responses,
   documentation attributes) regenerates `openapi.yaml` with `make openapi` and
   commits it in the same commit.

Before declaring a task done, the whole gate must be green: `make openapi-check`,
`make openapi-lint`, `make test`, `make crap`, `make mutation`, `make phpstan`,
`make cs-check`, `make deptrac` and `make lint-container` (`make ci` runs them
all). `make lint-container` lints the production container: the test
environment redefines the bus and repository aliases, so only this target
exercises the production autowiring.

## OpenAPI document

The HTTP API is described by NelmioApiDocBundle (`config/packages/nelmio_api_doc.yaml`
and the OpenAPI attributes on the controllers and request DTOs in `src/*/UI/Http`).
The generated document is committed as `openapi.yaml` at the repository root:

- `make openapi`: regenerate `openapi.yaml` from `nelmio:apidoc:dump --format=yaml`
  (deterministic output, no compose noise).
- `make openapi-check`: regenerate into a temporary file and fail if it differs
  from the committed `openapi.yaml`.
- `make openapi-lint`: lint `openapi.yaml` with Redocly CLI (Docker image
  `redocly/cli`, version pinned by `REDOCLY_VERSION`) against the `recommended`
  ruleset of `redocly.yaml`. Fix findings at the source (Nelmio config or
  attributes); never disable or downgrade a rule.

## Code style

PHP CS Fixer enforces the style, configured in `.php-cs-fixer.dist.php`:
`@Symfony` and `@Symfony:risky`, plus the `@autoPHPMigration` and
`@autoPHPUnitMigration` sets, which follow the PHP and PHPUnit versions in
`composer.json`. Run `make cs-fix` rather than formatting by hand, and
`make cs-check` to verify without modifying files. Rules worth knowing because
they shape what you write:

- `declare(strict_types=1);` at the top of every PHP file.
- `ordered_class_elements`: constants, properties, constructor, then methods, in
  the order the fixer imposes.
- `no_superfluous_phpdoc_tags`: no `@param`/`@return` that only repeats a native
  type, no `{@inheritdoc}`. Write PHPDoc only when it adds information, e.g.
  generics or array shapes for PHPStan.
- `phpdoc_to_property_type`: declare property types natively, not in `@var`.
- `concat_space`: one space around `.`.
- `php_unit_method_casing` is off: test method casing is not enforced.

PHPStan runs at `level: max` on `src/` and `tests/` with the Symfony and
PHPUnit extensions (`phpstan.dist.neon`). Run `make phpstan`; fix errors with
precise types, never with `@phpstan-ignore` or a baseline. `make lint` runs
`cs-fix` then `phpstan`.

`.editorconfig`: UTF-8, LF, 4-space indentation, final newline, trimmed
trailing whitespace; 2 spaces in `compose*.yaml`.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory.

PHP and Composer are not installed on the host: they run in containers. Go
through `make` (`make help` lists the targets). Run console commands with
`make console CMD="<command>"`, e.g. `make console CMD="debug:router"`; for
anything else in PHP, `make php-sh` opens a shell in the `php` service of
`compose.yaml`.

Composer runs in the `composer` image pinned by `COMPOSER_VERSION`; use
`make install`, or `make composer-cli` for anything else (`composer show`,
`composer require`, ...).

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container` (`make lint-container` for the prod container),
  plus `lint:twig templates/` and `lint:yaml config/` where those packages are
  installed: validate before running.
- `make phpstan` and `make cs-check`: static analysis and coding standards.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
