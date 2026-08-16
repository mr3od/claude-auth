# codex-auth Primary-Source Research

Research target: [`Loongphy/codex-auth`](https://github.com/Loongphy/codex-auth) (npm package
`@loongphy/codex-auth`), commit state as of `main` on 2026-08-16. All claims below are sourced
directly from the repository's files (fetched via `gh api repos/Loongphy/codex-auth/contents/<path>`
and `raw.githubusercontent.com/Loongphy/codex-auth/main/<path>`), not from the README summary or
secondhand description. Every subsection cites `path@main` plus the GitHub URL.

This document is research only. It contains no implementation code for a Claude Code equivalent.

---

## 1. Distribution & Build

- It's a Zig binary (`src/main.zig`, `build.zig`, `build.zig.zon`) wrapped for npm distribution.
  `package.json@main` (https://github.com/Loongphy/codex-auth/blob/main/package.json) declares
  `bin: { "codex-auth": "bin/codex-auth.js" }` and ships the real binaries as per-platform
  `optionalDependencies`: `@loongphy/codex-auth-{linux-x64,linux-arm64,darwin-x64,darwin-arm64,
  win32-x64,win32-arm64}`. This is the standard "npm installs a small JS shim that execs a
  platform-specific native binary" pattern (same shape used by esbuild, swc, etc.).
- Package name/version at research time: `@loongphy/codex-auth@0.3.0-alpha.11`.

## 2. Runtime State Layout

Source: `docs/implement.md@main` (https://github.com/Loongphy/codex-auth/blob/main/docs/implement.md),
confirmed against `src/registry/common.zig@main`.

Codex home resolution order (`src/registry/common.zig::resolveCodexHome`):
1. `CODEX_HOME` env var, if set to a non-empty existing directory
2. `HOME/.codex`
3. `USERPROFILE/.codex` (Windows)

Managed files under `<codex_home>`:

```
<codex_home>/auth.json                                          # the REAL Codex CLI auth file (untouched format)
<codex_home>/accounts/registry.json                              # codex-auth's own index/metadata
<codex_home>/accounts/<account file key>.auth.json                # one full copy of auth.json per account ("snapshot")
<codex_home>/accounts/auth.json.bak.YYYYMMDD-hhmmss[.N]           # timestamped backup of auth.json before overwrite
<codex_home>/accounts/registry.json.bak.YYYYMMDD-hhmmss[.N]       # timestamped backup of registry.json before overwrite
<codex_home>/sessions/...                                        # Codex CLI's own rollout/session files (read-only input)
```

Key architectural point: **codex-auth never invents its own auth file format.** It stores full,
byte-for-byte copies of whatever `codex login` itself writes to `auth.json`, one copy per known
account, keyed by a derived `account_key`. `registry.json` is pure metadata/index — pointers,
aliases, cached usage stats — not the credential material itself.

## 3. Registry File Format (`registry.json`)

### 3.1 On-disk shape (schema 4, current)

Source: `src/registry/storage_write.zig@main` (`RegistryOut` struct — this is literally what gets
serialized) — https://github.com/Loongphy/codex-auth/blob/main/src/registry/storage_write.zig,
and `src/registry/common.zig@main` (`Registry`, `AccountRecord` structs) —
https://github.com/Loongphy/codex-auth/blob/main/src/registry/common.zig.

```
{
  "schema_version": 4,
  "active_account_key": "chatgptUserId::chatgptAccountId" | null,
  "previous_active_account_key": "..." | null,
  "active_account_activated_at_ms": <i64> | null,
  "interval_seconds": <u16>,           // live-refresh poll interval, config.md
  "accounts": [ <AccountRecord>, ... ]
}
```

`AccountRecord` fields (`src/registry/common.zig` lines ~95-108):

```zig
pub const AccountRecord = struct {
    account_key: []u8,          // stable identity, see 3.2
    chatgpt_account_id: []u8,
    chatgpt_user_id: []u8,
    email: []u8,                 // normalized lowercase, display/grouping only — NOT identity
    alias: []u8,                 // user-settable label, "" = none
    account_name: ?[]u8,         // workspace/team display name from account-metadata API
    plan: ?PlanType,             // free/go/plus/prolite/pro/business/enterprise/edu/unknown
    auth_mode: ?AuthMode,        // chatgpt | apikey
    created_at: i64,
    last_used_at: ?i64,
    last_usage: ?RateLimitSnapshot,   // cached usage/rate-limit data
    last_usage_at: ?i64,
    last_local_rollout: ?RolloutSignature,  // dedupe marker for local session-file scanning
};
```

No field stores a filesystem path — the snapshot file location is *derived* from `account_key`
(see 3.3), not stored explicitly. This means the registry can't drift from the snapshot layout by
construction.

### 3.2 Account identity (`account_key`)

Source: `docs/implement.md@main` "Account Identity" section, confirmed against
`src/registry/account_ops.zig@main::accountFromAuth` (lines 578-612) —
https://github.com/Loongphy/codex-auth/blob/main/src/registry/account_ops.zig.

For ChatGPT OAuth accounts:
- `chatgpt_account_id` selection order: (1) non-empty `tokens.account_id`, (2) JWT claim
  `https://api.openai.com/auth.chatgpt_account_id`, (3) JWT claim
  `https://api.openai.com/auth.organizations[].id` (prefers `is_default = true`, else first
  non-empty). This third case yields an `org-...` id rather than a legacy account id.
- `chatgpt_user_id` comes from JWT claims, falling back to `user_id`.
- **`account_key` = `record_key` = `chatgpt_user_id + "::" + chatgpt_account_id`.**
- Email is normalized lowercase and used only for display/grouping, never as identity — this is
  deliberate, since the same email can have multiple workspace/org contexts (Business, Enterprise,
  Edu) that must be tracked as distinct accounts.

For OpenAI API-key auth (`OPENAI_API_KEY` present in `auth.json`):
- Verified via `GET https://api.openai.com/v1/me`.
- `account_key = "apikey::" + me.id + "::" + sha256(OPENAI_API_KEY)`.
- The raw API key is stored **only** in the managed snapshot file, never in `registry.json`,
  never in a filename, never in a display label (a fingerprint/label like `API key <fingerprint>`
  is shown instead).

### 3.3 Snapshot filenames

Source: `src/registry/common.zig::accountFileKey/accountSnapshotFileName/accountAuthPath`.

```
accounts/<account_key or base64url(account_key) if unsafe>.auth.json
```

`account_key` is used directly as the filename stem when it only contains
`[a-zA-Z0-9._-]`; otherwise it's base64url-encoded (`std.base64.url_safe_no_pad`) via
`encodedFileKey`. This keeps snapshot filenames human-legible in the common case while staying
filesystem-safe in all cases.

### 3.4 Schema migration policy

Source: `docs/schema-migration.md@main` —
https://github.com/Loongphy/codex-auth/blob/main/docs/schema-migration.md, implemented in
`src/registry/storage.zig::loadRegistry` (schema detection + dispatch,
https://github.com/Loongphy/codex-auth/blob/main/src/registry/storage.zig).

- `schema_version` is a pure on-disk migration gate, decoupled from the CLI release version
  (`src/version.zig`).
- History: `version=2` (email-keyed snapshots, `active_email`) → `schema_version=3`
  (record-key-based, `active_account_key`, per-account `account_key`/`chatgpt_account_id`/
  `chatgpt_user_id`) → `schema_version=4` (adds `interval_seconds` top-level, adds
  `previous_active_account_key`, normalizes plan values to final product semantics e.g. legacy
  `team`→`business`, legacy `business`→`enterprise`).
- Loading an older supported schema migrates it in memory, then **rewrites the file in the current
  format immediately** (`loadRegistry` sets `needs_rewrite` and calls `saveRegistry`).
- Loading a *newer* schema than the binary understands fails hard with `UnsupportedRegistryVersion`
  and refuses to write — this is the safety rail against a newer codex-auth's registry being
  corrupted by an older binary.
- Rule of thumb documented for schema bumps: bump `schema_version` for any change to persisted
  field shape/semantics/identity keys/filename conventions; do NOT bump for CLI output changes,
  pure in-memory logic, or docs. `import --purge` is called out as the deliberate manual recovery
  path when a registry is corrupted or too old — not the normal migration path.

## 4. Command Mechanics

### 4.1 `login`

Source: `docs/commands/login.md@main`, `src/workflows/login.zig@main` —
https://github.com/Loongphy/codex-auth/blob/main/src/workflows/login.zig.

Mechanically clever bit: `handleLogin` does **not** run `codex login` against the real
`CODEX_HOME`. It creates a scratch directory
`<codex_home>/accounts/login-<unix_ms_timestamp>/`, sets that as the login's `CODEX_HOME`
(`registry.ensurePrivateDir(login_codex_home)`), runs `codex login` (or `codex login
--device-auth`) with that scratch home via `cli.login.runCodexLogin(opts, login_codex_home)`,
reads the resulting `auth.json` out of the scratch dir, copies it into the real
`<codex_home>/auth.json`, and deletes the scratch directory (`defer ... deleteTree`). This means
an in-progress `codex login` never partially clobbers the real live `auth.json` if it fails
partway.

After copying: parses the new `auth.json` (`auth.parseAuthInfo`), derives `record_key`, copies the
same bytes to the managed snapshot path, upserts an `AccountRecord`, calls `setActiveAccountKey`,
triggers an immediate account-name refresh (`refreshAccountNamesAfterLogin`), and saves the
registry. No alias is set on login — `docs/commands/login.md` explicitly says use
`import <file> --alias <alias>` afterward if one is wanted.

### 4.2 `switch`

Source: `docs/commands/switch.md@main`, `src/workflows/switch.zig@main`
(https://github.com/Loongphy/codex-auth/blob/main/src/workflows/switch.zig), and the actual file
mutation in `src/registry/account_ops.zig::activateAccountByKey` (lines 524-540,
https://github.com/Loongphy/codex-auth/blob/main/src/registry/account_ops.zig).

The actual swap, verbatim from `activateAccountByKey`:

```zig
pub fn activateAccountByKey(allocator, codex_home, reg, account_key) !void {
    _ = findAccountIndexByAccountKey(reg, account_key) orelse return error.AccountNotFound;
    const src = try resolveStrictAccountAuthPath(allocator, codex_home, account_key); // accounts/<key>.auth.json
    const dest = try activeAuthPath(allocator, codex_home);                            // auth.json
    try backupAuthIfChanged(allocator, codex_home, dest, src);   // only if bytes differ
    try replaceFilePreservingPermissions(src, dest);              // COPY, preserving dest's existing file mode
    try setActiveAccountKey(allocator, reg, account_key);
}
```

**Swap mechanism = plain file copy, not a symlink.** `replaceFilePreservingPermissions`
(`src/registry/common.zig`) stats the existing `dest` for its current permission bits, then does a
regular `Dir.copyFile` with those permissions — deliberately so `auth.json`'s mode is never forced
back to `0600` if the user/Codex CLI had it at some other mode (see §6, "Live auth.json behavior"
in `docs/permissions.md`).

Ordered effects on a successful switch (`docs/commands/switch.md` "Switch Effects", matching the
code): (1) back up current `auth.json` if contents would change, (2) copy selected snapshot over
`auth.json`, (3) update `active_account_key` in `registry.json`, (4) record
`previous_active_account_key` (enables `switch -` / bare `codex-auth -`), (5) print
`Switched to <label>`.

Selector resolution (`src/workflows/query.zig::resolveSwitchQueryLocally`, lines 19-41): first
try exact `account_key` match, then displayed row number (`findAccountIndexByDisplayNumber`, which
literally rebuilds the same display-row ordering used by `list`), then case-insensitive
substring match against email/alias/account_name (`findMatchingAccounts`). Zero matches → error;
one match → direct switch; multiple → falls back to the interactive picker (or, under `--json`,
returns an `ambiguous_query` error with all candidates — never guesses).

`switch --live` keeps the picker open, patches the in-memory display immediately after a
successful switch without a full disk reload, and lets a scheduled background refresh reconcile
stale overlays later (`docs/api.md` "Usage Refresh Rules" — this is documented, not just
inferred).

### 4.3 `remove`

Source: `docs/commands/remove.md@main`, `src/workflows/remove.zig@main`,
`src/registry/account_ops.zig::removeAccounts` (lines 292-390).

Selector rules mirror `switch` but add exact `account_key` fragment matching
(`findMatchingAccountsForRemove` in `src/workflows/query.zig`, lines 61-77) since removal is
higher-stakes than switching. JSON-mode removal resolves **every** selector before mutating
anything — if any selector is ambiguous or not found, nothing is deleted and one error document
reports all resolutions (`selector_resolution_failed`). This atomicity-of-intent pattern (resolve
everything, then mutate) is a good one to reuse regardless of language.

Active-account reconciliation when the removed account was active: promote another account if
possible; rewrite `auth.json` from the promoted account "when safe"; delete `auth.json` only if no
accounts remain AND the current `auth.json` matches a tracked (now-removed) account; leave
malformed/unsyncable `auth.json` untouched rather than guess. `clean` docs and the JSON contract
both document a `state_uncertain` outcome for the case where a filesystem failure happens *after*
mutation has started — callers are told to re-run `list --json` rather than assume anything about
partial state.

### 4.4 `alias`

Source: `docs/commands/alias.md@main`, `src/workflows/alias.zig@main`.

Pure metadata edit — `alias set/clear` never touches `auth.json` or any snapshot file, only
`AccountRecord.alias` in `registry.json`. Validation (`validateAlias` in `alias.zig`): empty
rejected, all-digit rejected (collides with row-number selectors — `parseDisplayNumber` reused
directly as the validator), control characters rejected, case-insensitive duplicate check against
all other accounts.

### 4.5 `import` / `export`

Source: `docs/commands/import.md@main`, `docs/commands/export.md@main`,
`src/registry/import.zig@main` (818 lines equiv., handles CPA conversion and purge),
`src/registry/export.zig@main`.

- Standard `import <path>`: single file imports one auth/config file; a directory imports its
  direct (non-recursive) `.json` children. `--alias` only applies to single-file import.
- `--cpa`: converts flat [CLIProxyAPI](https://github.com/router-for-me/CLIProxyAPI) token JSON
  into codex-auth's snapshot format **in memory** before writing — i.e., a foreign auth format is
  normalized to the local shape at the import boundary, not carried through as a special case
  deeper in the system.
- `--purge`: full registry rebuild from whatever `.auth.json` snapshots already exist on disk
  (plus a best-effort import of the current live `auth.json` last). Explicitly the documented
  recovery path when the registry index and the on-disk auth files have drifted apart — "does not
  delete old snapshot files or backups."
- `export [<dir>]`: writes `*.auth.json` snapshot copies to a directory (default
  `CODEX_HOME/accounts/backup`); `export --cpa` reverses the CPA conversion for portability back
  to CLIProxyAPI-style tooling. Round-trips with `import <dir>` / `import --cpa <dir>`.

### 4.6 `clean`

Source: `docs/commands/clean.md@main`, `src/registry/clean.zig@main`.

Two independent behaviors:
1. **Backup pruning** (`pruneBackups`): keeps only the newest 5 (`max_backups = 5` in
   `common.zig`) backups per base name (`auth.json.bak.*`, `registry.json.bak.*`), sorted by
   mtime descending, deleting the rest. Runs even if `registry.json` is missing.
2. **Stale snapshot cleanup** (`cleanAccountsBackupsWithLoader`): whitelist-based — loads the
   registry, computes the expected snapshot filename for every still-tracked account
   (`isAllowedCurrentSnapshot`), and deletes any file/dir under `accounts/` that isn't
   `registry.json`, `backups`, or a still-referenced snapshot (`isAllowedAccountsEntry`). Skipped
   entirely if `registry.json` is missing, specifically so orphaned snapshots remain available for
   `import --purge` recovery.
3. `clean background` is a one-time migration cleanup that removes legacy OS background-service
   registrations (systemd user units, macOS LaunchAgent, Windows scheduled task) left behind by
   older versions that had an `auto_switch` feature — confirms the project *used to* have a
   background auto-switch daemon and deliberately removed it (schema-migration.md also mentions
   "removed `auto_switch` blocks are omitted on rewrite").

### 4.7 `config`

Source: `docs/commands/config.md@main`. Only one knob: `config live --interval <seconds>`
(range 5–3600), stored as top-level `interval_seconds` in `registry.json`. Notably: "Older
`registry.json` files may contain an `api` object; current builds ignore it and omit it on the
next registry save" — i.e. there used to be a way to disable the API entirely via config, and it
was removed in favor of always-per-command `--api`/`--skip-api` flags.

### 4.8 Atomic writes & backups (cross-cutting)

Source: `src/registry/storage_write.zig@main::saveRegistry/writeRegistryFileAtomic` (lines 17-101,
https://github.com/Loongphy/codex-auth/blob/main/src/registry/storage_write.zig).

- `saveRegistry` first serializes the candidate bytes, diffs them against the current on-disk file
  (`fileEqualsBytes`) and **no-ops entirely if unchanged** (still re-hardens permissions though).
- If changed: creates a timestamped backup first (`backupRegistryIfChanged`, prunes to 5), then
  writes via `writeRegistryFileAtomic`.
- On non-Windows: `Dir.createFileAtomic(..., .{ .replace = true, .permissions = 0o600 })` — write
  to a temp file, then atomic rename over the destination. Standard POSIX atomic-write pattern.
- On Windows (no atomic rename semantics for open files the same way): `writeRegistryFileReplace`
  manually writes to `<path>.tmp.<ns>`, `fsync`s, renames the *existing* file to
  `<path>.bak.<ns>` (if present), renames temp → real path, then deletes the `.bak.<ns>` — with an
  `errdefer` that restores the backup and deletes the temp file if anything fails midway. This is
  a hand-rolled "atomic-ish" swap for a platform without atomic replace.

## 5. Auth File Format Codex-Auth Wraps (`auth.json`)

Source: `src/auth/auth.zig@main` (lines 1-140+) —
https://github.com/Loongphy/codex-auth/blob/main/src/auth/auth.zig.

Real Codex CLI `auth.json` shape that codex-auth parses (not invented — this is what
`codex login` itself writes):

```json
{
  "auth_mode": "chatgpt",
  "OPENAI_API_KEY": null,
  "tokens": {
    "id_token": "<JWT>",
    "access_token": "<opaque bearer token>",
    "refresh_token": "<opaque>",
    "account_id": "<chatgpt account id, may be empty>"
  },
  "last_refresh": "<ISO timestamp>"
}
```

If `OPENAI_API_KEY` is a non-empty string, the file is treated as API-key auth instead
(`auth_mode = .apikey`) and no ChatGPT token fields are required. For ChatGPT auth, the code
decodes the `id_token` JWT payload (base64) to pull `email`, `chatgpt_user_id` (or `user_id`
fallback), `chatgpt_account_id` (falling back through JWT claim →
`organizations[].id`), matching exactly the identity-resolution order documented in §3.2.

`docs/implement.md` "Auth Parsing": if required identity fields are missing/mismatched,
import/login fails outright; but *foreground sync* of an already-populated registry against a
momentarily-broken `auth.json` just skips that sync and keeps existing registry state — the
registry is never destructively rebuilt just because the live file is transiently unparseable.

## 6. File Permissions

Source: `docs/permissions.md@main` —
https://github.com/Loongphy/codex-auth/blob/main/docs/permissions.md.

- `<codex_home>/accounts/` directory is hardened to `0700` on Unix. `<codex_home>` itself is
  **not** forced to any particular mode.
- Managed sensitive files (`registry.json`, every `*.auth.json` snapshot, every `.bak.*` file) are
  created at `0600` immediately (destination created with the target mode at copy time, not
  copy-then-chmod) and kept private on every rewrite/sync.
- The **live** `<codex_home>/auth.json` is deliberately treated differently: `login` leaves it at
  whatever mode `codex login` itself produced; foreground sync never re-hardens it; a `switch`-
  style replace *preserves the file's pre-existing mode* rather than forcing `0600` (see
  `replaceFilePreservingPermissions` in §4.2) — the stated rationale is not clobbering
  Codex CLI's own expectations about that file's permissions. It only ends up private "by
  accident" when it has to be recreated from a snapshot (which is already `0600`).
- Windows: POSIX mode bits are skipped entirely (ACL-based inheritance instead, implicit).

## 7. Outbound API Refresh (`--api` mode) — documented for completeness, NOT to be replicated

Source: `docs/api.md@main` — https://github.com/Loongphy/codex-auth/blob/main/docs/api.md.

- All refresh requests are issued by shelling out to `curl` (resolved from `PATH`), not an
  in-process HTTP client — `codex-auth` "does not translate platform proxy settings"; curl
  inherits parent env and does its own proxying.
- Two undocumented ChatGPT endpoints are called directly with the user's raw ChatGPT
  `access_token`:
  - `GET https://chatgpt.com/backend-api/wham/usage`
  - `GET https://chatgpt.com/backend-api/accounts`
  - both with `Authorization: Bearer <tokens.access_token>` and `ChatGPT-Account-Id: <id>` headers.
- This is the exact mechanism referenced in the task background as the ToS-risk pattern. It is
  recorded here only so the design doc has a precise technical description of what **not** to
  build: sending a live OAuth access token to an undocumented first-party web-app backend API
  (as opposed to the documented/public OpenAI API) in order to poll usage/plan metadata.
  Confirmed default-on for `list`/interactive `switch` unless `--skip-api` is passed.

## 8. `app` Command — the "live switch without restart" trick

Source: `docs/commands/app.md@main` —
https://github.com/Loongphy/codex-auth/blob/main/docs/commands/app.md, and
`src/workflows/app.zig@main` (1298 lines) —
https://github.com/Loongphy/codex-auth/blob/main/src/workflows/app.zig.

### 8.1 What it actually does

`codex-auth app` does **not** implement in-process hot credential swapping itself. It launches the
official Codex desktop app (Electron-style GUI) as a **child process with two per-process
environment variable overrides injected only for that spawn**:

- `CODEX_HOME=<path>` — which account-registry/config directory the launched app's embedded CLI
  should read.
- `CODEX_CLI_PATH=<path to a CLI binary>` — which CLI executable the app spawns internally for
  each Codex turn.

Concretely, on macOS (`launchMac`, `src/workflows/app.zig` lines 697-735): builds an `/usr/bin/open
--env CODEX_HOME=... --env CODEX_CLI_PATH=... -b <bundle id>` invocation — i.e. it uses the OS
app-launcher's per-launch environment injection, not a global/session env var, not editing a
plist, not editing the shell profile. On Windows (`launchWindowsViaPowerShell`, near line 1225):
sets `$env:CODEX_CLI_PATH=...` in a PowerShell session immediately before invoking the AppX/MSIX
launch (`shell:AppsFolder\<AUMID>`). Both are scoped to a single process launch — no lingering
global state.

### 8.2 Where the actual hot-swap logic lives

Critically: `CODEX_CLI_PATH`, when omitted, is **not** resolved to the real, official
`OpenAI.Codex`/`com.openai.codex` CLI. `handleApp`/`resolveCliPath`
(`src/workflows/app.zig` lines 110-127, 615-644) instead fetches release metadata from
`https://api.github.com/repos/Loongphy/codext/releases/latest`, downloads (if the cached version
differs) a build of **[`Loongphy/codext`](https://github.com/Loongphy/codext)** — the author's own
*fork* of the Codex CLI — caches it at
`$CODEX_HOME/accounts/codext-cli/codex-<platform>[.version]`, and points `CODEX_CLI_PATH` at that
fork instead of the stock Codex CLI.

So the actual "no app restart needed" behavior is implemented **inside the forked `codext`
binary**, a separate repository not covered by this research pass — `codex-auth`'s own
contribution is only: (a) the environment-variable injection trick to redirect which CLI binary
and which config home the officially-signed desktop app process uses, and (b) fetching/caching
that fork's release binary. This matches and confirms the already-known caveat: live account
switching without an app restart requires the patched `codext` CLI; the stock Codex App still
needs a restart after an auth-file swap.

### 8.3 Platform mechanics detail

- `--platform win|wsl|mac` further controls, on Windows, whether the app is told (via a
  `[desktop] runCodexInWindowsSubsystemForLinux` boolean written into `$CODEX_HOME/config.toml`,
  see `updateDesktopWslSettingTomlAlloc`) to run its agent natively or inside WSL — this changes
  which platform's cached `codext` binary gets selected/downloaded (`codex-<platform>`).
  Auto-detection reads that same TOML key when `--platform` is omitted.
  Windows native launches resolve the AppX/MSIX package via a hand-rolled PowerShell
  helper-function block embedded as a string constant (`windows_app_id_resolver_script`,
  lines 20-43) that looks up `Get-AppxPackage`/`Get-AppxPackageManifest` to resolve an app ID or
  full AUMID to `shell:AppsFolder\<AUMID>`.
- If the app is already running, `app` detects that and exits before doing any download/launch
  work (`isCodexAppRunning` check before `resolveCliPath`) — avoids clobbering a live session.
- `app` validates all configured options up front and reports every issue at once (not fail-fast
  on the first bad flag) — see the `ValidationIssue` accumulation pattern in
  `validateConfiguredOptions`/`appendConfiguredAppIdIssue`/`appendConfiguredCliPathIssue`.

## 9. JSON API Contract

Source: `docs/json-api.md@main` —
https://github.com/Loongphy/codex-auth/blob/main/docs/json-api.md. (Full JSON shapes already
quoted verbatim in the task's known-context; key structural points not to lose:)

- Every document carries `"schema_version": 1` (a **separate, independent** version number from
  the registry's `schema_version` — this is the *API* contract version, decoupled from the
  on-disk format version). Clients must ignore unknown fields/enum values (forward-compat by
  convention, not by strict schema).
- Exactly one JSON document on stdout, newline-terminated; all diagnostics go to stderr and are
  explicitly **not** part of the contract (no accidental stdout pollution from warnings).
  Exit codes: `0` success, `1` handled operation error (stdout has a JSON error doc), `2` invalid
  usage.
- `account_key` is the stable identifier for scripting; `number` (display row) is explicitly
  ephemeral/only valid for that single invocation's ordering — a deliberate two-tier identifier
  design (stable machine key vs. disposable human-friendly ordinal).
- Interactive/live modes are explicitly excluded from the JSON contract — only `list`, `switch
  <query>`, `remove <selector...>|--all` support `--json`. This is a clean boundary: anything
  requiring a TTY/interactivity is out of scope for scripting.
- Remove's `selector_resolution_failed` error resolves *every* selector before reporting, listing
  a `resolutions[]` array with per-selector `status: resolved|ambiguous|not_found` — good precedent
  for "batch operation reports everything wrong at once" rather than stopping at the first bad
  selector.

## 10. Project Conventions (meta-context)

Source: `AGENTS.md@main` (https://github.com/Loongphy/codex-auth/blob/main/AGENTS.md) and
`style.md@main` (https://github.com/Loongphy/codex-auth/blob/main/style.md).

- `AGENTS.md` mandates English-only user-facing text; requires `zig build run -- list` after any
  `.zig` edit as a smoke test; forbids guessing Zig stdlib APIs from memory — contributors (human
  or AI) must run `zig env`/`zig version` and read the actual local stdlib source
  (`std_dir`/`lib_dir`) before using an API, "prefer evidence from local sources: symbol
  definitions, nearby tests, existing call sites." Also explicitly says implementation detail
  belongs in `docs/*.md`/`AGENTS.md`, not `README.md` — the README stays a user-facing pitch, deep
  behavior contracts live in `docs/`. That split is exactly why this research doc had to go past
  the README into `docs/commands/*.md` and the source.
- `style.md` defines a small semantic color-role palette (header/primary/secondary/success/error/
  hint) mapped to ANSI codes in one place (`src/cli/style.zig`) rather than scattering raw color
  codes through business logic — worth mirroring structurally (one style module, semantic roles)
  regardless of language/palette choice.

---

## Applicability to a Claude Code Equivalent

Mapping each codex-auth concept to its Claude Code CLI analogue. Known Claude-side facts (already
established, not re-derived here): Claude Code CLI stores OAuth credentials in
`~/.claude/.credentials.json` (mode `0600`), and account/session state (an `oauthAccount` block,
plus project/history/memory state) in `~/.claude.json` in the home directory.
`CLAUDE_CONFIG_DIR` can redirect the entire `~/.claude` directory but that's too broad for this
purpose (it isolates memory/history/settings/etc. too — we only want to isolate/swap *credentials*
per account, not fork the whole Claude Code state).

| codex-auth concept | Claude Code analogue / design implication |
|---|---|
| `~/.codex/auth.json` (live credential file, plain JSON, copied whole) | `~/.claude/.credentials.json` is the direct analogue: single JSON file, mode `0600`, holds the live OAuth material. A Claude-side tool should copy this file wholesale per account, exactly like `activateAccountByKey` copies `accounts/<key>.auth.json` over `auth.json`. |
| `~/.codex/accounts/registry.json` (index + metadata, never raw creds) | A `~/.claude-auth/registry.json` (tool-owned, outside `~/.claude` and outside `CLAUDE_CONFIG_DIR` scope) holding account records: derived id, alias, cached display info (email/org), created/last-used timestamps, `active_account_key`, `previous_active_account_key`. Should **not** duplicate `~/.claude.json`'s `oauthAccount` block content beyond what's needed to identify/display an account — mirror codex-auth's separation of "identity index" vs "credential blob." |
| `account_key = chatgpt_user_id + "::" + chatgpt_account_id`, decoded from the OAuth `id_token` JWT | Need the equivalent for Anthropic's OAuth flow: whatever stable user/workspace identifier is embedded in Claude's credentials/`oauthAccount` block (e.g. account id / org id if present) — must NOT be reconstructed by making any undocumented API call; parse only what's already present in the credentials file or `~/.claude.json`'s `oauthAccount`, the same way codex-auth only decodes the JWT it already has, no network round-trip required for identity. |
| `accounts/<key>.auth.json` snapshot files, one full copy per account | `<claude-auth-home>/accounts/<key>.credentials.json` — same one-file-per-account snapshot copy strategy, same filename-safety encoding rule (base64url the key if it contains unsafe chars) as `accountFileKey`/`encodedFileKey`. |
| `switch`: backup-if-changed → copy snapshot over live file → update `active_account_key`, preserving file mode | Directly portable: back up `~/.claude/.credentials.json` if content differs, copy the target snapshot over it *preserving existing file permissions* (don't force `0600` if Claude Code itself left it some other mode), then update the registry pointer. This is the core, safe, restart-required swap — matches codex-auth's default (non-`app`) behavior, which is the only part of codex-auth we're replicating. |
| `previous_active_account_key` / `switch -` | Directly portable as a "swap back" convenience — trivial to add, low risk, high daily-driver value. |
| Alias set/clear, pure metadata, never touches credential file | Directly portable, same validation rules apply almost verbatim (reject empty, reject all-digit since numeric selectors mean row number, case-insensitive dedup). |
| Query/selector resolution: exact key → row number → substring match on email/alias/account name; ambiguous → interactive picker or JSON error with candidates | Directly portable design, same tiering. Whatever display name Claude Code's `oauthAccount` block exposes (email, org name) stands in for codex-auth's email/account_name. |
| `import`/`export` with directory scanning, alias-on-single-file only | Directly portable: `import <path>` to adopt an existing `.credentials.json` (e.g. from another machine/profile) into the registry as a new account; `export [<dir>]` to dump snapshots for backup/transfer. No CPA-equivalent needed unless a similar third-party proxy tool exists in the Claude ecosystem — skip unless there's a concrete target format. |
| `import --purge`: rebuild registry from on-disk snapshots when index and files have drifted | Directly portable as the disaster-recovery command — cheap to build, valuable given file-based state can always drift. |
| `clean`: prune old `.bak.*` files (keep newest 5), delete snapshot files no longer referenced by the registry, whitelist-based | Directly portable almost unchanged. |
| Atomic write for the registry file: temp file + `fsync` + atomic rename (POSIX), manual rename-swap-with-errdefer-rollback (Windows) | Directly portable pattern (temp file + rename) for the registry JSON regardless of implementation language — avoids partial/corrupt registry writes if the process is killed mid-save. Less critical for the credentials-file copy itself (codex-auth doesn't atomic-write that one either — it's a plain `copyFile`), but worth doing for the registry index at minimum. |
| Backup-only-if-changed, keep newest N, timestamped `.bak.YYYYMMDD-hhmmss[.N]` names | Directly portable, same scheme. |
| `0700` on the accounts directory, `0600` on managed files, but the **live** credential file's mode is left alone / preserved across swaps | Directly portable principle: harden the tool's own directory and snapshot files, but don't fight Claude Code over what mode it expects `.credentials.json` to have — preserve existing mode on swap, same as `replaceFilePreservingPermissions`. |
| JSON contract: separate `schema_version` for the *API* shape (vs. registry file schema), single stdout document, stderr for diagnostics only, stable `account_key` vs. ephemeral display `number`, exit codes 0/1/2, batch-selector resolution reporting all failures at once | Directly portable wholesale — this is a clean, already-proven design for a scriptable interface and has no Codex-specific coupling. Worth adopting nearly verbatim for a `claude-auth --json` mode. |
| `login`: run the real login flow against an **isolated scratch config dir**, then copy the result in — so a failed/partial login never corrupts live state | Directly portable if Claude Code's `/login` (or `claude setup-token` / OAuth device flow) can be pointed at an alternate config dir the same way codex-auth points `codex login` at a scratch `CODEX_HOME`. If Claude Code doesn't support an equivalent per-invocation config-dir override for its login flow, this may require using `CLAUDE_CONFIG_DIR` for the *scratch login only* (spun up in a temp directory, thrown away after extracting the credentials file) — narrower and safer than trying to use `CLAUDE_CONFIG_DIR` for normal day-to-day operation, since it's scoped to one throwaway login session, not the tool's steady state. |
| `app` / `CODEX_CLI_PATH` trick: per-launch env var override to redirect which CLI binary + config dir a GUI app process uses; real hot-swap logic lives in a *forked* CLI (`codext`), not in codex-auth itself | **Do not replicate the forked-CLI part** (out of scope per task background, and it's a large undertaking — an actual fork of Claude Code). The *general mechanism* is still worth noting as a future option: Claude Code CLI does not currently need a GUI-app-launch equivalent (it's a terminal CLI, not a desktop app you "launch" via OS package manager), so this pattern mostly doesn't transfer. The one narrow idea that *does* transfer without forking anything: `CLAUDE_CONFIG_DIR`, set only for the duration of a single spawned `claude` subprocess invocation (not exported into the parent shell), would let a wrapper launch one *specific* fully-isolated Claude Code session pinned to one account without touching the user's default `~/.claude` state at all — useful for a possible future `claude-auth run <query> -- <args>` one-shot subprocess command, distinct from `switch` (which mutates the shared default state so a normal interactive `claude` session picks it up). This is a genuinely different feature (spawn-with-isolated-identity) from `switch` (swap-the-shared-default), and both could coexist. |
| Outbound `--api` usage/plan refresh against undocumented `chatgpt.com/backend-api/*` endpoints with the raw access token | **Explicitly excluded per task instructions.** No Claude-side equivalent should call any undocumented Anthropic endpoint with a raw token for usage/plan metadata. If a Claude tool wants usage/plan display at all, it must be limited to whatever Claude Code's own documented `/usage`-style output or officially supported channels expose — or omitted entirely (`list`/`status` without live usage numbers is still a fully useful account-registry tool, as codex-auth itself demonstrates via its `--skip-api` mode, which is fully functional and local-only). |
| `clean background` migration cleanup for a removed `auto_switch` daemon feature | Not directly applicable (we have no legacy daemon to clean up), but the underlying lesson — codex-auth *had* a background auto-switch daemon and removed it — is a signal worth heeding: don't build a background daemon/service for a Claude equivalent; keep it a synchronous CLI operating on files, invoked on demand. |
| Terminal color/style module: one file (`src/cli/style.zig`) mapping semantic roles to ANSI codes, referenced everywhere else by role name | Directly portable structural idea regardless of implementation language: define role constants (header, primary, secondary, success, error, hint) once, never scatter raw color codes through command logic. |

### Summary of scope for a `claude-auth`-equivalent tool

Building directly on the above, the buildable core (all "directly portable" rows) is: a
registry file (JSON, atomic-written, backed up) living outside `~/.claude` and outside any
`CLAUDE_CONFIG_DIR` scope; one full snapshot copy of `~/.claude/.credentials.json` per known
account, filename-derived from a stable per-account key; `login` (scratch-dir isolated),
`switch`/`switch -`/`switch <query>`, `remove`, `alias set/clear`, `import`/`export`,
`clean`/`import --purge` for recovery, and a `--json` machine contract modeled closely on
`docs/json-api.md`. Explicitly out of scope per task instructions: any undocumented-endpoint usage
API, and the forked-CLI hot-swap-without-restart mechanism (the `app`/`codext` piece) — Claude
Code CLI sessions will need a restart (or a separately-spawned, isolated-env subprocess) to pick up
a swapped account, exactly as stock (non-`codext`) Codex CLI/App does today.
