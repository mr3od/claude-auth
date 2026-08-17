# Publishing & Docs Primary-Source Research

Research target: how to prepare `claude-auth` (Laravel Zero / PHP 8.3, MIT, to be published as
`mr3od/claude-auth` on GitHub + Packagist) for a public release — README structure, agent-instruction
files, PHP packaging conventions, `composer.json` correctness, and standalone-binary distribution.
Fetched directly from primary sources (official docs, specs, first-party guidance) on 2026-08-17.
Every claim below cites the specific page it came from. Where a primary source didn't cover something
asked about, that gap is stated explicitly rather than filled in with a guess — same discipline as
`docs/codex-auth-research.md` and `docs/laravel-zero-best-practices.md`.

This document is research only. It contains no implementation.

---

## 1. README.md best practices

### GitHub's own guidance

Source: [About READMEs](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/about-readmes)

- GitHub doesn't prescribe a section order, only content categories: "README files typically include
  information on: What the project does / Why the project is useful / How users can get started with
  the project / Where users can get help with your project / Who maintains and contributes to the
  project."
- Multi-README resolution order if more than one exists: `.github` directory, then repo root, then
  `docs` directory — root is the conventional and simplest choice.
- Scope discipline, stated directly: "A README should only contain information necessary for
  developers to get started using and contributing to your project. Longer documentation is best
  suited for wikis" — i.e. deep behavioral docs belong elsewhere (this project already does that via
  `docs/*.md`).
- Hard technical limit: content beyond 500 KiB is truncated when rendered on GitHub.
- READMEs are positioned as one piece of a larger expectations-setting set: "A README, along with a
  repository license, citation file, contribution guidelines, and a code of conduct, communicates
  expectations for your project."

### The standard-readme spec

