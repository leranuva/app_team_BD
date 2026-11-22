import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { DateTime } from 'luxon';
import { useEffect, useState } from 'react';
import { toast, Toaster } from 'sonner';
import AppLayout from '@/Layouts/AppLayout';
import { apiErrorMessage } from '@/lib/apiError';
import { readActiveSite, writeActiveSite } from '@/lib/activeSite';

type Member = {
    id: number;
    name: string;
    status: 'no_shift' | 'scheduled' | 'clocked_in' | 'outside_geofence' | 'completed';
    job_site: string | null;
    clocked_in_at: string | null;
    attendance_id: number | null;
    can_correct: boolean;
};

const labels: Record<Member['status'], string> = {
    no_shift: 'Sin turno',
    scheduled: 'Turno programado',
    clocked_in: 'Fichado',
    outside_geofence: 'Fuera de geocerca',
    completed: 'Turno completado',
};

function clock(value: string, timeZone: string) {
    return new Intl.DateTimeFormat('es-US', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone }).format(new Date(value));
}

export default function TeamToday({ timeZone, sites, jobSiteId, team }: {
    timeZone: string;
    sites: { id: number; name: string }[];
    jobSiteId: number | null;
    team: Member[];
}) {
    const [selected, setSelected] = useState<Member | null>(null);
    const [clockIn, setClockIn] = useState('');
    const [clockOut, setClockOut] = useState('');
    const [reason, setReason] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [pending, setPending] = useState(false);

    useEffect(() => {
        const timer = window.setInterval(() => router.reload({ only: ['team'] }), 60_000);

        return () => window.clearInterval(timer);
    }, [jobSiteId]);

    useEffect(() => {
        if (jobSiteId) return;
        const stored = readActiveSite();
        if (stored && sites.some((site) => String(site.id) === stored)) {
            router.get('/supervisor/team', { job_site_id: stored }, { preserveState: false, replace: true });
        }
    }, [jobSiteId, sites]);

    function filter(value: string) {
        writeActiveSite(value);
        router.get('/supervisor/team', value ? { job_site_id: value } : {}, { preserveState: false });
    }

    async function correct() {
        if (!selected?.can_correct || !selected.attendance_id) return;
        const clock_in_at = clockIn ? DateTime.fromISO(clockIn, { zone: timeZone }).toISO() : '';
        const clock_out_at = clockOut ? DateTime.fromISO(clockOut, { zone: timeZone }).toISO() : '';
        setPending(true);
        setFieldErrors({});
        try {
            await axios.patch(`/api/attendances/${selected.attendance_id}/correct`, {
                clock_in_at, clock_out_at, correction_reason: reason.trim(),
            }, { headers: { Accept: 'application/json' } });
            toast.success('Asistencia corregida. Se recalculan las horas de la semana.', { toasterId: 'team' });
            setSelected(null);
            router.reload({ only: ['team'] });
        } catch (caught) {
            const status = axios.isAxiosError(caught) ? caught.response?.status : undefined;
            const errors = axios.isAxiosError(caught)
                ? caught.response?.data?.errors as Record<string, string[]> | undefined
                : undefined;
            if (status === 403) {
                toast.error('Sin permisos para corregir esta asistencia.', { toasterId: 'team' });
                setSelected(null);
            } else if (status === 404) {
                toast.error('La asistencia ya no existe.', { toasterId: 'team' });
                setSelected(null);
                router.reload({ only: ['team'] });
            } else if (status === 422) {
                setFieldErrors({
                    clock_in_at: errors?.clock_in_at?.[0] ?? '',
                    clock_out_at: errors?.clock_out_at?.[0] ?? '',
                    correction_reason: errors?.correction_reason?.[0] ?? '',
                });
            } else {
                toast.error(apiErrorMessage(caught), { toasterId: 'team' });
            }
        } finally {
            setPending(false);
        }
    }

    return (
        <AppLayout header={<h1 className="text-2xl font-semibold text-slate-950">Equipo de hoy</h1>}>
            <Head title="Equipo de hoy" />
            <Toaster id="team" containerAriaLabel="Avisos del equipo" />
            <div className="mx-auto max-w-4xl px-4 py-6 text-base text-slate-950">
                <label className="grid max-w-sm gap-2 font-semibold">Obra
                    <select aria-label="Obra" className="min-h-12 rounded-lg border-slate-300" value={jobSiteId ?? ''} onChange={(event) => filter(event.target.value)}>
                        <option value="">Todas</option>
                        {sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
                    </select>
                </label>
                <ul className="mt-5 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                    {team.length === 0 && <li className="px-4 py-5">No hay trabajadores en esta obra.</li>}
                    {team.map((member) => (
                        <li key={member.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-4">
                            <div>
                                <p className="font-semibold">{member.name}</p>
                                <p>{labels[member.status]}{member.job_site ? ` · ${member.job_site}` : ''}</p>
                                {member.clocked_in_at && <p>Entrada {clock(member.clocked_in_at, timeZone)}</p>}
                            </div>
                            {member.can_correct && member.attendance_id && (
                                <button type="button" className="min-h-12 rounded-lg bg-slate-950 px-4 font-semibold text-white" onClick={() => {
                                    setSelected(member);
                                    setClockIn(member.clocked_in_at ? DateTime.fromISO(member.clocked_in_at, { zone: timeZone }).toFormat("yyyy-MM-dd'T'HH:mm") : '');
                                    setClockOut('');
                                    setReason('');
                                    setFieldErrors({});
                                }}>Corregir asistencia</button>
                            )}
                        </li>
                    ))}
                </ul>
                <p className="mt-4 text-sm text-slate-700">La lista se actualiza cada 60 segundos.</p>
                <a href="/supervisor/hours" className="mt-3 inline-flex min-h-12 items-center font-semibold underline">Horas de la semana</a>
            </div>
            {selected?.can_correct && (
                <Dialog open onClose={() => { if (!pending) setSelected(null); }} className="relative z-50">
                    <div className="fixed inset-0 bg-slate-950/50" aria-hidden="true" />
                    <div className="fixed inset-0 overflow-y-auto p-4">
                        <div className="flex min-h-full items-center justify-center">
                            <DialogPanel className="w-full max-w-lg rounded-2xl bg-white p-5">
                                <DialogTitle className="text-xl font-semibold">Corregir asistencia de {selected.name}</DialogTitle>
                                <div className="mt-4 grid gap-3">
                                    <label className="grid gap-1 text-sm font-medium">Entrada
                                        <input aria-label="Entrada" type="datetime-local" className="min-h-12 rounded-lg border-slate-300" value={clockIn} onChange={(event) => setClockIn(event.target.value)} />
                                        {fieldErrors.clock_in_at && <span role="alert">{fieldErrors.clock_in_at}</span>}
                                    </label>
                                    <label className="grid gap-1 text-sm font-medium">Salida
                                        <input aria-label="Salida" type="datetime-local" className="min-h-12 rounded-lg border-slate-300" value={clockOut} onChange={(event) => setClockOut(event.target.value)} />
                                        {fieldErrors.clock_out_at && <span role="alert">{fieldErrors.clock_out_at}</span>}
                                    </label>
                                    <label className="grid gap-1 text-sm font-medium">Motivo
                                        <textarea aria-label="Motivo" className="min-h-24 rounded-lg border-slate-300" value={reason} onChange={(event) => setReason(event.target.value)} />
                                        {fieldErrors.correction_reason && <span role="alert">{fieldErrors.correction_reason}</span>}
                                    </label>
                                </div>
                                <div className="mt-5 flex justify-end gap-3">
                                    <button type="button" className="min-h-12 rounded-lg border px-4" disabled={pending} onClick={() => setSelected(null)}>Cancelar</button>
                                    <button type="button" className="min-h-12 rounded-lg bg-slate-950 px-4 font-semibold text-white disabled:opacity-60" disabled={pending} onClick={() => { void correct(); }}>Guardar corrección</button>
                                </div>
                            </DialogPanel>
                        </div>
                    </div>
                </Dialog>
            )}
        </AppLayout>
    );
}
