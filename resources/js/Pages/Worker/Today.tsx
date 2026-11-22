import ClockButton from '@/Components/Attendance/ClockButton';
import EmptyState from '@/Components/EmptyState';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';

type ShiftToday = {
    id: number;
    starts_at: string;
    ends_at: string;
    role_required: string | null;
    notes: string | null;
    job_site: { name: string | null; address: string | null; maps_url: string | null };
    job_scope: { id: number; name: string } | null;
    supervisor: string | null;
};

type MaterialRow = { id: number; material: string | null; quantity: string; unit: string | null };

type ClockPanel = {
    open: { shift_id: number; clocked_in_at: string; geofence_flagged?: boolean; job_site?: { name: string } | null } | null;
    shifts: { id: number; starts_at: string; job_site: { name: string } | null }[];
};

function clock(value: string, timeZone: string) {
    return new Intl.DateTimeFormat('es-US', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone }).format(new Date(value));
}

export default function Today({ timeZone, shift, materials, clock: panel }: {
    timeZone: string;
    shift: ShiftToday | null;
    materials: MaterialRow[];
    clock: ClockPanel;
}) {
    return (
        <AppLayout header={<h1 className="text-2xl font-semibold text-slate-950">Hoy</h1>}>
            <Head title="Hoy" />
            <div data-field-page className="mx-auto max-w-3xl bg-slate-100 px-4 py-6 text-base text-slate-950">
                {shift ? (
                    <section aria-label="Turno de hoy" className="mb-5 rounded-xl border border-slate-300 bg-slate-100 p-5">
                        <h2 className="text-lg font-semibold">Turno de hoy</h2>
                        <p className="mt-3 text-base">
                            <time dateTime={shift.starts_at}>{clock(shift.starts_at, timeZone)}</time>
                            {' – '}
                            <time dateTime={shift.ends_at}>{clock(shift.ends_at, timeZone)}</time>
                        </p>
                        <p className="mt-2 text-base font-semibold">{shift.job_site.name}</p>
                        {shift.job_site.address && (
                            <p className="mt-1">
                                {shift.job_site.maps_url ? (
                                    <a className="inline-flex min-h-16 items-center text-base font-semibold underline" href={shift.job_site.maps_url} target="_blank" rel="noreferrer">
                                        {shift.job_site.address}
                                    </a>
                                ) : shift.job_site.address}
                            </p>
                        )}
                        {shift.job_scope && <p className="mt-2">Alcance: {shift.job_scope.name}</p>}
                        <p className="mt-2">Rol requerido: {shift.role_required ?? '—'}</p>
                        <p className="mt-2">Supervisor: {shift.supervisor ?? '—'}</p>
                        {shift.notes && <p className="mt-2 whitespace-pre-wrap">{shift.notes}</p>}
                    </section>
                ) : (
                    <div className="mb-5">
                        <EmptyState field title="No tienes turno asignado hoy" />
                    </div>
                )}
                <ClockButton field clock={panel} timeZone={timeZone} />
                {materials.length > 0 && (
                    <section aria-label="Materiales del turno" className="rounded-xl border border-slate-300 bg-slate-100 p-5">
                        <h2 className="text-lg font-semibold">Materiales del turno</h2>
                        <ul className="mt-3 divide-y divide-slate-300">
                            {materials.map((item) => (
                                <li key={item.id} className="py-3 text-base">
                                    {item.material ?? 'Material'} · {item.quantity}{item.unit ? ` ${item.unit}` : ''}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
