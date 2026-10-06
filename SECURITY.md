# Security

This plugin copies your Grav backups to Google Drive. A Grav backup zip holds
`user/`: `user/accounts` (password hashes), every config file and, unless you
exclude it, `user/data/gdrive/auth/` (the site's Google refresh token, OAuth
client secret or service-account key). Treat access to the Drive folder like a
password. If you find a way to read, redirect or destroy those backups through
this plugin, please report it privately rather than in a public issue.

- Use **Report a vulnerability** under this repository's Security tab (GitHub's
  private advisory), or email the author listed in `blueprints.yaml`.
- Say which version (`blueprints.yaml` `version:`), which Google Drive Auth
  version, and whether the account is OAuth or a service account.

What the plugin promises, so you know what counts as a bug:

- **Where backups go is a Google Drive manager's call.** Only an admin with
  `api.gdrive.manage` (or `api.super`) can change **Google Drive account** and
  **Drive folder**. The check is server-side, in the plugin's `onAdminSave`
  handler: a settings save by anyone else has those two values put back to
  what is on disk (`Sync::guardTarget`), so a plain `api.config.write` holder
  cannot aim the site's Google credential at a folder they control and receive
  every backup zip. The permission is read from the saving user and their
  groups (`$grav['admin']->user`, which the api plugin sets for Admin2's JWT
  users), not from `User::authorize()`, which is false for those users. The
  fields' help text says the same, but the handler is what enforces it.
- **The folder setting is parsed, never used as a URL.** `Sync::folderId()`
  takes a Drive folder link (`/folders/<id>` or `?id=<id>`) and keeps only the
  `[A-Za-z0-9_-]` id; a bare value is used as the id. The id reaches Google
  URL-encoded in paths and quoted in `q` queries (Google Drive Auth's
  `Drive` does the quoting). Links shown on the settings page are built only
  from ids filtered to that alphabet; any other value is shown as plain text,
  with Markdown and HTML characters stripped. The `gdrive-folder` field opens
  only `https://drive.google.com/` links in a new tab, with `noopener`, and
  escapes everything it puts into its own HTML.
- **Only files the plugin tagged are ever candidates.** Retention sees only
  files whose `appProperties` carry `grav_backup=1`, filtered in the Drive
  query and again on each result. Nothing else in the folder is listed,
  replaced or trashed.
- **Starred files are never trashed**, and don't count toward retention.
- **Trash only.** Old copies go to Drive's trash (`trashed=true`), never a
  permanent delete, so there is a 30-day undo. A missing or trashed folder is
  replaced by a new one, never restored, adopted or deleted.
- **Every upload is verified.** The `md5Checksum` Drive reports is compared
  with `md5_file()` of the local zip; a mismatch is trashed and recorded as an
  error, and any failed upload skips retention for that run, so an older copy
  is never trashed while a newer one failed to land.
- **One run at a time.** A non-blocking `flock` on
  `user/data/gdrive-backup/sync.lock` makes the scheduler job and the
  after-backup sync skip rather than overlap.
- **Nothing stored or cached comes from the `Host` header.** The site name in
  the folder name and the `site` tag is the host of `system.custom_base_url`,
  or the server's own hostname when that is empty; admin links are
  root-relative.
- **No secrets in config, status or logs.** The plugin's config holds an
  account name and a folder link or id. `status.json`, the settings page and
  `logs/gdrive-backup.out` carry file names, folder ids, counts, Google error
  messages and, on the settings page only, the account's email and folder
  names. The settings page needs `api.config.read`; its folder check is not on
  Admin2's `/data/resolve` allowlist (open to `api.pages.read`), so page
  editors can't reach it or spend the site's Google token.
- **Bounded network use.** The sync runs in the CLI (the scheduler, or
  `bin/grav backup`), never in a web request; "Backup now" in the admin leaves
  the upload to the scheduler job. The settings page's folder check is the only
  Google call on a web request: 5 seconds in all, no retries, cached for 5
  minutes. Nothing runs at boot or on front-end requests.
- **Failure-safe.** The sync catches every `Throwable`, records it in
  `status.json` and `grav.log`, and returns a one-line summary, so a plugin
  error can't break the backup that just ran, the scheduler, or the admin.
  Every settings-page renderer falls back to a plain line.

Keep your web server refusing `user/data/` (Grav's shipped Apache, nginx,
Caddy, lighttpd and IIS configs do), keep the Drive folder private, and
consider adding `/user/data/gdrive/auth` to the backup profile's
**Exclude paths** (the settings page warns while an active profile includes
it; you then reconnect Google after restoring from such a backup).
