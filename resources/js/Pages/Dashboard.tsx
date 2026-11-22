import ClockButton from '@/Components/Attendance/ClockButton';
import EmptyState from '@/Components/EmptyState';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { ReactNode } from 'react';
import { Toaster } from 'sonner';

type Site = { id: number; name: string; address?: string | null };
type MySite = { id: number; name: string; active_scopes_count: number | null; active_photos_count: number | null; read_only: boolean };
type Shift = { id: number; starts_at: string; ends_at: string; job_site: { name: string } };
type MaterialListRow = { id: number; name: string; job_site: { name: string } };
type Profile = 'worker' | 'supervisor' | 'admin';

function AssignmentList({ title, empty, items, renderDetails, id }: {
    title: string; empty: string; items: { id: number }[]; renderDetails: (item: never) => ReactNode; id?: string;
}) {
    return (
        <section id={id} className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 className="border-b border-slate-100 px-5 py-4 text-lg font-semibold text-slate-950">{title}</h2>
            {items.length ? (
                <ul className="divide-y divide-slate-100">
                    {items.map((item) => (
                        <li className="px-5 py-4 text-sm text-slate-600" key={item.id}>{renderDetails(item as never)}</li>
                    ))}
                </ul>
            ) : (
                <p className="px-5 py-5 text-sm text-slate-600">{empty}</p>
            )}
        </section>
    );
}

function sameDay(value: string, timeZone: string): boolean {
    const format = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' });

    return format.format(new Date(value)) === format.format(new Date());
}

