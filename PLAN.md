# Plan: grav-plugin-gdrive-backup

This repo is **grav-plugin-gdrive-backup**. Its own section is **§5**; the rest is family context.

Status: **planning only, nothing executed.** Written 2026-09-28. Execution waits
for the owner's explicit go-ahead. The same plan is copied into all three repos;
only the header above differs.

## 0. The family

| Repo (sibling folders in `C:\dev`) | Slug | Namespace | Role |
|---|---|---|---|
| `grav-plugin-gdrive` | `gdrive` | `Grav\Plugin\Gdrive` | Shared library: Google auth, Drive client, account management page and setup guides. No routes except the OAuth callback. |
| `grav-plugin-gdrive-images` | `gdrive-images` | `Grav\Plugin\GdriveImages` | Today's gallery code: `/gdrive` proxy, known-ids authz, watermark, disk cache, Twig `gdrive_gallery()`. |
| `grav-plugin-gdrive-backup` | `gdrive-backup` | `Grav\Plugin\GdriveBackup` | Uploads Grav backups to Drive after they're made, with automatic retention, like [sabeechen/hassio-google-drive-backup](https://github.com/sabeechen/hassio-google-drive-backup). |

All three repos are private under `github.com/sandymac`. They need PHP 8.3+ and Grav 2.0.23+ and use no Composer dependencies. The conventions come from `grav-plugin-mcp-server`: an spl autoload fallback, a bare-PHP `tests/smoke.php`, PHPStan level 6 against `.gravtest/grav-admin`, yamllint, GitHub Actions CI, and the `VERSION` constant matching `blueprints.yaml`.

## 1. Decisions (settled; don't reopen)

- **Library name.** It stays `gdrive`, because the scope is Drive and related content only. Inside it, the auth and transport code is written so any Google API could use it, so a Docs or Sheets client could be added later without renaming.
- **Fresh git history for the library.** It doesn't use `git filter-repo`. Its first commit message names the gallery commit the code was extracted from.
- **The existing repo is renamed.** `sandymac/grav-plugin-gdrive` becomes `sandymac/grav-plugin-gdrive-images` and keeps the gallery history. A new, empty `sandymac/grav-plugin-gdrive` then takes the old name. That ends GitHub's redirect from the old URL, which is intended.
- **Two kinds of credential.** Service accounts are supported, and so are **OAuth user accounts**, so personal Gmail users can use the plugins too.
- **Bring your own OAuth client.** Each site creates its own Google Cloud "Web application" client, and there's no hosted relay. The auth and token URLs stay configurable, so a relay could be added later.
- **The gallery's public behaviour is unchanged.** The `/gdrive` route, `cache://gdrive`, the `gdrive_gallery()` Twig function and the `gdrive-gallery` template all keep their names. Existing URLs and Cloudflare's cached copies stay valid.
- **The library's settings page carries full, friendly setup guides** for both OAuth and service accounts (section 4).

## 2. Findings that shape the design

