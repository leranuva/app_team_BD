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
        <section id={id} className="workspace-card overflow-hidden">
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
    const dateFormat = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short', timeZone });
    const today = shifts.filter((shift) => sameDay(shift.starts_at, timeZone));
    const flagged = Boolean(clock.open?.geofence_flagged);

    return (
        <AppLayout>
            <Head title="Operations" />
            <Toaster id="attendance" containerAriaLabel="Time clock notifications" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-8 sm:py-12">
                <header className="workspace-hero mb-6 flex flex-wrap items-end justify-between gap-5 px-6 py-8 text-white sm:px-9 sm:py-11">
                    <div>
                        <p className="mb-3 text-sm font-medium text-blue-100 first-letter:uppercase">{new Intl.DateTimeFormat('en-US', { dateStyle: 'full', timeZone }).format(new Date())}</p>
                        <h1 className="text-3xl font-bold tracking-tight sm:text-5xl">Blue Draft Team</h1>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        {photoScope && (
                            <a href={route('job-scopes.show', photoScope.id)} className="inline-flex min-h-12 items-center rounded-lg bg-white px-5 text-sm font-semibold text-slate-950 hover:bg-slate-100">
                                View scope photos{typeof photoScope.active_photos_count === 'number' ? ` (${photoScope.active_photos_count})` : ''}
                            </a>
                        )}
                        {profile === 'supervisor' && (
                            <a href="/material-lists" className="inline-flex min-h-12 items-center rounded-lg bg-white px-5 text-sm font-semibold text-slate-950">New list</a>
                        )}
                        {canOpenAdmin && (
                            <a href="/admin" className="inline-flex min-h-12 items-center rounded-lg brand-accent-action px-5 text-sm font-semibold">
                                Open administration
                            </a>
                        )}
                    </div>
                </header>
                {profile === 'worker' && (
                    <section aria-label="Today’s shift" className="mb-5 rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="text-lg font-semibold">Today’s shift</h2>
                        {today.length === 0 ? <p className="mt-2 text-sm text-slate-700">No shift</p> : today.map((shift) => (
                            <p key={shift.id} className="mt-2 text-sm">{shift.job_site.name} · {dateFormat.format(new Date(shift.starts_at))}</p>
                        ))}
                        <p className="mt-2 text-sm text-slate-700">{clock.open ? 'Open time entry' : 'No open time entry'}</p>
                        <a href="/worker/today" className="mt-3 inline-flex min-h-12 items-center text-sm font-semibold underline">View today’s shift</a>
                    </section>
                )}
                {profile === 'admin' && (
                    <section aria-label="Operations overview" className="mb-5 grid gap-3 sm:grid-cols-3">
                        <div className="workspace-card p-5"><p className="text-sm font-semibold text-slate-600">Active job sites</p><p className="mt-2 text-3xl font-bold tabular-nums">{sites.length}</p></div>
                        <div className="workspace-card p-5"><p className="text-sm font-semibold text-slate-600">Pending lists</p><p className="mt-2 text-3xl font-bold tabular-nums">{lists.length}</p></div>
                        <div className="workspace-card p-5"><p className="text-sm font-semibold text-slate-600">Geofence</p><p className={`mt-3 text-lg font-bold ${flagged ? 'text-amber-800' : 'text-emerald-800'}`}>{flagged ? 'Time entry needs review' : 'No alerts'}</p></div>
                    </section>
                )}
                <div id="fichaje">
                    <ClockButton clock={clock as never} timeZone={timeZone} />
                </div>
                {profile === 'worker' && (
                    <div className="mb-5">
                        <AssignmentList title="Materials for your shift" empty="No material lists assigned." items={lists} renderDetails={(list: MaterialListRow) => (
                            <><p className="font-medium text-slate-900">{list.name}</p><p className="mt-1">{list.job_site.name}</p></>
                        )} />
                    </div>
                )}
                <div className="grid items-start gap-5 md:grid-cols-2">
                    <AssignmentList title="Upcoming shifts" empty="No upcoming shifts." items={shifts}
                        renderDetails={(shift: Shift) => (
                            <>
                                <p className="font-medium text-slate-900">{shift.job_site.name}</p>
                                <p className="mt-1"><time dateTime={shift.starts_at}>{dateFormat.format(new Date(shift.starts_at))}</time></p>
                                <p>End {dateFormat.format(new Date(shift.ends_at))}</p>
                            </>
                        )}
                    />
                    <AssignmentList title="Pending material lists" empty="No pending lists." items={lists}
                        renderDetails={(list: MaterialListRow) => (
                            <>
                                <p className="font-medium text-slate-900">{list.name}</p>
                                <p className="mt-1">{list.job_site.name}</p>
                            </>
                        )}
                    />
                    {(mySites.length > 0 || !canOpenAdmin) && (
                        <AssignmentList id="obras" title="My job sites" empty="You have no assigned job sites." items={mySites}
                            renderDetails={(site: MySite) => (
                                <>
                                    <p className="font-medium text-slate-900">{site.name}</p>
                                    {site.read_only ? <p className="mt-1">Read only</p> : <p className="mt-1">{site.active_scopes_count} active scopes · {site.active_photos_count} active photos</p>}
                                </>
                            )}
                        />
                    )}
                    {canOpenAdmin && (
                        <AssignmentList title="Job sites" empty="No job sites are available yet." items={sites}
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
                    <nav aria-label="Supervisor shortcuts" className="mt-5 flex flex-wrap gap-2">
                        <a href="/supervisor/team" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Team</a>
                        <a href="/supervisor/scopes" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Progress</a>
                        <a href="/shifts/calendar" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Shifts</a>
                        <a href="/change-orders" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Change Orders</a>
                        <a href="/material-lists" className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">Materials</a>
                        <a id="fotos" href={photoScope ? route('job-scopes.show', photoScope.id) : '/dashboard#fotos'} className="inline-flex min-h-12 items-center rounded-lg border px-4 text-sm font-semibold">View photos</a>
                    </nav>
                )}
                {lists.length === 0 && profile === 'supervisor' && (
                    <div className="mt-5"><EmptyState title="No material lists yet. Create the first one." action={{ href: '/material-lists', label: 'Create list' }} /></div>
                )}
            </div>
        </AppLayout>
    );
}
