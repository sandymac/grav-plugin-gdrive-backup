# Google Drive Backup for Grav

Uploads your Grav backups to Google Drive after they're made and keeps a
rotating set there: the newest N, plus optional daily, weekly, monthly and
yearly generations. It's modelled on the Home Assistant add-on
[hassio-google-drive-backup](https://github.com/sabeechen/hassio-google-drive-backup).

Requires PHP 8.3+, Grav 2.0.23+ and the
[gdrive](https://github.com/sandymac/grav-plugin-gdrive) library plugin
(0.1.8+), which holds the Google accounts. No Composer dependencies.

## What it does

- **After a scheduled backup** (which runs in Grav's CLI scheduler) it syncs
  straight away.
- **After "Backup now" in the admin** it does nothing in that web request, so
  you don't wait for an upload. The hourly job `gdrive-backup-sync` (default
  `15 * * * *`) picks it up.
- **Each sync** lists the local backups and the backups on Drive, works out
  retention over both together, uploads the local ones that should be kept
  and aren't on Drive yet, checks each upload's md5 against the local file,
  then moves Drive copies that are no longer kept to the trash.
- The result lands in `user/data/gdrive-backup/status.json`, on the plugin's
  settings page ("Last sync"), in `logs/gdrive-backup.out`, and errors in
  `grav.log`.

Grav's own `backups.yaml` purge still manages the **local** copies; this
plugin only manages Drive.

### Compared with the HA add-on

| | HA add-on | gdrive-backup |
|---|---|---|
| Newest N on Drive | yes | `keep` |
| Generational days/weeks/months/years | yes | `generational.*` |
| "Never delete" a backup | a toggle in its UI | star the file in Drive |
| Deletes old copies | permanently | Drive's trash (30-day undo) |
| Restore from Drive | yes | download from Drive by hand, then restore as usual |
| Dashboard, failure notifications | yes | not yet; the settings page shows the last sync |
| Makes the backups | yes | no, Grav's backup profiles do |

## Setup

1. Install and enable **gdrive** and **gdrive-backup** (`user/plugins/gdrive`
   and `user/plugins/gdrive-backup`).
2. On the **Google Drive Auth** plugin's settings page, create an account and
   follow its guide. The guide lists the scope this plugin needs.
3. On this plugin's page, choose the account and a folder mode:
   - **Drive folder empty** (OAuth accounts only): the plugin creates
     "Grav backups (&lt;site&gt;)" in that account's My Drive and remembers it,
     so you can rename or move it. It needs only `drive.file`, which sees only
     files this plugin created. `<site>` is the host of `system.custom_base_url`,
     or the server's hostname if that's empty.
   - **Drive folder set**: an existing folder, pasted as its Drive link or its ID, which needs full `drive` access.
     **Service accounts must use this**, with a folder in a **Shared Drive**
     shared with the service account as **Content Manager** (service accounts
     have no storage of their own).
   Changing the mode changes the scope, so reconnect OAuth accounts afterwards.

### If the folder is trashed or gone

The folder the plugin created (blank mode), and any replacement it made, are
remembered in `user/data/gdrive-backup/status.json` (`auto_folder_id`,
`replacement`); `folder_id` there is just the folder the last run used.
Clearing the **Drive folder** setting never reuses the folder that was
configured before: the plugin goes back to its own folder.

Each run checks the folder first. With **Recreate a missing folder** on (the
default, `recreate_folder: true`):

- **Blank mode**, own folder in the trash or gone: a new "Grav backups
  (&lt;site&gt;)" is created in My Drive and remembered.
- **Folder set**, and it's in the trash: a folder with the same name is
  created next to it (in its first parent, which works in Shared Drives) and
  used until you change the setting.
- **Folder set**, and Drive can't find it (deleted, or no longer shared):
  OAuth accounts fall back to "Grav backups (&lt;site&gt;)" in My Drive.
  Service accounts have no My Drive, so the run stops.

The run still backs up, and "Last sync" shows a **Warning** (also in
`grav.log`) until you fix the setting. The old folder is never restored,
untrashed or deleted. With the option off, the run stops and reports the
problem instead; nothing is uploaded and nothing is trashed.
4. In **Tools → Backups**, turn on the backup profile's schedule.
5. Make sure cron runs Grav's scheduler every minute:
   `* * * * * cd /path/to/grav && bin/grav scheduler 1>> /dev/null 2>&1`.
   Without it neither the backups nor the sync job run.

## How retention works

- Every backup is dated by the timestamp in its filename
  (`<profile>--YYYYmmddHHMMSS.zip`, in the server's timezone).
- **Kept:** the newest `keep`, plus, for each of `generational.days`,
  `weeks`, `months` and `years` set above 0, the newest backup in each of the
  last N calendar days / ISO weeks (Monday to Sunday) / calendar months /
  calendar years, counting back from now with the current one included. An
  empty period keeps nothing and isn't made up from an older one.
- Retention is worked out over local and Drive backups **together**, so a
  backup that has already rotated off Drive isn't uploaded again just because
  it's still on disk.
- **Only files the plugin uploaded** (tagged `grav_backup=1` in their
  `appProperties`) are ever considered. Anything else in the folder is left
  alone.
- **Starred files are never trashed**, and don't count towards `keep`.
- Old copies are **moved to Drive's trash**, never deleted permanently, so
  you have 30 days to change your mind.
- If an upload fails, or its md5 doesn't match (the bad upload is trashed),
  nothing else is trashed that run.
- All backup profiles share one retention count.

## Restoring

Download the zip from Drive (it's a normal Grav backup) and unzip it over a
Grav install, as with any Grav backup.

## Development

```
php tests/smoke.php                          # GDRIVE_LIB=path/to/grav-plugin-gdrive, default ../grav-plugin-gdrive
phpstan analyse --memory-limit=1G           # needs .gravtest/grav-admin and ../grav-plugin-gdrive
```

## License

MIT
