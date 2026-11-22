# Deploy Blue Draft Team with Hostinger hPanel

Use this guide for the MariaDB database created under **MySQL Databases** in hPanel. The production domain is `https://bluedraft.site`. The VPS guide covers the PostgreSQL setup.

## Prepare the website

1. Set PHP 8.3 or newer for `bluedraft.site`, verify that the matching SSH PHP executable has `pdo_mysql` and enable SSL in hPanel. Check PHP upload and POST limits for progress photos and attachments.
2. Enable SSH and check the domain directory in hPanel. Clone the repository as `~/domains/bluedraft.site/blue-draft-team`, next to `public_html`. Keep the database credentials in the private checkout. Back up any existing website in `public_html` before publishing.
3. Copy `deploy/hostinger/hpanel/production.env.example` to the checkout’s `.env` and restrict its permissions to `600`. Enter the full database name and username from hPanel, including their account prefixes. Set the password on the server and fill in the SMTP settings.
4. In a checkout of the same revision, run `npm ci` and `npm run build` using Node.js 22.12 or newer. Copy the resulting `public/build` directory into the private checkout on Hostinger. The domain runs Laravel on PHP, with Vite assets served as static files.

## Initialize Laravel

Run as the hPanel SSH user from the private checkout. Use the PHP and Composer executables available for your account. With `composer2` as the executable and `php` pointing to the matching PHP CLI, run:

```bash
cd ~/domains/bluedraft.site/blue-draft-team
composer2 install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan key:generate --force
bash deploy/hostinger/hpanel/deploy.sh
php artisan logistics:create-worker
```

Choose **Super Admin**. Generate `APP_KEY` once for a new installation and keep it for future releases. The script installs dependencies, runs migrations and seeds roles, caches Laravel configuration, and copies public assets and a small entry point into `public_html`. The local SQLite database and demo data are separate from this setup.

## Configure scheduled work

In hPanel, add two cron jobs that run every minute. Replace the example account path and PHP executable with the values confirmed for your account.

```bash
cd /home/HOSTINGER_USER/domains/bluedraft.site/blue-draft-team && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
cd /home/HOSTINGER_USER/domains/bluedraft.site/blue-draft-team && flock -n storage/framework/worker.lock /usr/bin/php artisan queue:work database --queue=attendance,default,reports --stop-when-empty --max-time=50 --timeout=120 --tries=3 >> storage/logs/worker.log 2>&1
```

The lock keeps cron runs from starting a second worker while one is active. Confirm that `flock` and one-minute cron are available on your account. The database queue’s retry interval is 180 seconds. Check for failed jobs and confirm that queued mail, attendance calculations and large reports complete on your hosting plan.

## Verify and update

Check `/up`, `/login` and `/admin/login` over HTTPS. Sign in on a phone and test clock-in, clock-out and switching sites from inside an assigned geofence. Verify photo downloads, PDF and Excel exports, mail, and backup of the database and `storage/app/private`.

For an update, back up the database, take the site into maintenance mode with `php artisan down --retry=60`, fast-forward the checkout to the reviewed commit and upload the assets built from that commit. Run `bash deploy/hostinger/hpanel/deploy.sh` again. The script takes the site out of maintenance mode after a successful deploy.
