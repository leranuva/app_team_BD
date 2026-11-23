import ClockButton, { type ClockPanel } from '@/Components/Attendance/ClockButton';
import EmptyState from '@/Components/EmptyState';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Toaster } from 'sonner';

type MaterialRow = { id: number; material: string | null; quantity: string; unit: string | null; reference_photo_url?: string | null };

type ShiftToday = {
    id: number;
    starts_at: string;
    ends_at: string;
    role_required: string | null;
    notes: string | null;
    is_unscheduled?: boolean;
    job_site: { name: string | null; address: string | null; maps_url: string | null };
    job_scope: { id: number; name: string } | null;
    supervisor: string | null;
    clocked_in_at: string | null;
    clocked_out_at: string | null;
    materials: MaterialRow[];
};

function clock(value: string, timeZone: string) {
    return new Intl.DateTimeFormat('en-US', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone }).format(new Date(value));
}

export default function Today({ timeZone, shifts, clock: panel }: {
    timeZone: string;
    shifts: ShiftToday[];
    clock: ClockPanel;
}) {
    return (
        <AppLayout header={<h1 className="text-2xl font-semibold text-slate-950">Today</h1>}>
            <Head title="Today" />
            <Toaster id="attendance" containerAriaLabel="Time clock notifications" />
            <div data-field-page className="mx-auto max-w-3xl bg-slate-100 px-4 py-6 text-base text-slate-950">
                <ClockButton field clock={panel} timeZone={timeZone} />
                {shifts.length === 0 && <EmptyState field title="You have no shift assigned today" />}
                {shifts.length > 0 && <h2 className="mb-3 text-lg font-semibold">Today’s shifts</h2>}
                {shifts.map((shift) => (
                    <section key={shift.id} aria-labelledby={`shift-title-${shift.id}`} className="mb-5 rounded-xl border border-slate-300 bg-white p-5">
                        <h3 id={`shift-title-${shift.id}`} className="text-lg font-semibold">{shift.job_site.name ?? 'Job site'}</h3>
                        {shift.is_unscheduled ? <p className="mt-2">Unscheduled work</p> : <p className="mt-2 text-base">
                            Scheduled: {' '}
                            <time dateTime={shift.starts_at}>{clock(shift.starts_at, timeZone)}</time>
                            {' – '}
                            <time dateTime={shift.ends_at}>{clock(shift.ends_at, timeZone)}</time>
                        </p>}
                        {shift.job_site.address && (
                            <p className="mt-1">
                                {shift.job_site.maps_url ? (
                                    <a className="inline-flex min-h-16 items-center text-base font-semibold underline" href={shift.job_site.maps_url} target="_blank" rel="noreferrer">
                                        {shift.job_site.address}
                                    </a>
                                ) : shift.job_site.address}
                            </p>
                        )}
                        {shift.job_scope && <p className="mt-2">Scope: {shift.job_scope.name}</p>}
                        <p className="mt-2">Required role: {shift.role_required ?? '—'}</p>
                        <p className="mt-2">Supervisor: {shift.supervisor ?? '—'}</p>
                        {shift.notes && <p className="mt-2 whitespace-pre-wrap">{shift.notes}</p>}
                        <dl className="mt-3 grid grid-cols-2 rounded-lg bg-slate-100 p-3">
                            <div>
                                <dt className="font-semibold">Clock-in</dt>
                                <dd>{shift.clocked_in_at ? <time dateTime={shift.clocked_in_at}>{clock(shift.clocked_in_at, timeZone)}</time> : '—'}</dd>
                            </div>
                            <div>
                                <dt className="font-semibold">Clock-out</dt>
                                <dd>{shift.clocked_out_at ? <time dateTime={shift.clocked_out_at}>{clock(shift.clocked_out_at, timeZone)}</time> : '—'}</dd>
                            </div>
                        </dl>
                        {shift.materials.length > 0 && (
                            <div className="mt-4">
                                <h4 className="font-semibold">Shift materials</h4>
                                <ul className="mt-2 divide-y divide-slate-200">
                                    {shift.materials.map((item) => (
                                        <li key={item.id} className="py-2">
                                            <div className="flex items-center gap-3">
                                                {item.reference_photo_url && (
                                                    <a href={item.reference_photo_url} target="_blank" rel="noreferrer"
                                                        aria-label={`Open reference photo of ${item.material ?? 'material'}`}>
                                                        <img src={item.reference_photo_url} alt="" loading="lazy"
                                                            className="h-12 w-16 rounded border border-slate-200 bg-white object-contain" />
                                                    </a>
                                                )}
                                                <span>{item.material ?? 'Material'} · {item.quantity}{item.unit ? ` ${item.unit}` : ''}</span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
