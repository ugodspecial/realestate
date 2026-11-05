# Upgrading Cedar Tranquility

Every code change ships with an upgrade path. Schema changes travel as
versioned migrations in `database/migrations/`; `bin/upgrade.php` backs up
the database and applies whatever is pending. Fresh installs run through the
same migrator, so install and upgrade can never drift apart.

## How it works

- Migration files are named `NNN_description.php` (`001_baseline.php`,
  `002_add_view_counts.php`, …) and return
  `['description' => …, 'up' => function (PDO $pdo, string $driver) {…}]`.
- Applied migrations are recorded in the `migrations` table with a checksum.
  Re-running an upgrade is always safe: applied migrations are skipped, and
  editing an applied migration produces a warning instead of re-running it.
- There are no down-migrations by design. Rollback means restoring the
  automatic pre-upgrade backup (plus the previous code) — see below.

## Upgrading a test site

```sh
cd /path/to/site
git pull
php bin/upgrade.php --dry-run   # show what would change
php bin/upgrade.php              # back up, then apply pending migrations
php tests/smoke.php              # verify data and behaviour survived
```

## Upgrading production (cPanel / shared hosting)

1. Pick a low-traffic window and make sure nobody is editing content.
2. Deploy the new code (`git pull` or your usual upload), keeping
   `config.local.php`, `storage/`, and `public/uploads/` untouched.
3. Run the upgrade over SSH:
   ```sh
   php bin/upgrade.php
   ```
   It refuses to run on an empty database (that is what `bin/install.php`
   is for), writes a timestamped backup plus a `.json` manifest to
   `storage/backups/`, then applies each pending migration in order.
4. Verify: load the homepage, sign in to `/admin`, open a property page,
   and confirm the version in the admin sidebar matches the release.
5. Keep the backup until the release has soaked for a few days.

`--no-backup` exists for CI and throwaway copies only. Never use it on
production. If `mysqldump` is missing or `exec` is disabled, take a backup
from your hosting panel first, then re-run with `--no-backup`.

## Rollback

1. Restore the pre-upgrade backup:
   - SQLite: stop the site, copy the backup file back over
     `storage/cedar.sqlite` (delete any `-wal`/`-shm` sidecars first).
   - MySQL: `mysql -u USER -p DB_NAME < storage/backups/db-….sql`
2. Check out the previous code release.
3. Reload the site and confirm the admin sidebar shows the old version.

## Writing a migration

1. Copy the next free number: `database/migrations/002_what_changed.php`.
2. Return the description plus an `up` closure. Keep it additive
   (`ADD COLUMN`, new tables/indexes) — never drop or rename in a way that
   loses data. Handle both drivers when the SQL differs:
   ```php
   <?php
   return [
       'description' => 'Track property view counts',
       'up' => static function (PDO $pdo, string $driver): void {
           $pdo->exec('ALTER TABLE properties ADD COLUMN view_count INTEGER NOT NULL DEFAULT 0');
       },
   ];
   ```
3. Test it the way CI does: install an older copy, deploy your branch, run
   `php bin/upgrade.php`, and confirm the app still works.
4. Never edit a migration after it has run anywhere shared. Fix forward
   with a new migration.
5. Add a `CHANGELOG.md` entry under the new version and bump `APP_VERSION`
   in `app/bootstrap.php`.

## Troubleshooting

- **Upgrade aborts mid-run:** fix the cause, then re-run. The failed
  migration was not recorded, so it retries cleanly; the backup from the
  first attempt is still valid. (Note: MySQL commits DDL immediately, so a
  failed multi-statement migration can leave partial schema behind — that
  is exactly what the backup is for.)
- **Checksum warning:** an applied migration file changed. Revert the edit
  or ship the change as a new migration.
- **`mysqldump was not found`:** install a MySQL client package, or back up
  via your hosting panel and re-run with `--no-backup`.
- **Slow/large tables on shared hosting:** run the upgrade from SSH rather
  than a web-triggered process so PHP time limits do not apply.
