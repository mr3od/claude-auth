# Laravel Zero Best Practices — Primary-Source Research

Research target: [Laravel Zero official documentation](https://laravel-zero.com/docs), fetched directly
from laravel-zero.com on 2026-08-16. Every claim below is sourced from the actual doc page content
(fetched live), not from training knowledge or secondary blog posts. Each subsection cites the specific
page URL. Where the docs did not cover something the task asked about, that gap is stated explicitly
rather than filled in with a guess.

This document is research only, written to inform (not replace) implementation decisions for the
`claude-auth` CLI, which is scaffolded with 8 stub commands under `app/Commands/` and
`config/claude-auth.php` (keys: `home`, `claude_credentials_file`, `claude_json_file`, `max_backups`).

---

## 1. Command Structure Conventions

Source: [Commands](https://laravel-zero.com/docs/commands)

- Commands live in `app/Commands` and are "automatically registered" — no manual wiring needed. They
  extend `LaravelZero\Framework\Commands\Command`, which adds `schedule`, `task`, and `title` methods on
  top of Laravel's base `Command` class.
- Two required properties per command: `$signature` (name + input contract) and `$description` (shown in
  the command list). Logic goes in `handle()`: "The `handle` method will be called when your command is
  executed, and it is the place where the logic of your command should live."
- **Signature syntax** (route-like):
  - Required argument: `mail:send {user}`
  - Optional argument: `mail:send {user?}` or with a default `mail:send {user=foo}`
  - Boolean option flag: `mail:send {user} {--queue}`
  - Option requiring a value: `mail:send {user} {--queue=}`
  - Inline descriptions via colon: `{user : The ID of the user}` / `{--queue : Whether the job should be queued}`
- **Commands should be thin.** The docs state directly: "it is good practice to keep your console
  commands light and let them defer to application services." The example injects a service
  (`DripEmailer`) as a parameter of `handle()` rather than putting the "heavy lifting" inline:
  ```php
  public function handle(DripEmailer $drip): void
  {
      $drip->send($this->argument('user'));
      $this->info('The email was sent successfully.');
  }
  ```
  Dependencies type-hinted in `handle()`'s signature are auto-resolved by the service container.
- **Constructor injection is not documented.** The Commands page only demonstrates dependency injection
  via the `handle()` method signature; it never shows or discusses a command's `__construct()` receiving
  injected dependencies. I explicitly re-queried the page for this and confirmed: "constructor injection
  is not mentioned... The documentation explicitly shows dependency injection occurring in the handle
  method signature." This is a documentation gap, not a documented restriction — see §2 for why it likely
  still works mechanically, but it is unverified against the docs.
- The docs do not address organizing commands into subdirectories/namespaces within `app/Commands`
  beyond noting that additional scan paths can be configured.
- **Generator**: `php application make:command SendEmails` scaffolds a new command in `app/Commands`.
  Generated file templates can be customized via `php application stub:publish`, which publishes
  `stubs/console.stub` for future edits.

## 2. Service Container Usage

Source: [Service Providers](https://laravel-zero.com/docs/service-providers)

- "Service providers live in your application's `app/Providers` directory." A fresh install ships an
  `AppServiceProvider`, which matches what's already scaffolded at
  `~/claude-auth/app/Providers/AppServiceProvider.php`.
- Binding is done in a provider's `register()` method. The doc's only fully worked example uses
  `singleton`:
  ```php
  $this->app->singleton(MovieRepository::class, function ($app) {
      return new TmdbMovieRepository(config('services.tmdb.key'));
  });
  ```
  "Service providers are the best way to specify that a concrete implementation should be bound to a
  contract or interface." The page does not show a contrasting plain `bind()` example — `singleton` is
  the pattern actually demonstrated.
- Injection into a service provider's own `boot()` method is documented and works via type-hinting:
  "You may type-hint dependencies for your service provider's `boot` method. The service container will
  automatically inject any dependencies you need."
- Command-side injection is documented via `handle(MovieRepository $movies)` (same pattern as §1) — i.e.
  once a class is bound in the container (singleton or otherwise), it can be pulled into a command's
  `handle()` method by type-hint. **Constructor injection into commands is not shown on this page either.**
  I did not find a doc page that confirms or denies constructor-injection-in-commands directly.
  Laravel Zero commands are `Illuminate\Console\Command` subclasses resolved through the Artisan
  application, which is itself built on `illuminate/container` — in vanilla Laravel, container-resolved
  classes generally do support constructor injection. But since no Laravel Zero doc page states this
  explicitly for commands, this document does not claim it as confirmed; treat it as "expected to work
  given the underlying container, but validate empirically before relying on it" rather than a cited fact.
- **Provider registration is explicit, no auto-discovery.** Providers must be listed in
  `bootstrap/providers.php`. Quoted directly: "Laravel Zero does not use package auto-discovery, service
  providers offered by third-party packages must be registered in this file as well." (This applies to
  third-party providers; `AppServiceProvider` is registered by default in a fresh scaffold.)

## 3. Config Handling Conventions

Sources: [Configuration](https://laravel-zero.com/docs/configuration),
[Environment Variables](https://laravel-zero.com/docs/environment-variables)

- **Custom config files are auto-loaded**, same as Laravel: "Every file placed in that directory
  [`config/`] is registered as a configuration file automatically. For example, if you create a
  `config/movies.php` file, its values are immediately available via the `config` helper using the
  `movies` key." This confirms `config/claude-auth.php` is picked up automatically with no extra
  registration step, keys accessible as `config('claude-auth.home')`, `config('claude-auth.max_backups')`, etc.
- **`config()` helper / `Config` facade are the documented access patterns** — `Config::get('app.name')`
  or `config('app.name')` / `config('app.name', 'Application')` with dot syntax ("file.key"). The
  Configuration page does **not** mention or demonstrate injecting `Illuminate\Contracts\Config\Repository`
  as an alternative — that pattern is not documented for Laravel Zero at all (it exists in vanilla Laravel
  but isn't shown here). Given the docs only ever demonstrate the `config()` helper / `Config` facade,
  that is the idiomatic approach per Laravel Zero's own documentation.
- Runtime overrides use the same helper: `Config::set('app.name', 'Movie CLI')` or
  `config(['app.name' => 'Movie CLI'])`.
- **`env()` should only be called inside config files**, not application code: "you should only call the
  `env` helper from within your configuration files. Elsewhere in your application, read the value
  through the `config` helper instead." This matches how `config/claude-auth.php` is already written
  (`env('CLAUDE_AUTH_HOME', ...)` inside the config file, not scattered through commands/services).
- The Environment Variables page didn't add anything specific about overriding config in tests (see §4
  for that gap).

## 4. Testing Conventions with Pest

Source: [Testing](https://laravel-zero.com/docs/testing)

- **Directory structure**: "Every application ships with a `tests` directory containing a Pest test
  suite, a `Feature` suite that boots the full application, and a `Unit` suite for everything else."
  This matches the scaffold: `tests/Unit/ExampleTest.php` exists; a `tests/Feature/` directory would be
  created via `make:test` (Feature is the default location) or `make:test --unit` for Unit tests.
- **Base test case**: "Your test cases extend `Tests\TestCase`, which itself extends
  `LaravelZero\Framework\Testing\TestCase`." Confirmed in the scaffold —
  `tests/TestCase.php` does exactly this: `abstract class TestCase extends BaseTestCase {}`. This gives
  "the same testing experience you are used to in Laravel, adapted to the console."
- **Command testing** uses the familiar Laravel `artisan()` helper inside tests, with fluent assertions:
  `expectsOutput()`, `expectsQuestion()`, `expectsConfirmation()`, `assertExitCode()`, `assertSuccessful()`.
  Example from the docs:
  ```php
  $this->artisan('inspire')
      ->expectsOutput('Simplicity is the ultimate sophistication.')
      ->assertExitCode(0);
  ```
- Laravel Zero also tracks nested command execution: "Laravel Zero records every command executed through
  the `call` and `callSilently` methods," enabling `assertCommandCalled()` / `assertCommandNotCalled()`.
- **Config override per test — NOT documented on this page.** I searched the Testing page specifically
  for guidance on swapping a config value (e.g. `config(['claude-auth.home' => $fakeDir])`) for a single
  test, and it contains no such guidance. This is a gap in the Laravel Zero docs, not a confirmed
  Laravel-Zero-specific mechanism. However, per §3, the `Configuration` page confirms Laravel Zero uses
  the *exact same* `config()` helper / `Config` facade as Laravel (`Config::set(...)` / `config([...])`),
  and Laravel Zero's `Application` is described elsewhere in the docs as being built on standard Laravel
  components (Illuminate). Since Laravel Zero doesn't document a different config-repository mechanism,
  and the test base class is a normal Laravel-style `TestCase`, standard Laravel/Pest per-test config
  mutation (`config(['claude-auth.home' => $fakeDir]);` at the top of a test, or in a `beforeEach()`)
  is the reasonable expectation — but this is inferred from the general container/config mechanics
  documented elsewhere, not verified by an explicit Laravel Zero testing example. Treat it as needing
  empirical confirmation (write one test, run it) before depending on it broadly.

## 5. Generator Commands

Sources: [Commands](https://laravel-zero.com/docs/commands), live `php claude-auth list` output on the
scaffolded project.

- Laravel Zero ships exactly two `make:*` generators, confirmed both from the docs and from running
  `php claude-auth list` against the actual scaffold:
  - `make:command` — "Create a new command" (generates into `app/Commands`, customizable via
    `stub:publish` → `stubs/console.stub`, per the Commands doc page).
  - `make:test` — "Create a new test class" (Feature by default, `--unit` flag for Unit, per the Testing
    doc page).
- **There is no generator for a plain service class** (e.g. nothing like `make:service`, `make:class`, or
  a generic `make:model`/`make:class` analog). The full list of built-in commands on this scaffold is:
  `accounts, alias, clean, export, import, login, remove, switch, test, app:build, app:install,
  app:rename, make:command, make:test`. A `Registry` service class (for JSON registry file I/O, locking,
  atomic writes) will need to be created by hand under, e.g., `app/Services/`, with no scaffolding tool
  to lean on — this matches Laravel Zero's minimal footprint compared to full Laravel (no `make:class`,
  no Artisan `stub` for arbitrary classes).

---

## References

- https://laravel-zero.com/docs (index / navigation)
- https://laravel-zero.com/docs/commands
- https://laravel-zero.com/docs/service-providers
- https://laravel-zero.com/docs/configuration
- https://laravel-zero.com/docs/environment-variables
- https://laravel-zero.com/docs/testing

---

## Implications for claude-auth

- **Keep all 8 commands thin.** Per §1, Laravel Zero's own docs prescribe deferring to application
  services from `handle()`. Each of the 8 stub commands (`LoginCommand`, `SwitchCommand`,
  `RemoveCommand`, etc.) should call into a `Registry` service rather than doing JSON/file-locking work
  inline.
- **Bind `Registry` as a singleton in `AppServiceProvider::register()`**, following the documented
  pattern (`$this->app->singleton(Registry::class, fn ($app) => new Registry(...));`) — a singleton is
  correct here since the registry represents one logical JSON-file-backed resource per process, and this
  is the only binding style the docs actually demonstrate.
- **Inject `Registry` via `handle(Registry $registry)` parameters, not the constructor**, since that is
  the only pattern Laravel Zero's docs confirm works for commands. If constructor injection is wanted
  for readability, verify it empirically first (write a quick command + test) — the docs neither confirm
  nor rule it out.
- **Read config via the `config()` helper** (`config('claude-auth.home')`,
  `config('claude-auth.max_backups')`, etc.) inside `Registry` and commands — this is the only access
  pattern the docs demonstrate; do not introduce `Illuminate\Contracts\Config\Repository` injection since
  it's undocumented for Laravel Zero and would deviate from the framework's own examples.
  `config/claude-auth.php` is already auto-loaded correctly per §3 — no provider registration needed for
  the config file itself.
- **Write `Registry`/command tests as `Tests\Feature\*` Pest tests** extending the existing
  `Tests\TestCase`, using `$this->artisan('switch', [...])->assertExitCode(0)` etc. Override
  `config(['claude-auth.home' => $tmpDir])` at the top of each test (e.g. in `beforeEach()`) to point the
  Registry at a throwaway temp directory — this follows standard Laravel/Pest config mutation, since
  Laravel Zero doesn't document a different mechanism (§4). Confirm this pattern works with one throwaway
  test before building all file-mutating command tests on top of it.
- **Scaffold the `Registry` service by hand** under `app/Services/Registry.php` — there is no
  `make:service` or generic class generator in Laravel Zero (§5), only `make:command` and `make:test`.
