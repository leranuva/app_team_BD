# Despliegue en Laravel Forge

Forge es el equivalente si ya hay un VPS. Las variables, el primer Super Admin y la verificación posterior al deploy son los de [deploy-laravel-cloud.md](deploy-laravel-cloud.md). Aquí solo cambia cómo se instala el servidor.

No uses esta guía para Laravel Cloud. Cloud no lleva nginx, Certbot ni Supervisor.

## Servidor

Ubuntu 22.04 o 24.04, PHP 8.3, nginx, PostgreSQL 16 y Redis. El sitio apunta a `public/` y el directorio es la raíz del repositorio. **[pendiente verificación en cuenta real]**

PHP necesita `pdo_pgsql`, `pgsql`, `mbstring`, `xml`, `gd`, `zip`, `bcmath`, `exif` e `intl`.

## Workers

En Supervisor, dos programas, `autorestart=true`:

```ini
command=php /home/forge/app/artisan queue:work --queue=default,attendance --tries=3 --timeout=60
numprocs=1

command=php /home/forge/app/artisan queue:work --queue=reports --tries=3 --timeout=120
numprocs=1
```

Sustituye la ruta por la del sitio. `reports` va aparte porque un Excel de más de 1000 filas puede tardar más que el recálculo de horas.

## Scheduler

Cron del usuario del sitio:

```text
* * * * * cd /home/forge/app && php artisan schedule:run >> /dev/null 2>&1
```

`reports:cleanup` ya llama a `onOneServer()`. Redis tiene que ser `CACHE_STORE` para que el candado funcione.

## Backups

`pg_dump` diario hacia el mismo bucket privado, en un prefijo distinto de `exports/` y de las fotos. Retén varias copias. Restaurar es crear una base vacía, cargar el dump y apuntar `DB_*` a ella. El runbook tiene la secuencia.

## SSL

Certbot con renovación automática. `APP_URL` en `https` y `SESSION_SECURE_COOKIE=true`.
