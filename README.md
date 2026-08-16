# claude-auth

A CLI tool to store and switch between multiple Claude Code account credentials,
while keeping settings, history, and memory centralized across accounts.

Design is based on primary-source research into
[`Loongphy/codex-auth`](https://github.com/Loongphy/codex-auth) — see
[`docs/codex-auth-research.md`](docs/codex-auth-research.md) for the full findings
and the "Applicability to a Claude Code Equivalent" mapping this project follows.

## Status

Scaffolded, commands stubbed, not yet implemented:

- `accounts` — list stored accounts and which one is active
- `login` — run Claude Code login in an isolated scratch config dir, store the result
- `switch <query>` — swap the active account's credentials
- `remove <selectors...>` — remove stored accounts
- `alias set|clear` — label an account
- `import` / `export` — adopt or back up credential snapshots
- `clean` — prune old backups and orphaned snapshots

## Design

- `~/.claude-auth/registry.json` — this tool's own index (aliases, ids, timestamps).
  Never stores raw credentials.
- `~/.claude-auth/accounts/<key>.credentials.json` — one full snapshot per account.
- `~/.claude/.credentials.json` and `~/.claude.json` (`oauthAccount` block) are the
  live files Claude Code itself reads — `switch` copies a snapshot over them,
  preserving existing file permissions, and requires restarting any running
  `claude` session to pick up the change.
- No undocumented Anthropic endpoints are ever called with a raw token.

## Development

```bash
composer install
php claude-auth list
```