- **The backup hook.** Grav's `Backups::backup()` fires `onBackupFinished` (`['backup' => $zipPath]`) right after writing the zip, before its own local purge. Scheduled backups run in the CLI scheduler (`Backups::onSchedulerInitialized`). The admin "Backup now" button runs inside a web request.
- **Local retention already exists.** Grav already deletes old *local* backups (`backups.yaml` `purge`, by count, space or age). gdrive-backup only manages the copies on Drive.
- **Backup filenames.** They look like `<profile>--YYYYmmddHHMMSS.zip` (`BACKUP_FILENAME_REGEXZ = #(.*)--(\d*).zip#`, `BACKUP_DATE_FORMAT = YmdHis`).
- **Service accounts have no storage quota.** They can only create files inside a Shared Drive where they're at least Content Manager. Anything with a person's My Drive needs OAuth (or Workspace domain-wide delegation, which is out of scope).
- **Google's scope categories.** `drive.file` is non-sensitive: no verification is needed, but the app only sees files it created. `drive.readonly` and `drive` are restricted: an unverified personal app gets a warning screen and a 100-user cap, which is fine for bring-your-own clients.
- **The 7-day trap.** For an External OAuth client left in "Testing" status, Google makes refresh tokens expire after **7 days**. The guide must say to publish the client to "In production", or to make it "Internal" on Workspace. Separately, Google may also expire a refresh token after about 6 months without use.
- **Service-account key creation may be blocked.** Workspace organisations created since 2024 block it by default (`iam.disableServiceAccountKeyCreation`).
- **Admin2 is the only admin on Grav 2.** sandy.mcarthur.org runs admin2 2.1.24 and api 1.0.41, with no classic admin. Admin2 fires no `onAdmin*` events, and everything goes through the API plugin (`api.*` permissions). Extension points:
  - `display` fields with `markdown: true` and `data-content@: '\Class::method'` show Markdown generated in PHP. The mcp-server plugin uses this on the live site.
  - Custom field types are Custom Elements that a plugin ships at `admin-next/fields/<type>.js`. They're discovered through `GET /custom-fields`, so any plugin's blueprint can use them. The API plugin's own `admin-next/fields/users.js` is a working example.
  - Plugin API endpoints are registered through `onApiRegisterRoutes`.
- **Admin2 signs in with an in-memory bearer token, not a cookie.** So when Google sends the browser back after OAuth sign-in, the request carries **no** admin login. The callback must trust a single-use `state` created on the server, not a session.
- **Live site (as of 2026-09-28):**
  - The backup profile's schedule is **off**, and there's one 22 MB backup from 2026-08-17.
  - Scheduler jobs show `overdue`, with last runs on Sep 5 and Sep 19, so **cron appears not to be firing**.
  - `gdrive-sweep` is missing from the scheduler's job list. That needs looking into.

## 3. `grav-plugin-gdrive`: the shared library

### 3.1 Files

```
gdrive.php                       autoload, service registration, OAuth callback route, API routes, scope collection
classes/Credentials.php          interface: token(array $scopes): string; email(): string
classes/ServiceAccount.php       JWT RS256 via openssl_sign (moved from today's Drive::jwt)
classes/OAuthUser.php            refresh-token exchange, PKCE auth URL, code exchange, revoke
classes/Accounts.php             name → Credentials; reads config + user/data/gdrive/<name>.*.json
classes/Http.php                 curl transport: retries/backoff 429+5xx, 401 → refresh once, errors → DriveException
classes/DriveException.php       status + Google `reason` + troubleshooting anchor
classes/Drive.php                thin Drive v3 client (below)
classes/Setup.php                data-content@ renderers: guides with placeholders, checklist, who-uses-what
admin-next/fields/gdrive-accounts.js   the account manager component
admin-next/fields/gdrive-account.js    account dropdown for other plugins' blueprints
docs/setup/*.md                  the guides (only copy; rendered in admin and linked from README)
permissions.yaml                 api.gdrive.manage
gdrive.yaml / blueprints.yaml    config defaults / tabs + admin form
tests/smoke.php                  JWT, PKCE, transport retry/401 logic, upload request shape, Accounts file parsing
```

### 3.2 Accounts and credentials

```yaml
# user/config/plugins/gdrive.yaml
accounts:
  site:     { type: service_account }
  personal: { type: oauth }
```

- **Files are stored by fixed name** under `user/data/gdrive/`, created with mode 0600:
  - `<name>.sa.json` holds the service-account key;
  - `<name>.client.json` holds the OAuth Web client;
  - `<name>.token.json` holds the refresh token, the granted scopes and the Google email.
- **Secrets never go in config files** and are never returned by any API endpoint.
- **Access tokens are cached** in Grav's cache under a key made from the account, the credential's email and the sorted list of scopes. They're kept for `expires_in - 60` seconds.
- **The public entry point** for other plugins is `Gdrive::drive(string $account, array $scopes): Drive`. It throws `DriveException('scope_not_granted')` with a "reconnect" hint when an OAuth account lacks a scope.

### 3.3 Scopes

