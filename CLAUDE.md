# grav-plugin-gdrive-backup

A Grav CMS plugin (PHP 8.3+, Grav 2.0.23+) that uploads Grav backups to Google
Drive with HA-add-on-style retention. It depends on the shared library plugin
Google Drive Auth (`gdrive-auth`, `C:\dev\grav-plugin-gdrive-auth`, `Gdrive::drive()`); read that repo's
README "Public API" before using anything from it, and don't use anything
not listed there.

The design lives in `PLAN.md` (§5 is this repo; §1–§3 for context). The
decisions in §1 are settled.

## Layout

- `gdrive-backup.php`: events, scheduler job `gdrive-backup-sync`, and the
  glue in `sync()` (config → Drive client → folder → local backups → `Sync` →
  `status.json`).
- `classes/Retention.php`: pure. `keep()` and filename → timestamp.
- `classes/Sync.php`: one sync pass over a `Drive` and a list of local
  backups; also `folder()` and the `locked()` flock helper.
- `classes/Status.php`: `status.json` and the settings page's Markdown.

## Invariants (never simplified away)

- **Only tagged files**: nothing on Drive is a candidate unless its
  `appProperties` has `grav_backup=1`. Untagged files are never touched.
- **Starred files are never trashed.**
- **Trash only**: `Drive::trash()`, never a permanent delete.
- **Verify md5** of every upload against `md5_file()`; on mismatch trash the
  upload and record an error. Any failed upload skips retention for the run.
- Retention runs over the **union** of local and Drive backups, and only
  survivors are uploaded.
- `onSchedulerInitialized`, `onBackupFinished` and `onGdriveScopes` are
  subscribed **statically** in `getSubscribedEvents()`. Under Admin2 the api
  plugin sets `$grav['admin']`, so `isAdmin()` is true on every API request;
  never gate these behind it.
- `Event` array access returns copies: append with
  `$e['declarations'] = [...$e['declarations'], $decl];`.
- Local retention is Grav's `backups.yaml` purge; don't duplicate it.
- **`account` and `folder` belong to `api.gdrive.manage`**: `onAdminSave` puts
  back a change by anyone else (`Sync::guardTarget`, `canManageGdrive()` reads
  `$grav['admin']->user`, never `User::authorize()`, which is false for the api
  plugin's JWT users). A plain `api.config.write` holder could otherwise aim
  the site's Google credential at a folder they control and receive every
  backup zip.

## Conventions

- Thin and lazy, no Composer dependencies, final classes,
  `declare(strict_types=1)`, terse docblocks that explain why.
- Pattern library: `C:\dev\grav-plugin-gdrive-images` and the library: spl
  autoload fallback at `onPluginsInitialized` 100000, bare-PHP
  `tests/smoke.php` with `check()`, PHPStan level 6, and a `VERSION` constant
  that smoke asserts matches `blueprints.yaml`.
- Tests use the library's real `Drive` over a fake transport
  (`callable(string $method, string $url, array $opts): array{int, string, array}`).

## Tooling

No local PHP. Run it via Docker from Git Bash, mounting `C:\dev` so the
library at `../grav-plugin-gdrive-auth` resolves (not at `/dev`, which would hide
the container's own `/dev`):

```
MSYS_NO_PATHCONV=1 docker run --rm -v "C:/dev:/work" -w /work/grav-plugin-gdrive-backup php:8.3-cli php tests/smoke.php
MSYS_NO_PATHCONV=1 docker run --rm -v "C:/dev:/work" -w /work/grav-plugin-gdrive-backup php:8.3-cli php .gravtest/phpstan.phar analyse --memory-limit=1G
yamllint blueprints.yaml gdrive-backup.yaml
```

Without `MSYS_NO_PATHCONV=1` MSYS rewrites the `-v` colon, docker mounts
nothing, and a stray `<dir>;C` directory appears next to the repo.

CI checks the private library out into `lib` with the `GDRIVE_LIB_TOKEN`
secret (a fine-grained PAT with read access) and sets `GDRIVE_LIB=lib`.

On a deployed site PHP class changes take effect immediately; **YAML config
changes need `bin/grav clearcache`** (`clearcache`, not `clear-cache`).

Machine- and deployment-specific notes go in `CLAUDE.local.md`, which is
gitignored.

## Releasing

- Bump `version:` in `blueprints.yaml` and the plugin class's `VERSION`
  constant (smoke asserts they match), add the CHANGELOG entry (`# vX.Y.Z`
  heading is markdown only; the date line is ISO `## YYYY-MM-DD`), merge to
  `main`.
- **Tags are bare: `X.Y.Z`, never `vX.Y.Z`.** GPM and GitHub sort tags as
  text, so mixing the two styles hides updates (getgrav/grav#3992), and GPM
  submissions are refused with a `v` (getgrav/grav#4297).
  Every tag here was normalised to bare on 2026-10-06; keep it that way.
- GitHub release from that tag: title `X.Y.Z`, body = the CHANGELOG entry
  without its two heading lines:
  `gh release create X.Y.Z --title X.Y.Z --notes-file <body>`.

## Agent skills

### Issue tracker

GitHub Issues on `sandymac/grav-plugin-gdrive-backup`, via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` and `docs/adr/` at the repo root, created lazily. See `docs/agents/domain.md`.
