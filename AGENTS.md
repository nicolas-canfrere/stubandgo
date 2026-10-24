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
