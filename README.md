# Logística de Obra

Aplicación interna para una constructora en Nueva York. La Fase 0 entrega autenticación, alta de cuentas internas, panel de obras y el esquema para desarrollar los módulos de logística.

## Arranque

Requisitos: PHP 8.3 con PDO PostgreSQL, intl, mbstring y zip, Composer 2, Node.js 22.12+ y PostgreSQL 16. Crea una base vacía y un usuario con permisos sobre ella. Desde la raíz del proyecto:

~~~powershell
composer install
Copy-Item .env.example .env
# Completa DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD en .env.
php artisan key:generate
php artisan migrate --seed
npm ci
npm run build
php artisan logistics:create-worker
php artisan serve
~~~

Usa el comando de alta para crear el primer **Super Admin**. La contraseña se captura de forma interactiva. Abre [el panel administrativo](http://127.0.0.1:8000/admin) o [el portal operativo](http://127.0.0.1:8000/dashboard).

Ejecuta de nuevo el comando para crear supervisores y trabajadores. Desde **Obras**, un administrador puede crear una obra y guardar su **Equipo asignado**. Para probar con SQLite en local, establece DB_CONNECTION=sqlite, elimina DB_DATABASE y crea database/database.sqlite antes de migrar.

### Configuración del entorno

- En producción usa HTTPS en el servidor o proxy, APP_URL con https, APP_DEBUG=false y SESSION_SECURE_COOKIE=true. Configura SMTP para la recuperación de contraseñas. El login de Breeze limita intentos por correo e IP y el de Filament aplica su propio límite.
- `APP_TIMEZONE=America/New_York` define la zona operativa en `config('app.business_timezone')`. El portal usa ese valor para mostrar los turnos. Laravel y la sesión PostgreSQL usan UTC para persistir instantes. Para la Fase 1, calcula los límites de semana laboral en la zona operativa y conviértelos a UTC para consultar y medir intervalos, incluidos los cambios de horario de verano.
- El disco S3 está disponible con las variables AWS de .env.example. Usa un bucket privado y credenciales fuera del repositorio. La subida de documentos se incorpora en los respectivos módulos.
- La cola usa la base de datos. Para consumir trabajos, ejecuta php artisan queue:work. Las fases de órdenes de cambio y reportes añadirán sus trabajos.
- Conserva APP_KEY al desplegar o actualizar. Los datos del seguro y del contacto de emergencia están cifrados con esa clave.

## Acceso

### Trabajadores

En `/admin/workers`, los administradores pueden dar de alta, editar, activar, desactivar y eliminar perfiles operativos. Cada perfil utiliza su registro de `User`. Elegir la obra principal también añade al trabajador a su equipo y conserva sus demás membresías. Los supervisores pueden actualizar únicamente el teléfono, la dirección y el contacto de emergencia de los trabajadores de su equipo. La eliminación es lógica para conservar las referencias históricas. Una cuenta inactiva pierde el acceso en la siguiente solicitud.

Las membresías se guardan en `job_site_user`. La semántica de `is_primary` se representa con `users.primary_job_site_id`: cada trabajador tiene cero o una obra principal. El guardado actualiza esa FK y añade la membresía en la misma transacción. Cambiar de obra principal conserva la membresía anterior. Para seleccionar trabajadores asignables, usa `User::operational()->active()` y aplica también el filtro de obra.

Configura las variables AWS del disco privado S3 para cargar documentos de identidad y certificaciones OSHA. Conserva bloqueado el acceso público al bucket. Filament guarda rutas generadas y solo firma documentos asociados al perfil cuando el usuario está autorizado. Los enlaces de S3 caducan a los 10 minutos. Los archivos se conservan al eliminar registros o quitar adjuntos para que la retención de documentación se gestione por separado. Las pruebas usan un disco S3 simulado.

`php artisan db:seed --class=ExampleWorkersSeeder` crea una obra y tres perfiles operativos ficticios en local. Los ejemplos tienen contraseñas aleatorias y sirven para probar el panel desde una cuenta administrativa.

| Rol | Acceso en la Fase 0 |
| --- | --- |
| Super Admin | Todas las obras, operaciones y gestión de roles en Shield. |
| Administrador | Todas las obras, sus equipos y asignaciones. |
| Supervisor | Consulta de sus obras en Filament y de sus turnos y listas en el portal. Las policies autorizan la consulta de su equipo y registros de sus obras. |
| Carpintero y Ayudante | Portal operativo con sus propios turnos y listas pendientes en las obras de las que sean miembros. |

El registro público está deshabilitado. Los cinco roles base se aprovisionan mediante un seeder idempotente y se protegen de cambios desde Shield. La pertenencia a una obra se controla mediante job_site_user. Los modelos ligados a obras aplican un scope global para sesiones autenticadas. Las policies comprueban el acceso a cada registro. Los procesos sin sesión deben consultar con visibleTo($actor) cuando actúan por cuenta de un usuario. Los comandos de mantenimiento operan a nivel de base de datos.

Shield gestiona roles y permisos administrativos. La generación automática de policies y el registro de la policy de roles de Shield están desactivados. Para generar permisos CRUD, usa `php artisan shield:generate --panel=admin --option=permissions` y selecciona el recurso correspondiente. Las policies de Laravel conservan la autorización de registros y acciones de negocio. En Fase 1, registra la policy de fichaje explícitamente si reemplaza la policy de lectura actual de `Attendance`.

## Estructura

### Turnos en Filament

El recurso `/admin/shifts` permite crear, editar, duplicar y cancelar turnos. El formulario muestra la hora de Nueva York y guarda los instantes en UTC. Usa las columnas existentes `user_id`, `starts_at` y `ends_at`. La migración complementaria añade el alcance opcional, el rol operativo, el estado, las notas y los datos de auditoría.

El trabajador debe estar activo y tener el rol requerido y una membresía en la obra. La validación de solapamientos cubre todas sus obras, admite rangos contiguos y excluye turnos cancelados o eliminados. PostgreSQL bloquea la fila del trabajador durante la asignación para serializar cambios simultáneos. La asignación múltiple confirma cada turno por separado e informa el resultado por trabajador.

Cancelar exige un motivo y lo registra en la bitácora `shifts`. Administrador y Super Admin pueden eliminar lógicamente un turno sin asistencia. Para ejecutar las pruebas de este recurso, usa `php artisan test --filter=ShiftManagementTest` o la suite completa con `php artisan test`.

### API de turnos

La API usa la sesión web con Sanctum. Desde el portal, envía las cookies, `Accept: application/json` y el token CSRF en solicitudes de escritura.

- `GET /api/shifts/options` devuelve `job_sites` como pares `{ id, name }`, `statuses` y `roles` como pares `{ value, label }` y `can_edit` según la policy de turnos. Para los trabajadores, las obras incluyen su obra principal y sus demás membresías vigentes. El endpoint exige sesión y tiene una cuota independiente de 120 solicitudes por minuto.

- `GET /api/shifts` devuelve `{ data: [...], meta: { from, to, count } }` según el rol y la obra. Requiere `from` y `to` en ISO 8601. Los filtros opcionales son `job_site_id`, `worker_id`, `role_required` y `status`. El rango incluye turnos que lo intersectan y usa un límite final exclusivo. Una fecha sin hora representa la medianoche en la zona operativa. El intervalo puede cubrir hasta 90 días locales y devolver hasta 2.000 turnos sin paginación.
- El GET devuelve 200 al consultar, 403 si se solicita un filtro fuera de los permisos del usuario, 422 por rango inválido o exceso de resultados y 429 después de 60 solicitudes por minuto.
- `PATCH /api/shifts/{id}/reassign` exige `{ start_at, end_at, expected_updated_at }` con timestamps ISO 8601 y offset explícito, incluido `Z`. Usa como `expected_updated_at` el valor `updated_at` del evento leído. `data` y `current` contienen eventos completos en el mismo formato que el GET.
- La consulta mapea las columnas `starts_at` y `ends_at` a `start` y `end` para FullCalendar. El cuerpo de escritura usa `start_at` y `end_at`. El offset identifica el instante exacto al cruzar zonas horarias y cambia según la fecha en Nueva York. Los valores se guardan en UTC.
- La reasignación bloquea el trabajador y el turno, en ese orden, y compara la versión antes de guardar. Comparte la regla de solapamiento con Filament y registra los cambios en `shifts`. `updated_at` avanza al menos un segundo por cada actualización del modelo para distinguir arrastres rápidos con la precisión de la columna existente.
- El PATCH tiene una cuota independiente de 120 solicitudes por minuto. Alcanzar la cuota devuelve 429.
- Después de confirmar un cambio real, se encolan una notificación de base de datos y un correo al trabajador, excepto cuando es quien hizo el cambio. Incluyen la obra, los horarios anterior y nuevo en Nueva York y el nombre del editor. Un PATCH con la versión vigente y el mismo horario devuelve 200, conserva la versión y omite la auditoría y la notificación. Ejecuta `php artisan queue:work` y configura SMTP para procesar los avisos.

| Código PATCH | Resultado |
| --- | --- |
| 200 | `{ data, message }` |
| 403 | El usuario carece de permiso para reasignar el turno. |
| 409 | Versión obsoleta. `{ message, current }` contiene el evento vigente. |
| 422 | Datos inválidos o solapamiento en `errors.start_at`. |
| 429 | Se alcanzó la cuota independiente de 120 solicitudes por minuto. |

### Calendario de turnos

`/shifts/calendar` carga por Inertia el mismo catálogo que el endpoint de opciones, el permiso de edición y la zona de Nueva York. FullCalendar 6 con el adaptador Luxon muestra los turnos en mes, semana o lista. Los filtros de obra, rol y estado se inicializan desde la URL y actualizan `job_site_id`, `role_required` y `status` sin volver a navegar. La página también admite `worker_id` en un enlace compartido. Para agregar otro filtro, añade su clave al estado de la página y aplica el mismo criterio en la consulta del backend.

La fuente de eventos de FullCalendar pasa el rango visible a `useShiftsFeed`. El hook precarga siete días a cada lado, agrupa cambios durante 300 ms, cancela consultas obsoletas y guarda cada combinación de rango y filtros por 60 segundos. Un 422 en el rango ampliado reintenta una vez con el rango visible. Un 429 respeta `Retry-After` cuando el servidor lo envía. La paleta de `resources/js/features/shifts/shiftColors.ts` define fondo, borde y texto para cada estado: índigo para programados, verde para confirmados, azul para completados y gris atenuado con tachado para cancelados. El tooltip nativo muestra el trabajador, la obra y el rol.

El servidor decide el permiso general `can_edit` y el permiso por turno `extendedProps.can_edit`. La interfaz refleja ambos. La página detecta el puntero táctil al montar y abre directamente el formulario de horario al tocar un turno editable, para evitar arrastres involuntarios. En escritorio también se puede editar desde el detalle con el teclado. Ante un 409, la página sustituye el evento por `current`, incluida la versión que usará el próximo intento.

#### Calendario: edición y fallback táctil

Sonner muestra los avisos del calendario y del formulario. El formulario usa campos nativos `datetime-local` y convierte la hora de Nueva York a ISO 8601 con Luxon. Si el servidor devuelve 409 durante la edición, el modal sigue abierto con el horario y la versión vigentes. El diálogo de Headless UI contiene el foco y se cierra con Escape.

| Resultado de edición | Aviso y comportamiento |
| --- | --- |
| 200 | «Turno reasignado». Se aplica la respuesta y se vuelve a consultar el rango activo. |
| 409 | «Otro usuario modificó el turno; se actualizó la vista». Se aplican el horario y la versión de `current`. |
| 422 | Se muestra el primer error de validación. En escritorio se revierte el arrastre o el ajuste de duración. |
| 403 | «Sin permisos para reasignar este turno». Se revierte el gesto. |
| 429 | «Demasiadas operaciones, espera un momento». Se revierte el gesto. |
| Error de red | Se revierte el gesto y se ofrece «Reintentar». En el modal se puede volver a enviar el formulario. |

Ejecuta `npm test` para probar el calendario con Vitest y Testing Library. Para comprobarlo en un navegador, abre Turnos como supervisor, arrastra un turno y confirma el nuevo horario. Abre el mismo turno en dos pestañas, guarda en una y luego arrástralo en la otra para comprobar que la segunda muestra el horario vigente. Con emulación táctil, toca el turno y confirma que el formulario permite editar inicio y fin.

Evento de ejemplo en `data`:

~~~json
{
  "id": 1,
  "title": "Carlos Méndez · Carpintero",
  "start": "2026-11-21T08:00:00-05:00",
  "end": "2026-11-21T17:00:00-05:00",
  "updated_at": "2026-11-20T16:42:00-05:00",
  "status": "programmed",
  "role_required": "carpintero",
  "job_site": {"id": 3, "name": "Brooklyn Heights Renovation", "address": "Brooklyn, NY"},
  "job_scope": {"id": 7, "name": "Encofrado"},
  "worker": {"id": 12, "name": "Carlos Méndez", "role": "carpintero"},
  "extendedProps": {
    "worker_name": "Carlos Méndez",
    "job_site_name": "Brooklyn Heights Renovation",
    "role_required": "carpintero",
    "status": "programmed",
    "can_edit": true
  }
}
~~~

- **Laravel 12 y Breeze** comparten la sesión web con React e Inertia. Las rutas de dominio de esta fase son web.
- **Filament 4 y Shield** exponen el panel en /admin. Filament valida sus propios formularios, autoriza con policies y guarda la obra y su equipo en una transacción. El perfil web conserva el Form Request de Breeze.
- **Eloquent y PostgreSQL** cubren trabajadores, obras, scopes, órdenes de cambio, materiales, listas, turnos y asistencias. Las claves foráneas compuestas previenen cruces de obra en scopes y listas, y garantizan que la asistencia pertenezca al turno, trabajador y obra indicados.
- **Spatie Activitylog** registra cambios de modelos en órdenes de cambio, turnos, catálogo y listas de materiales. En una actualización autenticada, `causer` identifica al usuario, `subject` al modelo modificado y `LogOptions` limita el historial a los campos configurados que cambiaron. El valor anterior queda en `properties.old` y el nuevo en `properties.attributes`. Las actualizaciones masivas con el constructor de consultas omiten los eventos de modelo.
- El catálogo es global. El acceso de campo a los materiales se concede a través de las listas asignadas. Los datos privados de trabajadores quedan fuera de la serialización del usuario.

## Verificación

### Fotos de avance de obra

La migración `scope_photos` vincula cada foto con un alcance y un usuario. El modelo deriva la obra del alcance y una clave foránea compuesta comprueba esa relación en la base. En SQLite, Laravel activa `PRAGMA foreign_keys` al abrir la conexión mediante `DB_FOREIGN_KEYS=true` por defecto.

`ScopePhoto::temporaryUrl()` firma durante 15 minutos las fotos activas que existen en el disco privado. La ruta relativa no se incluye al serializar el modelo. `php artisan test --filter=ScopePhotoModelTest` comprueba la reversión y reaplicación de la migración, el vínculo con la obra y la expiración de los enlaces con `Storage::fake('s3')`.

#### ScopePhotoService: reglas de negocio

El observer permite actualizar `caption` y `archived_at` y protege los demás metadatos tras crear la foto. `ScopePhotoService` permite a administradores y supervisores de la obra subir JPEG, PNG o WebP de hasta 10 MB. El servicio ignora `job_site_id` en los metadatos recibidos y siempre lo deriva del alcance. Escribe con visibilidad privada en `scopes/{obra}/{alcance}/{uuid}.{ext}`, consulta el tamaño en S3 y conserva el nombre original por separado. Si falla la escritura o el registro en la base, intenta eliminar el objeto de esa subida. La fecha de captura usa primero el dato explícito, después el EXIF del JPEG si PHP puede leerlo y finalmente la hora del servidor. El EXIF sin offset se interpreta en Nueva York.

#### Archivado y restauración de fotos

El archivado conserva el objeto en S3 y evita emitir enlaces nuevos. Los enlaces ya emitidos pueden seguir funcionando hasta expirar. Al repetir un archivado se conservan `archived_at` y el motivo original. Al repetir una restauración de una foto activa se conserva su estado. Las repeticiones omiten una segunda entrada en la bitácora.

`LogsActivity` guarda una sola actualización por cambio, con descripción `scope_photo.archived` o `scope_photo.restored` y el actor en `causer`. El motivo es opcional en el servicio y se guarda en `properties.reason` al archivar. El futuro formulario lo pedirá.

Antes de restaurar, el servicio comprueba que el objeto siga en S3. Si se borró manualmente o por una política de retención, la foto sigue archivada y el log de la aplicación registra `photo_id` y `path` como advertencia. Las pruebas usan `Storage::fake('s3')`, que admite `exists()`, `delete()` y las comprobaciones `assertExists()` y `assertMissing()`.

### Asistencias y horas

`AttendanceService` abre y cierra asistencias del propio trabajador. Usa la hora del servidor en UTC y puede rechazar muestras GPS vencidas. Valida el radio de la obra y marca para revisión los fichajes dentro del radio con precisión superior a 100 m. Las ventanas de entrada y salida, de 30 minutos respecto del horario previsto, se ajustan en `config/attendance.php`; se pueden desactivar por separado con `null`. La base permite una asistencia por turno y una entrada abierta por trabajador. La bitácora identifica al actor y registra horarios, indicadores y totales de minutos.

`AttendanceCalculatorService` separa períodos al cruzar medianoche o el comienzo de semana en Nueva York, mide el tiempo real en UTC y suma las horas del trabajador en todas sus obras. La configuración inicial usa semana desde el lunes, 40 horas semanales, un umbral diario interno de 8 horas, overtime a 1,5× y doble tiempo desactivado. Cada minuto se asigna a una sola categoría. `estimateGrossPay()` estima el importe bruto con una tarifa suministrada.

El umbral semanal general y las reglas de obras públicas se deben contrastar con los requisitos del proyecto y del oficio antes de usar estos totales en nómina. [Jornadas y salarios de NY](https://dol.ny.gov/wages-and-hours-frequently-asked-questions) · [Artículo 8 y obra pública](https://dol.ny.gov/article-8-frequently-asked-questions)

Aplica la migración con `php artisan migrate`. Ejecuta `php artisan test --filter=Attendance` para validar fichaje y overtime.

En **Operaciones**, el botón de fichaje pide permiso de ubicación al pulsarlo. El navegador necesita HTTPS o localhost. El panel presenta los turnos propios disponibles y prioriza cerrar una entrada abierta.

`POST /api/attendances/clock-in` y `POST /api/attendances/clock-out` requieren sesión Sanctum, CSRF y un cuerpo `{ shift_id, lat, lng, accuracy, timestamp }`. El timestamp ISO 8601 con offset indica cuándo se tomó la muestra GPS. Los dos endpoints comparten 20 solicitudes por minuto. La entrada devuelve 201 y la salida 200; ambas devuelven `{ data, message }` con horarios de Nueva York y sin coordenadas. Un turno ajeno devuelve 403 y los datos inválidos o un fichaje fuera del radio devuelven 422.

Un intento fuera del radio encola avisos de base de datos y correo para supervisores activos asignados a la obra. Se envía como máximo un aviso cada cinco minutos por trabajador, turno y tipo de fichaje. El aviso identifica la obra, el trabajador y la hora del intento. No incluye coordenadas. Ejecuta `php artisan queue:work` y configura SMTP para procesar los avisos.

~~~powershell
php artisan test
npm run build
~~~

`phpunit.xml` y `.env.testing` seleccionan PostgreSQL. GitHub Actions ejecuta la suite con un servicio PostgreSQL 16 y compila los recursos de React. Para la misma ejecución en local, crea `constructora_testing` y proporciona las credenciales de esa **base exclusiva de pruebas** mediante variables de proceso. `RefreshDatabase` puede borrar sus tablas. La clave de `.env.testing` es un valor público de pruebas.

Para una ejecución rápida local con SQLite en memoria, abre una sesión de PowerShell y ejecuta:

~~~powershell
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
php artisan test
Remove-Item Env:DB_CONNECTION, Env:DB_DATABASE
~~~

Las migraciones de la Fase 0 usan `json()`, que produce `json` nativo en PostgreSQL y `text` en SQLite. Los adjuntos de trabajadores usan `jsonb()` para permitir la comparación que necesita el selector de equipos al consultar usuarios con `DISTINCT`. SQLite también representa esas columnas como texto. PostgreSQL aplica precisión decimal y tipos temporales con zona, mientras SQLite usa afinidades de tipo y guarda las fechas en la convención UTC de Laravel. Usa el resultado de PostgreSQL para validar cambios de esquema y consultas dependientes del motor.

Las pruebas cubren inicio de sesión, acceso por rol, aislamiento por obra, creación de obras con miembros, integridad de sitio y trabajador en asistencias y auditoría de turnos. El fichaje, la aprobación de órdenes y los demás flujos de escritura quedan para las fases correspondientes.

