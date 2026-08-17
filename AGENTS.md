# AGENTS.md

Instructions for AI coding agents working on `claude-auth`, a Laravel Zero (PHP 8.3+) CLI that
stores and switches between multiple Claude Code account credentials while keeping settings,
history, and memory centralized across accounts.

## Setup commands

- Install dependencies: `composer install`
- Run the CLI locally: `php claude-auth <command>`
- Run the full test suite: `vendor/bin/pest`
- Run one test file: `vendor/bin/pest tests/Feature/Registry/ActivateTest.php`

## Code style

- Commands (`app/Commands/*.php`) stay thin. They receive `Registry` (and, for `login`,
  `ScratchLoginRunner`) via `handle()` **method injection**, never constructor injection —
  Laravel Zero only documents the former for commands. All real logic lives in `Registry`.
- Read config only via the `config()` helper (`config('claude-auth.home')`, etc.). Don't inject
  `Illuminate\Contracts\Config\Repository` — undocumented for Laravel Zero.
- `Registry` is a deep module: a small public interface (`listAccounts`, `activate`, `remove`,
  `importPath`, ...) hiding file I/O, atomic writes, backup pruning, and selector resolution.
  Internal collaborators (`SelectorResolver`, `SnapshotCodec`, `AtomicJsonStore`) are not part of
  its public seam.
- Value objects crossing the `Registry` ↔ command boundary live in `app/DataTransferObjects/` as
  `final readonly class`es.

## Live-file safety (read before touching credential/auth files)

- **Linux only, by design.** Claude Code stores credentials in a plain file only on Linux. macOS
  uses the encrypted Keychain (no file to manage at all — `CLAUDE_CONFIG_DIR` relocation is only
  documented "on Linux or Windows," so it can't be trusted to isolate a login on macOS either).
  Windows uses a different, currently-unresolved path (`%USERPROFILE%\.claude\.credentials.json`).
  `Registry::activate()`/`captureCurrentAccount()` and `ScratchLoginRunner::run()` all guard on
  `PHP_OS_FAMILY` and throw `UnsupportedPlatformException` off Linux — don't remove that guard
  without actually implementing Keychain/Windows-path support first.
- `~/.claude/.credentials.json` and `~/.claude.json` are files Claude Code itself owns and reads
  every session. Any code path that overwrites either one must back it up to
  `~/.claude-auth/backups/` **unconditionally, before writing** — not "if changed." This is the
  tool's only rollback mechanism; there is no separate `restore` command.
- Never decode a foreign JSON file (one claude-auth doesn't own the schema of) with
  `json_decode(..., true)` if any part of it will be written back. PHP can't distinguish an empty
  JSON object (`{}`) from an empty array (`[]`) once decoded to an associative array, so a
  decode-then-encode round trip silently corrupts any `"field": {}` into `"field": []`. Use
  `AtomicJsonStore::readJsonPreservingTypes()` for any such read instead. (This is a real bug that
  shipped once and was fixed — see `docs/*` commit history around `activate()`.)
- Before trusting a change to a file-mutating command against real credentials, verify it in a
  throwaway sandbox first: copy `~/.claude-auth` and the live files into a temp dir, run the
  command there with `CLAUDE_AUTH_HOME`/`CLAUDE_CREDENTIALS_FILE`/`CLAUDE_JSON_FILE` env overrides,
  and diff the result. Passing unit tests is necessary but not sufficient — hand-authored test
  fixtures can miss structural properties real data has.
- **Never print, `cat`, or dump the full contents of a credentials/token file to a terminal or
  conversation**, even a throwaway copy of one — filter output to only the specific field(s) under
  test. A copy of real data still contains the real secret values.

## Testing instructions

- Tests live under `tests/Feature/`, extend `Tests\TestCase`, and use Pest's functional syntax.
- Any test touching `Registry` or a command must call `$this->useFakeClaudeHome()` (from
  `tests/Feature/Concerns/UsesFakeClaudeHome.php`) in `beforeEach()`, which redirects
  `claude-auth.home`/`claude_credentials_file`/`claude_json_file` config to an isolated temp
  directory. Never let a test touch the real `~/.claude` or `~/.claude-auth` paths.
- `$this->table()` output in a command is not reliably visible to `expectsOutputToContain()`
  (Laravel Zero buffers per `write()` call). Use `Artisan::call()` + `Artisan::output()` instead
  for asserting on table/JSON command output.
- Follow red → green: write one failing test at the `Registry`/command seam, then the minimal code
  to pass it. Don't write tests in bulk after the fact.

## Commit conventions

Every commit follows Tim Pope's and cbea.ms's conventions:
[A Note About Git Commit Messages](https://tbaggery.com/2008/04/19/a-note-about-git-commit-messages.html),
[How to Write a Git Commit Message](https://cbea.ms/git-commit/).

- Subject line: imperative mood, capitalized, no trailing period, ≤50 characters.
- Blank line, then a body wrapped near 72 characters explaining what and why, not a restatement of
  the diff.
- One concern per commit.
- **Never reference the conversation that produced the change** (no "per your answer to X",
  "as discussed", etc.) — describe the resulting behavior directly.

## Documentation style

User-facing prose (`README.md`, and any new user-facing doc) follows **ASD-STE100 (Simplified
Technical English)** combined with the **Google developer documentation style guide**: one idea
per sentence, active/imperative voice, second person, consistent terminology, code font for
commands/paths/flags. The research docs under `docs/*-research.md` are reference artifacts, not
user-facing — they're exempt.
