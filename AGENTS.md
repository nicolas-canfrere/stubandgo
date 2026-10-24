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

`phpunit.dist.xml` sets `failOnDeprecation`, `failOnNotice` and
`failOnWarning`: a deprecation, notice or warning triggered by code in `src/`
fails the suite.

Coverage, CRAP and mutations are measured on `src/` only.

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
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- `make phpstan` and `make cs-check`: static analysis and coding standards.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
