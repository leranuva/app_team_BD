# Despliegue en Laravel Cloud

Laravel Cloud es el camino principal. El panel crea Postgres, Redis, el bucket y el certificado. Esta guía no despliega: no hay cuenta ni token en el repositorio. Los pasos que dependen de la pantalla actual del panel están marcados **[pendiente verificación en cuenta real]**.

No hace falta un workflow de deploy. Cloud despliega al recibir un push a `main` cuando la aplicación está conectada al repositorio. `cloud ship` y `LARAVEL_CLOUD_TOKEN` solo sirven si más adelante se quiere disparar el deploy desde GitHub Actions. Hasta entonces no existe `.github/workflows/deploy.yml`.

## Requisitos

- Cuenta de Laravel Cloud. **[pendiente verificación en cuenta real]**
- Este repositorio en GitHub: `leranuva/app_team_BD`, rama `main`, directorio raíz del repo.
- Un dominio propio es opcional. Se puede empezar con el subdominio que entrega Cloud.

## 1. Crear la aplicación

En el panel, conectar GitHub, elegir `leranuva/app_team_BD`, rama `main` y la raíz del repositorio. **[pendiente verificación en cuenta real]**

Cloud genera `APP_KEY` al crear la aplicación. No la escribas en el repositorio ni en el workflow.

## 2. Postgres

Crear Postgres, serverless o dedicado, desde el panel. Las variables `DB_*` las inyecta Cloud. No copies la contraseña al repositorio. **[pendiente verificación en cuenta real]**

La aplicación espera PostgreSQL 16 y guarda los instantes en UTC. `APP_TIMEZONE=America/New_York` es la zona de la obra, no la de la sesión de la base.

## 3. Cache y colas

Crear Valkey o Redis en el panel. **[pendiente verificación en cuenta real]**

```text
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

`reports:cleanup` usa `onOneServer()`. Ese candado necesita Redis. Sin `CACHE_STORE=redis` la tarea puede ejecutarse en más de un nodo.

Dos workers, para que un Excel largo no bloquee el recálculo de horas:

```text
php artisan queue:work --queue=default,attendance --tries=3 --timeout=60
php artisan queue:work --queue=reports --tries=3 --timeout=120
```

`attendance` recibe `RecalculateWeeklyHours` después de corregir una asistencia. `reports` recibe `GenerateReportExport` cuando un Excel supera 1000 filas. El timeout de `reports` tiene que ser mayor. Si el panel solo admite un proceso, el mínimo es:

```text
php artisan queue:work --queue=default,attendance,reports --tries=3 --timeout=120
```

No hay un comando programado `attendance:recalculate-week`. El recálculo es el job de la cola `attendance`, no un cron.

## 4. Object storage

Crear un bucket privado compatible con S3. `FILESYSTEM_DISK=s3`. Cloud inyecta `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` y, si aplica, `AWS_ENDPOINT` y `AWS_USE_PATH_STYLE_ENDPOINT`. **[pendiente verificación en cuenta real]**

El bucket no puede ser público. Fotos, documentos y exportaciones comparten el bucket y se separan por prefijo. El disco `exports` usa el prefijo `exports/`. Las fotos de alcance y los adjuntos usan sus propios prefijos. Los enlaces de descarga son `temporaryUrl` de 15 minutos.

## 5. Variables de entorno

Definirlas en el panel, no en Git.

```text
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=America/New_York
APP_URL=https://el-dominio
SESSION_SECURE_COOKIE=true
FILESYSTEM_DISK=s3
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

`DB_*`, `REDIS_*` y `AWS_*` los pone Cloud al crear cada recurso. `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` y `MAIL_FROM_ADDRESS` salen del SMTP o del proveedor transaccional. Sin correo real, el aviso de «reporte listo» no sale del panel.

No fijes `APP_KEY` a mano. Si se rota, las sesiones y los campos cifrados (seguro y contacto de emergencia) dejan de leerse. El procedimiento está en `docs/operations-runbook.md`.

## 6. Scheduler

Activar `php artisan schedule:run` cada minuto en el panel. **[pendiente verificación en cuenta real]**

La única tarea programada es `reports:cleanup`, a diario y con `onOneServer()`. Entra con el módulo de reportes. Compruébala con `php artisan schedule:list`.

## 7. Primer deploy

Push a `main`, o el botón de deploy del panel. El hook tiene que ejecutar `php artisan migrate --force`. No ejecutes `db:seed` en producción: el seeder solo crea roles y permisos, y volver a correrlo no es el alta del administrador. Los roles sí hacen falta una vez; si el hook no siembra, ejecuta `php artisan db:seed --force` una sola vez antes de crear el Super Admin. No cargues datos de demostración: este seeder no los tiene.

## 8. Backups

Serverless Postgres de Cloud ofrece point-in-time. Confírmalo en el panel del clúster. **[pendiente verificación en cuenta real]**

Para restaurar: crear una instancia desde el backup en el panel, apuntar `DB_*` a esa instancia, redeploy y comprobar una obra, un fichaje y un reporte. El detalle está en el runbook.

## 9. Dominio y SSL

Añadir el dominio, publicar el DNS que indique el panel y esperar el certificado. Cloud lo emite. No instales Certbot en este camino. **[pendiente verificación en cuenta real]**

## 10. Verificación después del deploy

Hazlo en staging antes de abrir la obra real. **[pendiente verificación en cuenta real]**

1. Crear el Super Admin con `php artisan logistics:create-worker` y el rol `Super Admin`. La contraseña pide al menos 12 caracteres. Antes tienen que existir los roles (`php artisan db:seed --force` una vez).
2. Entrar en `/admin` y en `/dashboard`.
3. Crear una obra, un alcance, un turno, una foto, una change order y una lista de materiales.
4. Fichar con GPS en un móvil real, dentro y fuera de la geocerca.
5. Descargar los tres Excel y el PDF de costo contra presupuesto.
6. Confirmar el aviso en la tabla `notifications` y en el buzón configurado en `MAIL_*`.
7. Confirmar que el objeto queda bajo `exports/` y que no hay URL pública permanente.
8. Para probar la retención sin esperar 7 días, bajar `reports.retention_days` solo en ese entorno, correr `php artisan reports:cleanup` y devolver el valor a 7.
