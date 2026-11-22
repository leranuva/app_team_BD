# Operación

Comandos y síntomas de esta aplicación. Cloud y Forge se distinguen solo donde el panel y el VPS no coinciden. No hay tokens en este documento.

## Rotar claves

Hazlo en el panel de Cloud o en Environment de Forge. Después, redeploy o `php artisan config:clear` si el proceso ya tenía la config en caché.

| Clave | Efecto |
| --- | --- |
| `APP_KEY` | Invalida sesiones y no descifra seguro ni contacto de emergencia. Todo el mundo vuelve a entrar. No rotes esta clave si esos campos importan y no tienes otra copia. |
| `AWS_*` | Las URLs firmadas viejas dejan de servir. Las nuevas salen con la clave nueva. El bucket no cambia de objetos. |
| `DB_PASSWORD` | Actualízala en Postgres y en `DB_*` a la vez. Un desfase tumba la aplicación. |
| `LARAVEL_CLOUD_TOKEN` | Solo existe si alguien activa `cloud ship`. Este repositorio no tiene workflow de deploy. Revócalo en la cuenta de Cloud y borra el secret de GitHub si se llegó a crear. |

## Restaurar un backup

Cloud. **[pendiente verificación en cuenta real]**

1. En el clúster Postgres, crear una instancia desde el backup o el punto en el tiempo.
2. Copiar las nuevas `DB_*` al entorno de la aplicación.
3. Redeploy.
4. Entrar, abrir una obra conocida y un fichaje anterior al corte.

Forge.

1. Crear una base vacía.
2. `pg_restore` o `psql` con el dump guardado fuera de `exports/`.
3. Apuntar `DB_*` a esa base y reiniciar php-fpm y los workers.
4. La misma comprobación de obra y fichaje.

No ejecutes `migrate:fresh` ni `db:seed` sobre la base restaurada.

## Logs

Cloud: el visor de logs del panel de la aplicación. **[pendiente verificación en cuenta real]**

Forge:

```text
storage/logs/laravel.log
journalctl -u nginx
journalctl -u supervisor
```

## Colas atascadas

```text
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush
```

`queue:flush` borra los fallidos. Úsalo solo cuando ya no quieras reintentarlos.

Cloud: reinicia el worker desde el panel. **[pendiente verificación en cuenta real]**

Forge: `sudo supervisorctl restart all`. El programa de `reports` es el que conviene reiniciar si el fallo es un Excel.

## El scheduler no corre

```text
php artisan schedule:list
```

Tiene que aparecer `reports:cleanup` diario. En Cloud, el minuto de `schedule:run` se activa en el panel. En Forge, el cron del sitio es el de [deploy-forge.md](deploy-forge.md). `onOneServer()` exige `CACHE_STORE=redis`. Si Redis no está, la tarea puede saltarse o duplicarse.

No busques `attendance:recalculate-week`. No existe. Las horas de la semana se recalculan con el job `RecalculateWeeklyHours` en la cola `attendance`, disparado al corregir una asistencia.

## Un trabajador no puede fichar

`attendances.geofence_flagged` es el indicador. No hay columna `geofence_reason`. La precisión va en `in_accuracy_meters` o `out_accuracy_meters`. Por encima de 100 m el fichaje se marca, no se rechaza. Estar fuera del radio de la obra sí rechaza la entrada.

La corrección ya existe: `PATCH /api/attendances/{id}/correct` con `clock_in_at`, `clock_out_at` y `correction_reason` (mínimo 10 caracteres). La envía un administrador o el supervisor de esa obra, no el trabajador que fichó. En `/supervisor/team` el botón solo aparece si `can_correct` es verdadero. Después se encola el recálculo en `attendance`.

Si el endpoint responde 403, el usuario no es supervisor de esa obra. Si responde 422, la salida no es posterior o el motivo es corto.

## Un reporte no aparece

`GenerateReportExport` corre en la cola `reports`. Si falla:

```text
php artisan queue:failed
```

El nombre de la clase es `App\Jobs\GenerateReportExport`. Reintenta con `php artisan queue:retry {id}` cuando el disco `exports` y las credenciales `AWS_*` ya respondan. El usuario recibe `ReportReadyNotification` por correo y en `notifications` solo si el job termina. Si el correo no llega, revisa `MAIL_*` y la fila en `notifications`: la base puede tener el aviso aunque el SMTP falle.

Para regenerarlo, el mismo usuario vuelve a pulsar «Descargar Excel» o «Descargar PDF» en Filament. Eso crea otro objeto y otro registro en `report_exports`.

## Limpieza de exportaciones

`reports:cleanup` borra filas de `report_exports` con `expires_at` vencido y el objeto en el disco `exports`. La retención es `reports.retention_days` (7).

```text
php artisan schedule:list
php artisan reports:cleanup
```

En producción no hace falta lanzarlo a mano si el scheduler está activo. Para comprobarlo sin esperar una semana, baja la retención solo en staging, genera un archivo y corre el comando. Devuelve la retención a 7.
