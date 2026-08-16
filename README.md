# claude-auth

claude-auth stores and switches between multiple Claude Code account credentials. It keeps your
settings, history, and memory centralized across every account.

The design follows primary-source research into
[`Loongphy/codex-auth`](https://github.com/Loongphy/codex-auth), a similar tool for the OpenAI
Codex CLI. See [`docs/codex-auth-research.md`](docs/codex-auth-research.md) for the full findings,
and the "Applicability to a Claude Code Equivalent" section for the mapping this project follows.

## Status

All 8 commands work:

- `accounts` — Lists stored accounts and marks which one is active. Add `--json` for
  machine-readable output.
- `login` — Runs Claude Code login in an isolated scratch config directory, then stores the
  result as a new account.
- `switch <query>` — Switches the active account. `<query>` can be a row number, an alias, or an
  email substring. Use `switch -` to switch back to the previous account.
- `remove <selectors...>` — Removes one or more stored accounts. Prompts for confirmation unless
  you pass `--force`. Use `--all` to remove every account.
- `alias set <selector> <alias>` / `alias clear <selector>` — Sets or clears a display alias for
  an account.
- `import <path>` — Imports an existing snapshot file, or a directory of them, as new accounts.
  Use `--purge` to rebuild the registry from whatever snapshot files already exist on disk.
- `export [<dir>]` — Copies every stored account's snapshot file to a directory. Defaults to
  `~/.claude-auth/backups`.
- `clean` — Prunes old backups and deletes snapshot files no longer tracked by the registry.

Run `php claude-auth <command> --help` for full option details.

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
- Before `switch` or `login` overwrites a live file, claude-auth backs it up to
  `~/.claude-auth/backups/`, unconditionally, every time. To roll back by hand, copy the newest
  matching backup file back over the live path.
- claude-auth never calls an undocumented Anthropic endpoint with a raw token.

## Development

```bash
composer install
php claude-auth accounts
vendor/bin/pest
```
