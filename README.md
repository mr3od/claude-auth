# claude-auth

[![Latest Version](https://img.shields.io/packagist/v/mr3od/claude-auth.svg)](https://packagist.org/packages/mr3od/claude-auth)
[![License](https://img.shields.io/packagist/l/mr3od/claude-auth.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/mr3od/claude-auth.svg)](https://packagist.org/packages/mr3od/claude-auth)

claude-auth stores and switches between multiple Claude Code account credentials. It keeps your
settings, history, and memory centralized across every account — no forking, no separate profiles.

## Platform support

**Linux only, for now.** Claude Code stores credentials differently per platform — see
[Anthropic's own docs](https://code.claude.com/docs/en/authentication#credential-management):

- **Linux**: a plain file at `~/.claude/.credentials.json`. This is what claude-auth manages.
- **macOS**: the encrypted system Keychain, not a file. claude-auth's file-swap design has
  nothing to manage there, so it refuses to run rather than silently doing nothing.
- **Windows**: a file, but at a different path (`%USERPROFILE%\.claude\.credentials.json`) that
  claude-auth doesn't yet resolve. Untested and not currently supported.

Running a live-file command (`switch`, `login`) on an unsupported platform fails with a clear
error instead of corrupting anything.

## Install

### Prebuilt binary (recommended)

Download the binary for your platform from the
[latest release](https://github.com/mr3od/claude-auth/releases/latest). It bundles its own PHP
runtime, so you don't need PHP installed.

```bash
# Linux (x86_64)
curl -L -o claude-auth https://github.com/mr3od/claude-auth/releases/latest/download/claude-auth-linux-x64
# Linux (arm64)
curl -L -o claude-auth https://github.com/mr3od/claude-auth/releases/latest/download/claude-auth-linux-arm64

chmod +x claude-auth
sudo mv claude-auth /usr/local/bin/claude-auth
```

### Composer

If you already have PHP 8.3+ and Composer, install it as a global package instead:

```bash
composer global require mr3od/claude-auth
```

Make sure Composer's global `bin` directory is on your `PATH` (`composer global config bin-dir
--absolute`).

## Usage

```bash
claude-auth login                 # Log in and store the result as a new account
claude-auth accounts              # List stored accounts, mark the active one
claude-auth switch work           # Switch to the account matching "work"
claude-auth switch -              # Switch back to the previously active account
```

## Commands

| Command | Description |
|---|---|
| `accounts [--json]` | List stored accounts and mark which one is active. |
| `login [--alias=]` | Run Claude Code login in an isolated scratch config directory, then store the result as a new account. |
| `switch [<query>] [--json]` | Switch the active account. `<query>` can be a row number, an alias, or an email substring. Use `switch -` to switch back to the previous account. |
| `remove <selectors...> [--all] [--force] [--json]` | Remove one or more stored accounts. Prompts for confirmation unless you pass `--force`. |
| `alias set <selector> <alias>` | Set a display alias for an account. |
| `alias clear <selector>` | Clear a display alias for an account. |
| `import <path> [--alias=]` | Import an existing snapshot file, or a directory of them, as new accounts. |
| `import --purge` | Rebuild the registry from whatever snapshot files already exist on disk. |
| `export [<dir>]` | Copy every stored account's snapshot file to a directory. Defaults to `~/.claude-auth/backups`. |
| `clean` | Prune old backups and delete snapshot files no longer tracked by the registry. |

Run `claude-auth <command> --help` for full option details.

## Design

- `~/.claude-auth/registry.json` stores this tool's own index: account identities, aliases, and
  timestamps. It never stores raw credentials.
- `~/.claude-auth/accounts/<key>.snapshot.json` stores one full snapshot per account: the live
  credentials file's contents, plus the `oauthAccount` block from `~/.claude.json`.
- `~/.claude/.credentials.json` and `~/.claude.json` are the live files Claude Code itself reads.
  `switch` replaces `.credentials.json` entirely and merges only the `oauthAccount` key into
  `~/.claude.json`, leaving every other key — history, projects, settings — untouched. Switching
  preserves the live file's existing permissions and requires restarting any running `claude`
  session to pick up the change.
- Before `switch` overwrites a live file, claude-auth backs it up to `~/.claude-auth/backups/`,
  unconditionally, every time. To roll back by hand, copy the newest matching backup file back
  over the live path. `login` never touches the live files — it runs in an isolated scratch
  config directory and only stores the result as a new account; run `switch` to make it active.
- claude-auth never calls an undocumented Anthropic endpoint with a raw token.

The design follows primary-source research into
[`Loongphy/codex-auth`](https://github.com/Loongphy/codex-auth), a similar tool for the OpenAI
Codex CLI. See [`docs/codex-auth-research.md`](docs/codex-auth-research.md) for the full findings,
and the "Applicability to a Claude Code Equivalent" section for the mapping this project follows.

## Contributing

Bug reports and pull requests are welcome. Before opening a PR, run the test suite:

```bash
composer install
vendor/bin/pest
```

See [`AGENTS.md`](AGENTS.md) for this project's coding conventions.

## License

claude-auth is open-source software licensed under the [MIT license](LICENSE).