export default function Dashboard({ timeZone, canOpenAdmin, sites, shifts, lists, clock, photoScope, mySites = [], profile }: {
    timeZone: string;
    canOpenAdmin: boolean;
    sites: Site[];
    shifts: Shift[];
    lists: MaterialListRow[];
    clock: { open: { geofence_flagged?: boolean } | null; shifts: unknown[] };
    photoScope: { id: number; active_photos_count?: number } | null;
    mySites?: MySite[];
    profile?: Profile;
}) {
    const dateFormat = new Intl.DateTimeFormat('es-US', { dateStyle: 'medium', timeStyle: 'short', timeZone });
    const today = shifts.filter((shift) => sameDay(shift.starts_at, timeZone));
    const flagged = Boolean(clock.open?.geofence_flagged);

    return (
        <AppLayout>
            <Head title="Operaciones" />
            <Toaster id="attendance" containerAriaLabel="Avisos de fichaje" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-8 sm:py-12">
                <header className="mb-6 flex flex-wrap items-center justify-between gap-5 rounded-2xl bg-slate-950 px-6 py-8 text-white sm:px-9">
                    <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Operaciones de obra</h1>
                    <div className="flex flex-wrap gap-3">
                        {photoScope && (
                            <a href={route('job-scopes.show', photoScope.id)} className="inline-flex min-h-12 items-center rounded-lg bg-white px-5 text-sm font-semibold text-slate-950 hover:bg-slate-100">
                                Ver fotos del alcance{typeof photoScope.active_photos_count === 'number' ? ` (${photoScope.active_photos_count})` : ''}
                            </a>
                        )}
                        {profile === 'supervisor' && (
                            <a href="/material-lists" className="inline-flex min-h-12 items-center rounded-lg bg-white px-5 text-sm font-semibold text-slate-950">Nueva lista</a>
                        )}
                        {canOpenAdmin && (
                            <a href="/admin" className="inline-flex min-h-12 items-center rounded-lg bg-amber-300 px-5 text-sm font-semibold text-slate-950 hover:bg-amber-200">
                                Abrir panel administrativo
                            </a>
                        )}
                    </div>
                </header>
                {profile === 'worker' && (
                    <section aria-label="Turno de hoy" className="mb-5 rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="text-lg font-semibold">Turno de hoy</h2>
                        {today.length === 0 ? <p className="mt-2 text-sm text-slate-700">sin turno</p> : today.map((shift) => (
                            <p key={shift.id} className="mt-2 text-sm">{shift.job_site.name} · {dateFormat.format(new Date(shift.starts_at))}</p>
                        ))}
                        <p className="mt-2 text-sm text-slate-700">{clock.open ? 'Asistencia abierta' : 'Sin fichaje abierto'}</p>
                        <a href="/worker/today" className="mt-3 inline-flex min-h-12 items-center text-sm font-semibold underline">Abrir jornada</a>
                    </section>
                )}
                {profile === 'admin' && (
                    <section aria-label="Resumen global" className="mb-5 grid gap-3 sm:grid-cols-3">
                        <p className="rounded-xl bg-white p-4 text-sm font-semibold">Obras activas: {sites.length}</p>
                        <p className="rounded-xl bg-white p-4 text-sm font-semibold">{lists.length} listas pendientes</p>
                        <p className="rounded-xl bg-white p-4 text-sm font-semibold">{flagged ? 'Alerta de geofence' : 'Sin alertas de geofence'}</p>
                    </section>
                )}
                <div id="fichaje">
                    <ClockButton clock={clock as never} timeZone={timeZone} />
                </div>
                {profile === 'worker' && (
                    <div className="mb-5">
                        <AssignmentList title="Materiales de tu turno" empty="No hay listas asignadas." items={lists} renderDetails={(list: MaterialListRow) => (
                            <><p className="font-medium text-slate-900">{list.name}</p><p className="mt-1">{list.job_site.name}</p></>
                        )} />
                    </div>
                )}
                <div className="grid items-start gap-5 md:grid-cols-2">
                    <AssignmentList title="Próximos turnos" empty="No hay turnos próximos." items={shifts}
                        renderDetails={(shift: Shift) => (
                            <>
                                <p className="font-medium text-slate-900">{shift.job_site.name}</p>
                                <p className="mt-1"><time dateTime={shift.starts_at}>{dateFormat.format(new Date(shift.starts_at))}</time></p>
                                <p>Fin {dateFormat.format(new Date(shift.ends_at))}</p>
                            </>
                        )}
                    />
                    <AssignmentList title="Listas de materiales pendientes" empty="No hay listas pendientes." items={lists}
                        renderDetails={(list: MaterialListRow) => (
                            <>
                                <p className="font-medium text-slate-900">{list.name}</p>
                                <p className="mt-1">{list.job_site.name}</p>
                            </>
                        )}
                    />
                    {(mySites.length > 0 || !canOpenAdmin) && (
                        <AssignmentList id="obras" title="Mis obras" empty="No tienes obras asignadas." items={mySites}
                            renderDetails={(site: MySite) => (
                                <>
                                    <p className="font-medium text-slate-900">{site.name}</p>
                                    {site.read_only ? <p className="mt-1">Solo lectura</p> : <p className="mt-1">{site.active_scopes_count} alcances activos · {site.active_photos_count} fotos activas</p>}
                                </>
                            )}
                        />
                    )}
                    {canOpenAdmin && (
                        <AssignmentList title="Obras" empty="Todavía no hay obras disponibles." items={sites}
                            renderDetails={(site: Site) => (
                                <>
                                    <p className="font-medium text-slate-900">{site.name}</p>
                                    <p className="mt-1">{site.address}</p>
                                </>
                            )}
                        />
                    )}
                </div>
                {profile === 'supervisor' && (
                    <nav aria-label="Accesos del supervisor" className="mt-5 flex flex-wrap gap-2">
                        <a href="/supervisor/team" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Equipo</a>
                        <a href="/supervisor/scopes" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Avance</a>
                        <a href="/shifts/calendar" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Turnos</a>
                        <a href="/change-orders" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Change Orders</a>
                        <a href="/material-lists" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Materiales</a>
                        <a id="fotos" href={photoScope ? route('job-scopes.show', photoScope.id) : '/dashboard#fotos'} className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Ver fotos</a>
                    </nav>
                )}
                {lists.length === 0 && profile === 'supervisor' && (
                    <div className="mt-5"><EmptyState title="No hay listas todavía. Crea la primera." action={{ href: '/material-lists', label: 'Crear lista' }} /></div>
                )}
            </div>
        </AppLayout>
    );
}