- **Dependent plugins declare their needs** by listening for `onGdriveScopes` and adding `['plugin' => slug, 'account' => name, 'scopes' => [...]]`.
- **The Connect button** requests all scopes declared for that account at once. It sends `access_type=offline`, `prompt=consent`, `include_granted_scopes=true` and PKCE (S256).
- **What granted scopes do:** they're stored with the token. The settings page compares them with the declared scopes.
- **Prefer `drive.file`.** gdrive-images needs `drive.readonly`. gdrive-backup needs `drive.file` (a folder it creates itself) or `drive` (a folder you give it).

### 3.4 Drive client: a generic API, not a copy of the Drive API

- **Low level:**
  - `request($method, $path, $query = [], $json = null)` returns the decoded array.
  - `paginate($path, $query)` is a generator that follows `nextPageToken`.
  - `download($id, $dest)` streams the file.
  - `upload($localPath, $parentId, $name, $appProperties = [], $fields = 'id,md5Checksum,size')` opens a resumable session and does **one streamed PUT** with `CURLOPT_INFILE`, so the file never has to fit in memory.
- **Conveniences:**
  - `children($folderId, $mimes, $fields)` is what the gallery uses today, generalised.
  - `findByAppProperty($parentId, $key, $value, $fields)`.
  - `ensureFolder($name, $parentId = 'root')`.
  - `trash($id)`.
  - `about($fields)`.
- **Shared Drives always work.** `supportsAllDrives=true` is sent on every call, and `includeItemsFromAllDrives=true` on every list.
- **Results are Drive's raw JSON arrays**, and callers choose the `fields`. There are no resource objects and no caching in the library; each plugin caches its own way.
- **Testing:** the transport can be swapped for a fake (a public callable test hook), so other plugins' smoke tests can stub Drive.
- `ponytail:` resumable upload restarts from zero on failure; resume from the stored session URI (valid about a week) if backups outgrow about 1 GB or the host drops long uploads.

### 3.5 OAuth flow (security-sensitive; nothing here is simplified away)

1. The admin clicks Connect, which calls `POST /api/v1/gdrive/accounts/{name}/connect` (requires `api.gdrive.manage`).
   - The server creates a random 32-byte `state` and a PKCE verifier.
   - It stores `state → {account, verifier, username, expires: +10 min}` in `user/data/gdrive/oauth-state/` (mode 0600).
   - It returns Google's authorisation URL.