Source: [standard-readme spec.md](https://github.com/RichardLitt/standard-readme/blob/master/spec.md)

This is the closest thing to a canonical, versioned README spec (it explicitly defines required vs.
optional sections and an enforced order — "sections must appear in order given below").

- **Required, in order**: Title (must match the repo/package name) → short Description (<120 chars,
  should align with package-manager metadata) → Table of Contents (required unless the README is
  under ~100 lines) → Install (with code examples) → Usage (with code examples) → Contributing (how to
  ask questions / submit changes) → License (full name + SPDX identifier).
- **Optional, in recommended order between Title and License**: Banner, Badges, Long Description,
  Security, Background, N "Extra sections" (between Usage and API), API reference, Maintainers,
  Thanks/Credits.

### A concrete Laravel Zero-based reference: Laravel Zero's own README

Source: [laravel-zero/laravel-zero](https://github.com/laravel-zero/laravel-zero)

Order observed: logo → badges (build status, downloads, latest version, license) → one-line pitch +
creator attribution → a bulleted "key features" list → social/support links → a short "Documentation"
section that hands off entirely to the docs site (laravel-zero.com) rather than duplicating command
reference in the README → license line → repo topic tags. Notably, Laravel Zero's own README carries
**no command reference at all** — it treats the README purely as a pitch/landing page and pushes all
actual usage docs to the external docs site. That's a defensible pattern for a framework with its own
docs domain, but less appropriate for a single CLI tool with no separate docs site, where users expect
the command reference to live in the README or in-repo `docs/`.

### Gaps in the current `~/claude-auth/README.md`

Read directly (`/home/mr3od/claude-auth/README.md`, 56 lines). Current order: Title → one-line pitch →
"Status" (full 8-command reference, prose form) → "Design" (architecture notes) → "Development"
(3-line setup). Checked against standard-readme's required list:

- **No badges** — no license/build/Packagist-version badge. Cheap to add once published (Packagist
  auto-generates version/downloads badges for any registered package).
- **No explicit "Install" section** — `composer install` only appears under "Development" (i.e.
  contributor setup), not end-user installation. There is no end-user-facing install instructions at
  all (no `composer global require`, no PHAR/binary download instructions) — this is the single
  biggest gap, and it's the same gap section 5 below addresses at the distribution-mechanism level.
- **No Table of Contents** — currently borderline-necessary (56 lines, under standard-readme's ~100-line
  exemption threshold), but will likely need one once an Install section and full per-command option
  reference are added.
- **No "Contributing" section** — no guidance on filing issues/PRs, no `CONTRIBUTING.md` referenced.
- **License is stated in prose only** ("MIT" isn't mentioned in the README at all, actually — checked:
  it is not present in README.md, only in `LICENSE` and `composer.json`). standard-readme requires the
  License section name the license and owner explicitly in the README itself, not just link to a
  `LICENSE` file implicitly via GitHub's automatic detection.
- **"Status" section reads like a command reference, but isn't structured as one** — it's a paragraph
  list of full sentences rather than a scannable table/list of `command — flags — description`, and it
  doesn't show example invocations (standard-readme's Usage section wants code examples, not prose).
- What the README does well already: clear architecture/"Design" section (a good non-required "Extra
  section"), and correctly defers deep research/behavioral rationale to `docs/` rather than bloating
  the README (matches GitHub's own "longer documentation belongs elsewhere" guidance and the
  `codex-auth` project's own convention noted in that research doc's §10).

### Implications for claude-auth

- Add, in this order, to match both GitHub's content categories and standard-readme's required
  ordering: Title (already present) → one-line tagline → badges (Packagist version/downloads, license,
  CI if one exists) → **Install** (the actual install method chosen per §5 below — e.g.
  `composer global require mr3od/claude-auth` and/or a binary download line) → **Usage** (a couple of
  concrete example invocations, e.g. `claude-auth login`, `claude-auth switch work`) → full command
  reference (keep the existing content, but reformat as a scannable list with example invocations, not
  pure prose) → the existing "Design" section (good as-is, keep as an "extra section") →
  **Contributing** (even a two-line pointer to filing issues/PRs is enough) → **License** (name MIT and
  the copyright holder explicitly, not just imply it).
- State the license inline near the top, not only in `LICENSE`/`composer.json` — standard-readme and
  general OSS convention both expect it visible in the README itself.
- Once §5's distribution decision is made, the Install section is the one place that must reflect it
  precisely (PHAR download vs. Composer global install vs. platform binary) — don't leave the current
  `composer install` "Development" line as the only install instructions, since that's a contributor
  workflow, not an end-user one.

---

## 2. AGENTS.md / CLAUDE.md conventions

### agents.md — confirmed real, and now under the Linux Foundation

Source: [agents.md](https://agents.md/), corroborated by the [Linux Foundation's announcement of the
Agentic AI Foundation (AAIF)](https://www.linuxfoundation.org/press/linux-foundation-announces-the-formation-of-the-agentic-ai-foundation)
and [OpenAI's own post confirming the contribution](https://openai.com/index/agentic-ai-foundation/).

- `agents.md` is a real, live canonical spec site (originated at OpenAI, alongside Codex/Amp/Jules/
  Cursor/Factory collaboration), and — as of the AAIF announcement (Dec 2025) — is now a
  Linux-Foundation-stewarded open standard, claimed adoption "60,000+ repos."
- Framing, quoted directly from the site: "Think of AGENTS.md as a **README for agents**: a dedicated,
  predictable place to provide the context and instructions to help AI coding agents work on your
  project," explicitly kept separate so READMEs "stay concise and focused on human contributors."
- **Format**: plain Markdown, no required schema/frontmatter — example sections shown on the site are
  `Setup commands`, `Code style`, `Dev environment tips`, `Testing instructions`, `PR instructions`
  (illustrative, not mandatory field names).
- **Monorepo/nesting rule**, quoted: "Place another AGENTS.md inside each package. Agents automatically
  read the nearest file in the directory tree, so the closest one takes precedence and every subproject
  can ship tailored instructions."
- It is explicitly cross-tool: works with Codex, Cursor, GitHub Copilot, Devin, and (per Anthropic's
  own docs, see below) is read by Claude Code indirectly via an import, not natively.

### CLAUDE.md — Anthropic's own docs

Source: [Claude Code: How Claude remembers your project](https://code.claude.com/docs/en/memory)
(canonical current URL; `docs.claude.com/en/docs/claude-code/memory` 301-redirects here).

- Two separate, complementary mechanisms, both loaded every session: **CLAUDE.md** (human-written
  instructions/rules) vs. **auto memory** (Claude-written learnings, stored under
  `~/.claude/projects/<project>/memory/`, entrypoint `MEMORY.md`). CLAUDE.md is what a project should
  ship in version control; auto memory is machine-local and per-developer.
- **CLAUDE.md locations**, in load order broadest→narrowest: managed org policy path (IT-deployed,
  outside repo) → `~/.claude/CLAUDE.md` (personal, all projects) → `./CLAUDE.md` or `./.claude/CLAUDE.md`
  (project, team-shared via VCS) → `./CLAUDE.local.md` (personal + project-specific, meant to be
  gitignored).
- **Recommended content**, quoted: "coding standards, workflows, project architecture" and general
  guidance — "Add to it when: Claude makes the same mistake a second time / a code review catches
  something Claude should have known / you type the same correction twice / a new teammate would need
  the same context." Explicitly *not* the place for multi-step procedures (those belong in a "skill")
  or path-specific-only guidance (those belong in `.claude/rules/*.md` with `paths:` frontmatter).
- **Size guidance**: target under 200 lines; CLAUDE.md is loaded in full every session regardless of
  length, but "shorter files produce better adherence." Structure with markdown headers/bullets;
  instructions should be concrete/verifiable ("Use 2-space indentation" not "format code properly").
- **`AGENTS.md` explicitly addressed**: "Claude Code reads `CLAUDE.md`, not `AGENTS.md`." The
  documented reconciliation pattern for a repo that already has an AGENTS.md is either (a) a `CLAUDE.md`
  that does `@AGENTS.md` import plus Claude-specific additions below it, or (b) a plain symlink
  (`ln -s AGENTS.md CLAUDE.md`) if no Claude-specific content is needed. `/init` (with
  `CLAUDE_CODE_NEW_INIT=1`) and `/import` can also read an existing AGENTS.md and fold it in.
- `/init` is the documented way to bootstrap a CLAUDE.md: "Claude analyzes your codebase and creates a
  file with build commands, test instructions, and project conventions it discovers." It won't
  overwrite an existing file, only suggest improvements.

### Do they overlap or serve different purposes?

They overlap substantially in *intended content* (both want build/test commands, coding conventions,
architecture notes) but differ in *audience and mechanism*: AGENTS.md is a cross-tool, spec-level file
any agent can read directly; CLAUDE.md is Claude Code's own proprietary mechanism with its own loading
rules (hierarchical directory walk, imports, `.claude/rules/` path-scoping, managed-policy layer, size
enforcement) that no other agent understands. Anthropic's own docs resolve the overlap explicitly:
maintain one canonical file (AGENTS.md, if the project wants cross-tool support) and have CLAUDE.md
import it rather than duplicating content.

### Current state of `~/claude-auth`

Confirmed via `ls`: **neither `AGENTS.md` nor `CLAUDE.md`/`.claude/CLAUDE.md` exists in the repo today.**

### Implications for claude-auth

- Since this repo will presumably keep using Claude Code as its own dev tool (and may want other
  agents/contributors to pick up conventions too), the cheapest correct setup per Anthropic's own docs
  is: write one `AGENTS.md` at repo root with the real project conventions (Laravel Zero conventions
  already captured in `docs/laravel-zero-best-practices.md`'s "Implications" section — thin commands,
  `config()` helper usage, Pest test conventions, `Registry` service pattern), then add a two-line
  `CLAUDE.md` that does `@AGENTS.md` plus any Claude-Code-specific addition (if any) — this matches
  Anthropic's documented reconciliation pattern and avoids maintaining two divergent instruction files.
- Given the user's own MEMORY.md already logs "Backup before mutating credentials" and git-commit-style
  preferences as durable project facts, those are exactly the kind of "coding standards / workflows"
  content CLAUDE.md's own docs say belongs in the file, not left only in personal auto-memory — worth
  promoting into the committed AGENTS.md/CLAUDE.md so any contributor (human or agent) sees them, not
  just this user's own Claude Code sessions.
- Keep it under ~200 lines per Anthropic's stated adherence guidance; this is a small-to-medium project
  so that should be easy.

---

## 3. PHP community package-publishing conventions

Source: [Packagist: About](https://packagist.org/about), [Composer: Libraries](https://getcomposer.org/doc/02-libraries.md),
[Composer: Versions](https://getcomposer.org/doc/articles/versions.md).

- **`composer.json` placement**: "The composer.json file should reside at the top of your package's
  git/svn/.. repository, and is the way you describe your package to both packagist and composer"
  (Packagist's own words) — confirmed already correct for this repo (root-level `composer.json`).
- **Naming**: vendor/project, lowercase, `[a-z0-9]` plus `.`/`-`/`_` separators; "vendor names on
  packagist are protected once a package with that name has been published" — `mr3od/claude-auth`
  already satisfies the pattern and the vendor namespace is presumably owned by this GitHub user.
- **Versioning is tag-driven, not hand-written**: Composer's own Libraries doc says a maintained
  library "**should not** specify a version in your `composer.json` file" — versions are parsed from
  VCS tags/branches instead. Packagist confirms accepted tag formats explicitly: `1.0.0`, `v1.0.0`,
  `v1.10.5-RC1` (Composer strips a leading `v` internally to get the canonical version). Semantic
  Versioning is "strongly encouraged" by Packagist and is the explicit basis for Composer's own
  version-constraint operators (`^`, `~`) — the caret operator is "the recommended operator for maximum
  interoperability when writing library code" specifically because it "sticks closer to semantic
  versioning."
- **`keywords`/`description` for discoverability**: both are standard Composer schema fields (see §4);
  Packagist's own submission docs don't add extra discoverability rules beyond populating these fields
  meaningfully — already done reasonably in this repo's `composer.json`.
- **GitHub webhook auto-update**: Packagist explicitly recommends wiring a push webhook — "It is highly
  recommended to set up the GitHub/BitBucket/GitLab/Gitea service hook for all packages, as this reduces
  the load on Packagist's side and ensures packages are updated almost instantly" — payload URL
  `https://packagist.org/api/github?username=PACKAGIST_USERNAME`, content type `application/json`,
  secret = Packagist API token, `push` event only is sufficient. Without the webhook, "existing packages
  without auto-updating... will be crawled once a week for updates" (so pushing a new tag without the
  webhook configured means up to a week's delay before Packagist reflects the release, or a manual
  "Update" click on the package page).
- **Distribution hygiene**: Composer's Libraries doc recommends `.gitattributes` `export-ignore` entries
  to keep non-runtime files (tests, docs tooling, CI config) out of the distributed zip — this repo's
  `.gitattributes` already does this for `/.github`, `CONTRIBUTING.md`, `CHANGELOG.md` (checked
  directly: `/home/mr3od/claude-auth/.gitattributes`), though `docs/`, `tests/`, and `phpunit.xml.dist`
  are not currently `export-ignore`d.
- `composer.lock`: optional for a library/CLI tool — "you may commit the composer.lock file if you
  want to... this lock file will not have any effect on other projects that depend on it." This repo
  does commit `composer.lock`, which is fine per this guidance (helps reproducible local dev/CI, has no
  effect on consumers).

### Implications for claude-auth

- Do nothing to `composer.json`'s version field — it's correctly absent already (confirmed in §4 below)
  — and tag releases as `vX.Y.Z` (or `X.Y.Z`; either is accepted, `v`-prefixed is the more common GitHub
  convention and matches the PHPacker/GitHub-Releases example command shown in §5).
- After the first Packagist submission, configure the GitHub push webhook immediately — this is a
  one-time, low-effort step and is Packagist's own explicit recommendation over relying on the weekly
  crawl.
- Consider adding `export-ignore` for `docs/`, `tests/`, `phpunit.xml.dist`, and `box.json` in
  `.gitattributes` if this is published as a Composer *library* install path (not just a PHAR/binary
  download) — irrelevant if end users only ever fetch prebuilt binaries/PHARs from GitHub Releases
  rather than `composer require`-ing the source (see §5's recommendation on this point, since Laravel
  Zero's own docs describe a different `composer.json` shape needed for that path — `laravel-zero/framework`
  moved to `require-dev`, `bin` repointed at the built artifact).

---

## 4. How to write composer.json well

Source: [Composer Schema Reference](https://getcomposer.org/doc/04-schema.md).

- **Required for a published package (library)**: `name`, `description`. Both present in this repo's
  `composer.json`.
- **"Optional, but highly recommended"**: `license`, `authors`. Both present here (`"license": "MIT"`,
  one author with name + homepage — email isn't set, which the schema allows since `authors[].email` is
  itself optional).
- **`keywords`**: "used for searching and filtering" on Packagist; special values (`dev`, `testing`,
  `static analysis`) hint Composer to suggest `require-dev` placement — not applicable here. Current
  keywords (`claude`, `claude-code`, `cli`, `accounts`, `auth`) are reasonable and specific.
- **`require` vs `require-dev`**: `require` = "will not be installed unless those requirements can be
  met" (i.e. runtime deps); `require-dev` = deps "for developing this package, or running tests, etc.,"
  installed by default for the root package but never pulled in by consumers who `require` this package
  as a dependency. Current split (`laravel-zero/foundation` + `laravel-zero/framework` in `require`;
  `pint`/`mockery`/`pest` in `require-dev`) is correct for a plain Composer-library-style install.
  **However**, see the flag below — this split is *not* what Laravel Zero's own docs recommend once
  you're distributing a built PHAR/binary rather than the raw source (§5's "Distribution Guidance").
- **`minimum-stability` / `prefer-stable`**: schema docs list valid stabilities in order `dev, alpha,
  beta, RC, stable` (default `stable`); "If you rely on a `dev` package, you should specify it in your
  file to avoid surprises." This repo sets `"minimum-stability": "dev"` with `"prefer-stable": true` —
  a legitimate, documented combination (broadens the stability floor so any `dev`-only transitive
  dependency can resolve, while `prefer-stable` still picks the highest *stable* version whenever one
  satisfies the constraint) — worth double-checking, once ready to tag a release, whether any current
  dependency actually still needs `minimum-stability: dev`, since a stricter `stable` floor is generally
  preferable for a published package if nothing forces the looser setting.
- **`version`**: schema docs are blunt about this — "In most cases this is not required and should be
  omitted... Specifying the version yourself will most likely end up creating problems at some point due
  to human error," recommending VCS-tag inference instead (matches §3). This repo correctly omits
  `version` entirely.
- **`type`**: defaults to `library`; "Only use a custom type if you need custom logic during
  installation." This repo sets `"type": "project"` — schema-legal (a recognized built-in type: for an
  application, not a reusable library) and arguably the more accurate choice than `library` given this
  is a CLI application, not something meant to be depended on by other Composer packages. Worth being
  deliberate about this choice, since `type: project` vs `library` slightly changes tooling expectations
  (e.g. some Composer tooling/plugins treat `project` packages as "not installable as a dependency" in
  certain contexts) — it doesn't block `composer global require` or Packagist listing either way, so the
  current setting is fine.
- **`bin`**: "A set of files that should be treated as binaries and made available into the `bin-dir`."
  Current `"bin": ["claude-auth"]` correctly points at the executable script — this is exactly the field
  Laravel Zero's own docs (§5) say must be repointed at the *build output* path if distributing a built
  PHAR instead of raw source.

### Implications for claude-auth

- No required-field gaps: `name`, `description`, `license`, `authors`, `keywords` are all present and
  correctly formed per Composer's schema.
- Before tagging a first release, decide the distribution shape (source install via `composer global
  require` vs. a built PHAR/binary via GitHub Releases, per §5) — that decision determines whether
  `laravel-zero/framework` should move to `require-dev` and `bin` should point at a `builds/` artifact
  instead of the current `claude-auth` bootstrap script, per Laravel Zero's own documented pattern for
  Packagist-distributed built artifacts (§5's "Distribution Guidance" finding).
- Re-check `minimum-stability: dev` is still actually required by some dependency before the first
  tagged release; if nothing needs it, tightening to `stable` (or leaving it, since `prefer-stable`
  already biases resolution correctly) is optional polish, not a defect.

---

## 5. Shipping a single standalone binary (most important section)

Source, primary: Laravel Zero's own docs —
[Distribute as a PHAR Archive](https://laravel-zero.com/docs/distribute-as-a-phar-archive) and
[Distribute as a Single Executable Binary](https://laravel-zero.com/docs/distribute-as-a-single-executable-binary).
Secondary/first-party: [PHPacker](https://phpacker.dev) (the tool Laravel Zero's own docs point to),
[crazywhalecc/static-php-cli](https://github.com/crazywhalecc/static-php-cli) README, Owen Voke
(Laravel Zero co-maintainer)'s blog post on [Homebrew distribution](https://voke.dev/blog/distributing-laravel-zero-apps-with-brew/)
(fetch blocked by a 403 in this pass — cited for completeness but not independently re-verified here;
treat as a lead, not a confirmed quote), and [box-project/box configuration docs](https://github.com/box-project/box/blob/main/doc/configuration.md).

### (a) Does the PHAR build require PHP on the end user's machine?

**Yes — Laravel Zero's own docs say this explicitly and unambiguously**: "A PHAR archive still requires
PHP to be installed on the machine that runs it." A `php claude-auth <command>` or `./claude-auth
<command>` invocation of a bare `app:build` PHAR is *not* PHP-free; it only removes the need for
`composer install`/cloning the repo, not the need for a PHP runtime.

### (b) The current PHAR path (what `box.json` already configures)

`app:build` is documented as `./box compile --working-dir=/path --config=/path/box.json` under the
hood (humbug/box). The command is `php application app:build claude-auth-name [--build-version=X.Y.Z]
[--timeout=N]`, producing a `.phar` under `builds/`. `box.json` controls what's bundled: `directories`
(defaults `app`, `bootstrap`, `config`, `vendor`), `files` (defaults `composer.json`), `compression`,
`compactors`. Docs explicitly warn to add any other runtime-needed directories (e.g. `database`,
`resources`) — not applicable here since this project has none.

**Checked `~/claude-auth/box.json` against this**: it sets `chmod: "0755"`, the four default
`directories` (`app`, `bootstrap`, `config`, `vendor`), `files: ["composer.json"]`,
`exclude-composer-files: false`, `compression: "GZ"`, and the two default `compactors` (Php, Json). This
matches Laravel Zero's documented default scaffold exactly — nothing custom to this project (e.g. no
extra data/template directories) is missing. It's complete and correct **for the PHAR-only path**. It
does **not** set a `main` entry point explicitly, which matches Box's convention of resolving it from
`composer.json`'s `bin` — consistent with the rest of the config, not a gap.

The PHAR's actual runtime requirement, per the docs verbatim: "Anyone with PHP installed may then run
it, without cloning your repository or running `composer install`" — i.e. this is the "no Composer
knowledge needed" tier, not the "no PHP install needed" tier the user's stated goal actually wants.

### (c) Zero-PHP-install standalone binary: Laravel Zero's actual documented answer is PHPacker, not static-php-cli

This is the key finding. Laravel Zero **does have** an official, first-party-documented path to a truly
standalone binary with the runtime embedded — but it is **PHPacker** (phpacker.dev), not
`static-php-cli`/`spc`. Laravel Zero's docs introduce it with almost the exact framing of the user's
stated goal: "A PHAR archive still requires PHP to be installed on the machine that runs it. When you
are distributing your application to people who may not have PHP — or may have the wrong version — you
may instead bundle your application into a standalone executable binary with the PHP runtime embedded
in it."

Documented steps, verbatim command shapes:

```bash
composer require phpacker/phpacker --dev
php application app:build movie-cli.phar           # PHAR must end in .phar for this path
./vendor/bin/phpacker build --src=./builds/movie-cli.phar --php=8.4 all
```

This produces binaries for macOS (arm64+x64), Linux (arm64+x64), and Windows (x64) under
`./builds/build/`, each with the PHP runtime embedded — no PHP install needed on the target machine.
Documented caveats: binaries are "considerably larger" than the PHAR (runtime is embedded), building
needs internet access (PHP binaries are fetched at build time), some extensions may not be available in
the embedded runtime, and the produced app runs in `production` env with a read-only bundled filesystem
(same read-only-PHAR caveat as the plain-PHAR path — matters for anything claude-auth writes at runtime,
though claude-auth already writes only to `~/.claude-auth/` outside the archive, so this should be a
non-issue).

**`static-php-cli`/`spc` is a real, actively maintained project** (crazywhalecc/static-php-cli, aka
StaticPHP) that does the same category of thing — "Build single-file PHP executable with zero
dependencies," including a `micro:combine` command that fuses a PHAR with a statically-built PHP
interpreter into one self-extracting file (`phpmicro`) — but **Laravel Zero's own documentation does
not mention, link to, or endorse it anywhere** in the pages checked. Its README also does not mention
Laravel or Laravel Zero. It is a viable, independently-documented alternative path (and is sponsored by
NativePHP, a project in the broader Laravel ecosystem, for context), but it is not the officially
sanctioned Laravel Zero mechanism the way PHPacker is — PHPacker is literally the tool named on
Laravel Zero's own "Distribute as a Single Executable Binary" doc page.

### (d) Distribution via Homebrew / curl / GitHub Releases

- **GitHub Releases + curl install**: not covered in Laravel Zero's own PHPacker/PHAR docs directly, but
  the general documented pattern (found via search, common convention rather than a Laravel-Zero-specific
  page) is: build per-platform binaries via `phpacker build ... all`, upload each to a tagged GitHub
  Release, and give users a `curl -L <release-asset-url> -o claude-auth && chmod +x claude-auth && sudo mv
  claude-auth /usr/local/bin/` one-liner, naming assets per platform/arch so the install script (or the
  user) can pick the right one.
- **Homebrew**: Laravel Zero's own PHAR doc explicitly gestures at this as a valid distribution channel
  ("if your application will primarily be used on macOS, you may want to distribute it as a Brew
  formula") without giving Laravel-Zero-specific formula instructions on that page itself; a blog post by
  Owen Voke (a named Laravel Zero co-maintainer per the framework's own README) titled "Distributing
  Laravel Zero apps with Brew" appears to cover this in more depth, but the fetch for this pass returned
  HTTP 403, so its content is not independently verified here — worth re-fetching (or reading manually)
  before committing to a Homebrew-based distribution plan, since it wasn't possible to confirm its exact
  guidance in this research pass.
- **Self-updating**: Laravel Zero's PHAR doc also documents an optional `self-update` component
  supporting GitHub, GitHub Releases, or GitLab as update-check strategies — relevant if this project
  wants a `claude-auth self-update` command rather than relying purely on Homebrew/package-manager
  upgrade flows.

### Summary answer to the four sub-questions

| Question | Answer |
|---|---|
| (a) Does `app:build`'s PHAR need PHP installed? | Yes, always — confirmed explicitly in Laravel Zero's own docs. |
| (b) If PHP is required, what's the actual pattern? | `php claude-auth <command>` today, or (after a trivial shebang/chmod fix, since Box already sets `chmod: "0755"` and the executable already has `#!/usr/bin/env php`) a bare `./claude-auth <command>` — PHP still has to be on PATH, just not typed by the user. This matches the box.json config already in the repo. |
| (c) Is static-php-cli a documented/endorsed path? | It's real and works, but Laravel Zero's own docs never mention it. The docs' actual named tool for a zero-PHP-install binary is **PHPacker**. |
| (d) Homebrew/curl/GitHub Releases distribution? | Gestured at in Laravel Zero's PHAR doc (Homebrew mentioned by name) and achievable via standard GitHub Releases + curl once PHPacker-built per-platform binaries exist; no single canonical Laravel-Zero doc page walks through all of this end-to-end. |

### Implications for claude-auth

- **The user's stated goal — end users running `claude-auth <command>` with zero PHP knowledge and no
  `php` prefix — requires PHPacker, not just Box/`app:build`.** The current `box.json` is complete and
  correct for what it does (PHAR-only, still needs PHP on PATH), but it is not sufficient on its own to
  meet the "no PHP install needed" bar; that requires the separate `phpacker/phpacker --dev` step
  documented above, run against the `app:build`-produced `.phar`.
- Concretely, the missing pieces to close this gap: (1) `composer require phpacker/phpacker --dev`, (2)
  build the PHAR with a `.phar`-suffixed name (`php claude-auth app:build claude-auth.phar
  --build-version=X.Y.Z`), (3) run `./vendor/bin/phpacker build --src=./builds/claude-auth.phar --php=8.3
  all` to get macOS/Linux/Windows binaries with the runtime embedded, (4) upload the resulting
  per-platform binaries as assets on a tagged GitHub Release, (5) update the README's Install section
  (§1's biggest gap) to point at those release assets (curl one-liner) as the primary end-user install
  path, keeping `composer global require mr3od/claude-auth` as a secondary path for PHP-literate users.
- If bundle size or embedded-extension limitations from PHPacker turn out to be a blocker in practice,
  `static-php-cli`'s `micro:combine` is a documented (if third-party, un-endorsed-by-Laravel-Zero)
  fallback worth prototyping — but PHPacker should be tried first since it's what Laravel Zero's own
  docs actually recommend and is a one-command integration on top of the existing `app:build` output.
- If this project ends up distributing prebuilt binaries as the primary path (rather than `composer
  require`-ing source), revisit `composer.json` per §3/§4's "Distribution Guidance" finding: Laravel
  Zero's own docs say to move `laravel-zero/framework` to `require-dev` and repoint `bin` at the built
  artifact for that scenario — current `composer.json` is set up for the "`composer require` the source"
  path, which may end up being the secondary rather than primary install method once PHPacker binaries
  exist.
- The Homebrew lead (Owen Voke's blog post) could not be independently verified in this pass (403 on
  fetch) — re-fetch or read it manually before writing a brew formula, rather than relying on the
  unverified summary in §5(d) above.

---

## References

- https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/about-readmes
- https://github.com/RichardLitt/standard-readme/blob/master/spec.md
- https://github.com/laravel-zero/laravel-zero
- https://agents.md/
- https://www.linuxfoundation.org/press/linux-foundation-announces-the-formation-of-the-agentic-ai-foundation
- https://openai.com/index/agentic-ai-foundation/
- https://code.claude.com/docs/en/memory
- https://packagist.org/about
- https://getcomposer.org/doc/02-libraries.md
- https://getcomposer.org/doc/articles/versions.md
- https://getcomposer.org/doc/04-schema.md
- https://laravel-zero.com/docs/distribute-as-a-phar-archive
- https://laravel-zero.com/docs/distribute-as-a-single-executable-binary
- https://phpacker.dev
- https://github.com/crazywhalecc/static-php-cli
- https://github.com/box-project/box/blob/main/doc/configuration.md
- https://voke.dev/blog/distributing-laravel-zero-apps-with-brew/ (fetch blocked, HTTP 403 — cited as an
  unverified lead only)
