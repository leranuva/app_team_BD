import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast, Toaster } from 'sonner';
import AppLayout from '@/Layouts/AppLayout';
import { apiErrorMessage } from '@/lib/apiError';
import { readActiveSite, writeActiveSite } from '@/lib/activeSite';

type ScopeCard = {
    id: number;
    name: string;
    status: string;
    progress_percent: number;
    job_site: { id: number; name: string } | null;
    responsible: string | null;
    can_edit: boolean;
    pending_change_orders: number;
    pending_material_lists: number;
    today_shifts: { id: number; worker: string | null; starts_at: string; ends_at: string }[];
    photos: { id: number; caption: string | null; url: string | null }[];
};

const statusLabel: Record<string, string> = {
    pending: 'Pendiente',
    active: 'Activo',
    in_progress: 'En progreso',
    completed: 'Completado',
};

export default function ScopeProgress({ timeZone, sites, jobSiteId, scopes }: {
    timeZone: string;
    sites: { id: number; name: string }[];
    jobSiteId: number | null;
    scopes: ScopeCard[];
}) {
    const [drafts, setDrafts] = useState<Record<number, number>>({});
    const [errors, setErrors] = useState<Record<number, string>>({});
    const [pending, setPending] = useState<number | null>(null);

    useEffect(() => {
        if (jobSiteId) return;
        const stored = readActiveSite();
        if (stored && sites.some((site) => String(site.id) === stored)) {
            router.get('/supervisor/scopes', { job_site_id: stored }, { preserveState: false, replace: true });
        }
    }, [jobSiteId, sites]);

    function filter(value: string) {
        writeActiveSite(value);
        router.get('/supervisor/scopes', value ? { job_site_id: value } : {}, { preserveState: false });
    }

    async function save(scope: ScopeCard) {
        const progress = drafts[scope.id] ?? scope.progress_percent;
        if (progress === 100 && scope.status !== 'completed') {
            setErrors((current) => ({ ...current, [scope.id]: 'El avance del 100 % solo corresponde a un alcance completado.' }));
            return;
        }
        setPending(scope.id);
        setErrors((current) => ({ ...current, [scope.id]: '' }));
        try {
            await axios.patch(`/api/job-scopes/${scope.id}`, { progress }, { headers: { Accept: 'application/json' } });
            toast.success('Avance actualizado.', { toasterId: 'scopes' });
            router.reload({ only: ['scopes'] });
        } catch (error) {
            setErrors((current) => ({ ...current, [scope.id]: apiErrorMessage(error) }));
        } finally {
            setPending(null);
        }
    }

    return (
        <AppLayout header={<h1 className="text-2xl font-semibold text-slate-950">Avance de alcances</h1>}>
            <Head title="Avance de alcances" />
            <Toaster id="scopes" containerAriaLabel="Avisos de avance" />
            <div className="mx-auto max-w-4xl px-4 py-6 text-base text-slate-950">
                <label className="grid max-w-sm gap-2 font-semibold">Obra
                    <select aria-label="Obra" className="min-h-12 rounded-lg border-slate-300" value={jobSiteId ?? ''} onChange={(event) => filter(event.target.value)}>
                        <option value="">Todas</option>
                        {sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
                    </select>
                </label>
                <div className="mt-5 grid gap-4">
                    {scopes.length === 0 && <p>No hay alcances en esta vista.</p>}
                    {scopes.map((scope) => {
                        const value = drafts[scope.id] ?? scope.progress_percent;
                        return (
                            <article key={scope.id} className="rounded-xl border border-slate-200 bg-white p-5">
                                <h2 className="text-lg font-semibold">{scope.name}</h2>
                                <p className="mt-1">{scope.job_site?.name} · {statusLabel[scope.status] ?? scope.status} · {scope.responsible ?? 'Sin responsable'}</p>
                                <div className="mt-3 h-3 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={value} aria-label={`Avance de ${scope.name}`}>
                                    <div className="h-full bg-slate-950" style={{ width: `${value}%` }} />
                                </div>
                                <p className="mt-2">{value} %</p>
                                {scope.can_edit && (
                                    <div className="mt-3 grid gap-2">
                                        <label className="text-sm font-semibold">Actualizar avance
                                            <input type="range" min={0} max={100} aria-label={`Actualizar avance de ${scope.name}`} className="mt-2 w-full" value={value} onChange={(event) => setDrafts((current) => ({ ...current, [scope.id]: Number(event.target.value) }))} />
                                        </label>
                                        <button type="button" className="min-h-12 w-fit rounded-lg bg-slate-950 px-4 font-semibold text-white disabled:opacity-60" disabled={pending === scope.id} onClick={() => { void save(scope); }}>
                                            Guardar
                                        </button>
                                        {errors[scope.id] && <p role="alert">{errors[scope.id]}</p>}
                                    </div>
                                )}
                                <p className="mt-3">Órdenes pendientes: {scope.pending_change_orders} · Listas pendientes: {scope.pending_material_lists}</p>
                                <ul className="mt-2">
                                    {scope.today_shifts.map((shift) => (
                                        <li key={shift.id}>{shift.worker ?? 'Trabajador'} · <time dateTime={shift.starts_at}>{new Intl.DateTimeFormat('es-US', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone }).format(new Date(shift.starts_at))}</time></li>
                                    ))}
                                </ul>
                                {scope.photos.length > 0 && (
                                    <ul className="mt-3 flex gap-3">
                                        {scope.photos.map((photo) => (
                                            <li key={photo.id}>
                                                {photo.url && <img src={photo.url} alt={photo.caption ?? 'Foto reciente'} className="h-16 w-16 rounded object-cover" />}
                                                {photo.caption && <p className="max-w-16 text-xs">{photo.caption}</p>}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </article>
                        );
                    })}
                </div>
            </div>
        </AppLayout>
    );
}