2. Admin2 opens that URL in a popup, and the user consents.
3. Google redirects the browser to the **public** `GET /gdrive-oauth/callback?state&code`. The plugin intercepts it the same way today's proxy route is intercepted (`onPluginsInitialized` compares the path, then `onPagesInitialized` at priority 100000 handles it and exits).
   - The `state` must exist and not be expired, and it's **deleted before use** so it can't be replayed. Anything else gets a 400 and no detail.
   - The plugin exchanges the code using the verifier. It checks the account's email with `about.get`, then saves `<name>.token.json`.
   - The page shows "Connected as x@…, you can close this window" and tells the page that opened the popup to refresh (`postMessage`, restricted to the site's own origin).
4. **Disconnect** revokes the token at `https://oauth2.googleapis.com/revoke` and deletes the token file.
5. **The redirect URI** is `<site rootUrl(true)>/gdrive-oauth/callback`. It's shown in the guide with a copy button, and Google requires HTTPS for it.

### 3.6 API endpoints (Admin2; all require `api.gdrive.manage`)

| Method and path | Purpose |
|---|---|
| `GET /gdrive/accounts` | List accounts: type, Google email, granted and declared scopes, last test result. Never returns secrets. |
| `POST /gdrive/accounts` | Create or update an account, including the JSON credential. The server checks the shape (`type: service_account` with `private_key` and `client_email`, or `web.client_id` with `client_secret` and `redirect_uris`), says which kind was expected if it's wrong, and writes a fixed filename with mode 0600. |
| `DELETE /gdrive/accounts/{name}` | Remove an account (revoking the OAuth token first). |
| `POST /gdrive/accounts/{name}/test` | Real `about.get` (user, storage quota). Returns OK, or the error `reason` plus the Troubleshooting entry to link. |
| `POST /gdrive/accounts/{name}/connect` | Start OAuth as in 3.5. |

Upload goes through our endpoint, not Admin2's generic `/blueprint-upload`, because the file must be validated at this trust boundary.

### 3.7 The settings page (Plugins → Google Drive)

```
[ Start here ] [ Accounts ] [ Service account guide ] [ OAuth guide ] [ Troubleshooting ]
```

**Start here**
- **"Which should I use?"** table:

  | | Service account | OAuth |
  |---|---|---|
  | Works with | Workspace with Shared Drives | Any Gmail or Workspace account |
  | Who owns the files | The Shared Drive | You (counts against your 15 GB) |
  | Unattended | Never expires | Once the client is set to "In production" |
  | Can upload into My Drive | **No** | Yes |
  | Setup | About 5 minutes, plus sharing a folder | About 10 minutes, plus Connect |

- **Recommendation line:** "If you have a Shared Drive, use a service account. Otherwise use OAuth."
- **Live checklist** for each account: credential ✔, token ✔, Drive API ✔, each consuming plugin's folder is visible ✔. Every ✘ links to its Troubleshooting entry.
- **"Who uses what" table**, built from `onGdriveScopes`: plugin · account · scopes · granted?

**Accounts:** the `gdrive-accounts` component.
- **Rows** show the name, the type, "connected as …", the scopes and the last test result.
- **Row actions:** Test, Connect or Reconnect, Disconnect and Remove.
- **Add account:** pick a type, then upload the JSON file or paste it.

**Service account guide:** numbered steps with deep links into the Google Cloud console.
1. Create or pick a project, then enable the Drive API.
2. Create the service account. It needs **no IAM roles**, and the guide says so.
3. Create a JSON key, with the organisation-policy gotcha and its fix.
4. Upload the key here.
5. Share the folder or Shared Drive with the service account's email. The real address is shown with a copy button once the key is uploaded. Viewer is enough for galleries; backups need Content Manager.
6. Click Test.

**OAuth guide:**
1. Create a project and enable the Drive API.
2. In Google Auth Platform → Audience, choose **Internal** on Workspace, or **External → Publish app** for Gmail. The 7-day trap gets a call-out box.
3. In Data Access, add the scopes. The list comes from the plugins actually installed.
4. In Clients, create a Web application client and paste the exact redirect URI (copy button).
5. Download the client JSON and upload it here.
6. Click Connect.
7. The guide explains the "Google hasn't verified this app" screen (Advanced → Go to…) and why that's safe for your own client.

**Troubleshooting:** a table of error, cause and fix, keyed by Google's error codes so the Test button can link straight to a row:
- `storageQuotaExceeded`: a service account wrote into My Drive. Use a Shared Drive or switch to OAuth.
- `notFound`: the folder isn't shared with the service account.
- `accessNotConfigured`: the Drive API isn't enabled; the link uses the project number from the error.
- `redirect_uri_mismatch`: shows the expected URI.
- `invalid_grant`: the token was revoked, the client is in Testing (7 days), or there were about 6 months without use. Reconnect.
- `scope_not_granted` / insufficient permissions: reconnect to grant the new plugin's scopes.
- `rateLimitExceeded`: the plugin already backs off; if it keeps happening, reduce how often the plugin syncs.
- Organisation policy blocking key creation.

**Where the guide text lives**
- It lives only in `docs/setup/*.md`, with `{{redirect_uri}}`, `{{sa_email}}`, `{{scopes}}` and `{{site}}` placeholders.
- `Setup::guide($name)` renders the files through `data-content@`, and the README links to the same files.
- Dependent plugins don't repeat it. Their pages show the `gdrive-account` dropdown, a line such as "Needs `drive.file` · Set up Google Drive access →", and their own folder-sharing tip.

### 3.8 Public API and versioning

- The README has a "Public API" section: `Gdrive::drive()`, `Drive`'s public methods, `DriveException`, `onGdriveScopes`, the `gdrive-account` field and the test transport hook. Everything else is `@internal`.
- The library follows semver, and dependent plugins declare `{ name: gdrive, version: '>=x.y' }`.
- **If the library is missing or disabled,** dependent plugins check `class_exists(\Grav\Plugin\Gdrive\Drive::class)`, log the problem once and do nothing.

## 4. `grav-plugin-gdrive-images` (v0.2.0)

- **Code changes:**
  - Delete `classes/Drive.php`.
  - Move `Gallery`, `Proxy` and `Watermark` into `Grav\Plugin\GdriveImages`, with `gdrive-images.php` as the plugin class `GdriveImagesPlugin`.
  - Get the client from `Gdrive::drive($config['account'], [Drive readonly scope])`, and pass the current gallery listing fields through `children()`.
- **Config** moves to `plugins.gdrive-images`: add `account` (default `site`) and remove `service_account_file`. Everything else stays: `route`, `folders`, `originals_folders`, `allowed_mime`, `listing_ttl`, `cache_dir: cache://gdrive`, `watermark.*` and `sweep.*`.
- **Blueprint:** add a dependency on `gdrive`, the `gdrive-account` field and the "Set up Google Drive access →" line. Register `onGdriveScopes` for `drive.readonly`.
- **Keep every trust-boundary check unchanged:** known-ids authz, the MIME allow-list, the URL md5 match against the bytes, and 404 on refusal.
- **Housekeeping:**
  - Move the JWT smoke check to the library.
  - Update the images smoke test for the new namespace and a fake transport.
  - Find out why `gdrive-sweep` is missing from the scheduler list.
  - Update CLAUDE.md, HANDOFF.md, README and CHANGELOG, plus the issue-tracker docs (repo name).

## 5. `grav-plugin-gdrive-backup` (v0.1.0)

### 5.1 Config

```yaml
enabled: true
account: personal            # any gdrive account
folder: ''                   # empty → plugin creates "Grav backups (<host>)" in the account's My Drive (drive.file scope; OAuth only)
                             # set  → an existing folder ID (needs `drive` scope); required for service accounts, and must be in a Shared Drive
keep: 10                     # newest N kept on Drive
generational: { days: 0, weeks: 0, months: 0, years: 0 }   # kept in addition to the newest N, as in the HA add-on
sync: { enabled: true, at: '15 * * * *' }                   # catch-up/retention job
```

The `onGdriveScopes` declaration follows `folder`: `drive.file` when it's empty, `drive` when it's set. The settings page warns when a service account is paired with an empty `folder`.

### 5.2 Flow

- **Scheduled backup:** `onBackupFinished` in the CLI (`PHP_SAPI === 'cli'`) runs `sync()` straight away.
- **Admin "Backup now":** nothing runs in that web request, so the admin isn't made to wait for an upload. The hourly job picks it up.
- **`sync()`:**
  1. Lists local backups (`Backups::getAvailableBackups(true)`) and the Drive files in the folder tagged `appProperties.grav_backup=1`.
  2. Works out retention over **both lists together**, by the timestamp in each filename. It uploads only the local backups that would be kept. Without this, a backup removed from Drive but still on disk would be uploaded again on every run.
  3. Uploads each one with its tags (`grav_backup=1`, `site=<host>`). It then compares the returned `md5Checksum` with `md5_file()`; on a mismatch it trashes the upload and logs an error.
  4. Applies retention on Drive:
     - Only files tagged by the plugin are candidates, so nothing else in the folder is ever touched.
     - **Starred files are never deleted**, which covers the HA add-on's "never delete" without any UI.
     - Old copies are **trashed** (a 30-day undo), never permanently deleted.
  5. Writes the result to `user/data/gdrive-backup/status.json`, to the job's output log, and errors to `grav.log`.
- A single lock file stops the hourly job and the after-backup sync from overlapping.

### 5.3 Checks and scope

- **`tests/smoke.php` covers:**
  - retention: newest N plus generational buckets, with starred files exempt;
  - the upload-only-what-would-be-kept rule;
  - filename-to-timestamp parsing;
  - the lock.
- **The settings page shows** the last sync result from `status.json` (a `display` field) and a sharing tip ("share as **Content Manager**").

**HA add-on features left out:**

| Feature | Add it when |
|---|---|
| Restore from Drive | You want it; downloading from Drive by hand works meanwhile |
| A dashboard | You want sync state without opening the settings page or logs |
| Failure email | You're relying on backups (the Email plugin is already installed on the site) |
| Resuming an interrupted upload on the next run | See the `ponytail:` note in 3.4 |
| Several sites sharing one folder | You back up more than one site to the same folder (the `site` tag is already written, so it's a small change) |

## 6. Execution order (each step starts only on the owner's go-ahead)

1. **Move and rename repos.**
   1. `gh repo rename grav-plugin-gdrive-images -R sandymac/grav-plugin-gdrive`.
   2. `git clone C:\dev\grav-plugin-gdrive C:\dev\grav-plugin-gdrive-images`, then set `origin` to the new GitHub URL.
   3. Copy the untracked `CLAUDE.local.md`, `.gravtest/` and `.claude/`. **Don't** copy `C:\dev\grav-plugin-gdrive\PLAN.md`; it's the library's plan (gdrive-images has its own).
   4. Check the clone matches: `git status`, the log, and a smoke test run.
   5. Clear `C:\dev\grav-plugin-gdrive` except `PLAN.md`, `CLAUDE.local.md` and `.gravtest/`. Run `git init` and create the private `sandymac/grav-plugin-gdrive`.
   6. Run `git init` in `C:\dev\grav-plugin-gdrive-backup` and create its private repo.
2. **Admin2 prototype.** Build a minimal `admin-next/fields/gdrive-accounts.js` to confirm what data a custom field receives and how it saves. Also check how markdown tables look on a phone.
3. **Library core:** `Credentials`, `ServiceAccount`, `Http`, `Drive`, `Accounts` and the smoke test. Then gdrive-images uses it, and its smoke test and PHPStan pass.
4. **OAuth:** `OAuthUser`, the endpoints, the callback, the state store and revoke.
5. **Settings page:** the guides, `Setup` renderers, the components and the Troubleshooting mapping.
6. **gdrive-backup**, including retention and its smoke tests.
7. **Switch over sandy.mcarthur.org** (section 7).

Model tiers (from the global CLAUDE.md):
- **Top model:** steps 1 and 7, plus the OAuth security design and review.
- **Opus:** steps 3, 4 and 6 (protocol and auth work).
- **Sonnet:** the gdrive-images refactor and the Admin2 components, which follow an established pattern.
- **Haiku:** docs, CHANGELOGs and CLAUDE.md edits.

## 7. Switching over sandy.mcarthur.org

1. Check that `user/data/gdrive/*.json` returns **403** over HTTP (Apache `.htaccess`). Fix it before any credential is renamed or uploaded.
2. Deploy `gdrive` and `gdrive-images` using the `git archive | ssh` pattern in CLAUDE.local.md.
3. Rename `user/data/gdrive/service-account.json` to `site.sa.json`.
4. Split `user/config/plugins/gdrive.yaml`:
   - `gdrive.yaml` gets `accounts: {site: {type: service_account}}`;
   - `gdrive-images.yaml` gets the rest, plus `account: site`.
5. Run `bin/grav clearcache`, then check `/photos` and a cached image URL (both 200, with the same ETag).
6. Get cron firing again, then enable the backup profile's schedule.
7. **Backups**, either way:
   - **OAuth:** set up the `personal` account through the guide. This also tests the guide.
   - **Service account:** create a folder in a Shared Drive and share it as Content Manager.
8. Deploy gdrive-backup, run one backup by hand, and check that the upload, the md5 check and retention all work.

## 8. Still open

- Why `gdrive-sweep` is missing from the scheduler's job list.
- Whether the host's `.htaccess` blocks `.json` under `user/data`.
- The Admin2 custom-field contract (step 2).
- Whether picking a folder with Google Picker grants `drive.file` access to its contents. This only matters if gdrive-images should ever work without `drive.readonly`.
