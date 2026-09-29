# v0.1.11
## 2026-09-29

1. [](#improved)
    * The **Drive folder** setting shows the folder ID it parsed and warns before you save when the account needs a Reconnect for full Drive access (or is not connected).

# v0.1.10
## 2026-09-29

1. [](#improved)
    * In **Last sync**, the word "folder" links to the Drive folder; the ID next to it stays plain so it copies easily.

# v0.1.9
## 2026-09-29

1. [](#security)
    * Page editors could reach the folder check through Admin2's `/data/resolve` (open to `api.pages.read`), reading the account's email and folder names and spending the site's Google token. `Status::folderCheck` is no longer on the dynamic-callable allowlist; the settings page still shows it.
    * The scheduled-profiles line warns when backups include the site's Google sign-in (`user/data/gdrive/`: refresh token, client secret, service-account key), which Grav's default profile archives, and says how to exclude it.
1. [](#bugfix)
    * A service account with a configured folder in My Drive showed ✔, then every sync failed with `storageQuotaExceeded`. The sync now stops before uploading and the folder check shows ✘, both saying to use a Shared Drive folder or an OAuth account.
    * An upload whose response had no `md5Checksum` was trashed as a mismatch. The file is fetched once for its checksum; if Drive still reports none, the upload is kept and a warning says it couldn't be verified.
    * A backup already on Drive under the same name but with a different checksum counted as a good copy. It's uploaded again and verified, and only then is the bad copy trashed; a failed re-upload holds back retention like any failed upload.
    * Recreating a trashed configured folder reused any live folder with the same name in its parent. It now always creates a new folder, with " (2)", (3)… added to the name if it is taken.
1. [](#improved)
    * When the configured folder comes back after a replacement was made, the sync uses it again and warns, with a link, while the replacement still holds backups; the folder check shows the same ⚠. The replacement is forgotten once it's trashed, gone or empty of backups.
    * Clearer help for **Keep newest** and **Sync schedule**.

# v0.1.8
## 2026-09-29

1. [](#new)
    * A folder check line under **Drive folder** says what the next sync will do with the saved settings: the folder it will create, or the folder's name and Shared Drive and whether the account can add and remove backups there (✔), plus a plain fix when it can't (view-only, **Content manager** needed for retention, trashed, not shared, not connected, missing scope). It replaces the service-account warning above **Last sync**. Checked on page load with a 5-second limit and cached for 5 minutes. Needs gdrive 0.1.11.

# v0.1.7
## 2026-09-29

1. [](#bugfix)
    * The scheduled-profiles line showed a cron schedule with its `*`s stripped (`0 3   `); schedules keep them now.

# v0.1.6
## 2026-09-29

1. [](#improved)
    * A line under **Last sync** says Grav itself makes the backups, links to **Configuration → Backups**, and says how many backup profiles are scheduled (and when).

# v0.1.5
## 2026-09-29

1. [](#new)
    * **Recreate a missing folder** (`recreate_folder`, on by default): if the backup folder is in Drive's trash or gone, the sync creates a new one and keeps backing up, with a **Warning** on the settings page and in `grav.log`. A trashed configured folder gets a same-name sibling; one Drive can't find falls back to "Grav backups (<site>)" in My Drive (OAuth accounts only). The old folder is never restored or deleted. Off: the run stops and says why.
1. [](#bugfix)
    * Clearing the **Drive folder** setting reused the folder that had been configured before, instead of the plugin's own folder. The plugin's own folder and any replacement are now remembered separately in `status.json`; an old status is migrated.
    * A configured folder in the trash was never noticed: uploads landed inside the trashed folder and the run said OK.
    * `blueprints.yaml` had a YAML syntax error (`help:"…` with no space) in the Drive folder field.
    * Requires gdrive 0.1.8, where an account granted full `drive` also satisfies blank-folder mode's `drive.file`.

# v0.1.4
## 2026-09-29

1. [](#improved)
    * The Drive folder help names the folder the plugin will create, with this site's real host name in it, from the same code the sync uses.

# v0.1.3
## 2026-09-29

1. [](#improved)
    * The folder setting (now **Drive folder**) accepts a folder link pasted from Drive, such as `https://drive.google.com/drive/folders/<id>?usp=sharing` or `.../open?id=<id>`, as well as a bare ID.
1. [](#bugfix)
    * Its help text showed "Grav backups ()": Admin2 stripped the `<site>` placeholder as an HTML tag. It now says the folder is named after the site's host name, with an example.

# v0.1.2
## 2026-09-29

1. [](#improved)
    * Settings help points at the renamed **Google Drive Auth** plugin.

# v0.1.1
## 2026-09-29

1. [](#improved)
    * Settings help points at the renamed **Google Drive Library** plugin.

# v0.1.0
## 2026-09-28

1. [](#new)
    * Initial release: uploads Grav backups to Google Drive through the `gdrive` library plugin, with retention on Drive modelled on the Home Assistant Google Drive Backup add-on.
    * Syncs right after a scheduled (CLI) backup; backups made with the admin's "Backup now" are picked up by the scheduler job `gdrive-backup-sync` (default `15 * * * *`, output in `logs/gdrive-backup.out`).
    * Retention over local and Drive backups together, by the timestamp in the filename: the newest `keep` plus the newest backup in each of the last `generational.days` days, `weeks` ISO weeks, `months` months and `years` years. Only local backups that survive are uploaded, so a backup rotated off Drive is never re-uploaded.
    * Only files tagged `appProperties grav_backup=1` are ever touched; starred files are never trashed; old copies go to Drive's trash, never a permanent delete. Each upload's `md5Checksum` is checked against the local file, and a mismatch is trashed and reported; any failed upload holds back retention for that run.
    * Folder mode: empty `folder` creates "Grav backups (<site>)" in the account's My Drive with the `drive.file` scope and remembers its id; a set `folder` uses that folder with the full `drive` scope (required for service accounts, in a Shared Drive).
    * A non-blocking `flock` on `user/data/gdrive-backup/sync.lock` stops overlapping runs. Results go to `user/data/gdrive-backup/status.json`, the settings page's "Last sync" field, and `grav.log` for errors.
    * `tests/smoke.php`: retention buckets across day/week/month/year boundaries, filename parsing, upload-only-survivors, starred/untagged/trash-only invariants, md5 mismatch, folder choice and the lock, against the library's real `Drive` client over a fake transport; plus the `VERSION` drift check.
