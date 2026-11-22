# Deploy Blue Draft Team on a Hostinger VPS

This guide uses Ubuntu 24.04, Nginx, PHP 8.3-FPM, Node.js 22.12+, Composer 2 and PostgreSQL 16. Laravel serves the application and Vite compiles the React assets at deploy time. The proposed hostname is `team.bluedraft.cc`. Use the actual hostname in both the Nginx configuration and `APP_URL` before enabling HTTPS.

Hostinger supports PostgreSQL on VPS plans. [Hostinger database support](https://www.hostinger.com/support/which-databases-and-data-tools-are-supported-at-hostinger/)

## Prepare the server

1. Provision the VPS and set up an SSH deploy account. Install Nginx, PHP-FPM and CLI with `bcmath`, `curl`, `gd`, `intl`, `mbstring`, `pgsql`, `xml` and `zip`, plus PostgreSQL, Git, Composer, Node.js and Certbot. The PHP CLI also needs `pcntl` for queue timeouts. Composer checks the locked requirements during deploy.
2. Create a PostgreSQL login and a database owned by that login. Use a generated password and keep PostgreSQL bound to localhost. Use a distinct database for staging. Configure the firewall for SSH, HTTP and HTTPS.
3. Clone the repository to `/var/www/blue-draft-team` as the deploy account. Configure the checkout so PHP-FPM and the worker can read the app and write only to `storage/` and `bootstrap/cache/`. Give those writable directories the `www-data` group, group write permission and the setgid bit. The deploy account needs access to that group and must be able to update the Git checkout. Keep the project and configuration files outside the web root.
4. Copy `deploy/hostinger/production.env.example` to the root `.env` on the server. Restrict it to the deploy account and the `www-data` group. Fill in the database password, mail settings and actual HTTPS URL. Configure PHP upload size alongside the Nginx request limit. Install dependencies and generate `APP_KEY` once when initializing a new environment.

```bash
cd /var/www/blue-draft-team
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php8.3 artisan key:generate --force
bash deploy/hostinger/deploy.sh
php8.3 artisan logistics:create-worker
```

Choose **Super Admin** in the account creation command. Preserve the production `APP_KEY` across releases. Store release credentials on the VPS. The production seeder initializes roles and permissions. The SQLite demo database, demo accounts and local uploads are development data. Use a staging environment for demo data with its own database and storage.

## Serve the application

1. Point DNS for the chosen hostname to the VPS. Copy `deploy/hostinger/nginx.conf` to a site configuration and check the hostname, web root and PHP-FPM socket. Enable the site and run `sudo nginx -t` before reloading Nginx.
2. Issue a TLS certificate and enable HTTP-to-HTTPS redirection with Certbot, for example `sudo certbot --nginx -d team.bluedraft.cc`. If DNS has an AAAA record, confirm it points to this VPS before requesting a certificate.
3. Install the queue unit and the scheduler. Confirm the web server uses the same environment as the workers and scheduler.

```bash
sudo install -m 644 deploy/hostinger/blue-draft-worker@.service /etc/systemd/system/
sudo install -m 644 deploy/hostinger/blue-draft-scheduler /etc/cron.d/
sudo systemctl daemon-reload
attendance_worker=$(systemd-escape --template=blue-draft-worker@.service 'default,attendance')
sudo systemctl enable --now "$attendance_worker" blue-draft-worker@reports.service
systemctl status "$attendance_worker" blue-draft-worker@reports.service
php8.3 artisan schedule:list
```

The workers run with a 120-second timeout and the database is configured to retry released jobs after 180 seconds. The scheduler runs every minute and dispatches the daily cleanup of expired exports. Inspect worker logs using `journalctl -u 'blue-draft-worker@*'` and scheduler logs in `storage/logs/scheduler.log`.

## Storage and backups

The template uses private local storage for worker documents, attachments, photos and exports. Download URLs are signed. Back up PostgreSQL, the production `.env` and private files from `storage/app/private/` to storage outside the VPS. Test a restore in staging. To use private S3, set `JOB_FILES_DRIVER=s3` and configure the bucket, region and credentials in `.env`.

## Verify before going live

- Check `/up`, `/login`, `/admin/login` and compiled assets over HTTPS.
- Create a site, assign a supervisor and a worker, and check the site in the worker portal. Verify address suggestions and the saved geofence.
- On a real phone inside an assigned site, sign in and test **Clock in**, **Clock out** and **Resume work**. Test location denial and a punch outside the configured geofence.
- Upload and open a progress photo. Export a report and verify it downloads. Check outgoing mail and failed jobs. Confirm the daily cleanup appears in `schedule:list`.

## Deploy an update

Take a fresh backup before applying migrations. Fetch and inspect the target revision before running the deployment script.

```bash
cd /var/www/blue-draft-team
git fetch origin main
git log --oneline HEAD..origin/main
php8.3 artisan down --retry=60
git merge --ff-only origin/main
bash deploy/hostinger/deploy.sh
```

The script installs locked packages, builds assets, runs migrations and the production seeder, refreshes Laravel caches, signals queue workers to restart and brings the application back up. If the script fails during an update, resolve the failure before bringing the application online. Use the inspected commit and the database backup for recovery if the update changed the schema.
