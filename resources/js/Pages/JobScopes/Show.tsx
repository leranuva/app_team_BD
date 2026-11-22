import LoadingState from '@/Components/LoadingState';
import AppLayout from '@/Layouts/AppLayout';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Head } from '@inertiajs/react';
import { DateTime } from 'luxon';
import { useEffect, useState } from 'react';
import { toast, Toaster } from 'sonner';
import { archivePhoto, fetchPhotos, restorePhoto, uploadPhoto, type PhotoStatus, type ScopePhotoItem } from './photoApi';

const toaster = { toasterId: 'photos' } as const;

type ScopeShift = { id: number; worker: string | null; starts_at: string; status: string };

type ScopeSummary = {
    id: number; name: string; status: string; job_site: { id: number; name: string } | null;
    description?: string | null; notes?: string | null; progress_percent?: number;
    start_date?: string | null; estimated_end_date?: string | null;
    responsible?: { id: number; name: string } | null; site_url?: string | null;
    can_edit?: boolean; edit_url?: string | null;
};

const statusLabels: Record<string, string> = {
    pending: 'Pendiente', active: 'Activo', in_progress: 'En progreso', completed: 'Completado',
};

function formatWhen(value: string | null, zone: string): string {
    if (!value) return 'Sin fecha';
    const date = DateTime.fromISO(value, { setZone: true }).setZone(zone);
    return date.isValid ? date.setLocale('es').toLocaleString(DateTime.DATETIME_MED) : 'Sin fecha';
}

export default function Show({ scope, canCreate, canViewAll, timeZone, shifts = [] }: {
    scope: ScopeSummary; canCreate: boolean; canViewAll: boolean; timeZone: string; shifts?: ScopeShift[];
}) {
    const tabs: { value: PhotoStatus; label: string }[] = [
        { value: 'active', label: 'Activas' },
        { value: 'archived', label: 'Archivadas' },
        ...(canViewAll ? [{ value: 'all' as const, label: 'Todas' }] : []),
    ];
    const [section, setSection] = useState<'photos' | 'shifts' | 'detail'>('photos');
    const [status, setStatus] = useState<PhotoStatus>('active');
    const [photos, setPhotos] = useState<ScopePhotoItem[]>([]);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState<ScopePhotoItem | null>(null);
    const [reason, setReason] = useState('');
    const [uploading, setUploading] = useState(false);
    const [uploadOpen, setUploadOpen] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [caption, setCaption] = useState('');
    const [progress, setProgress] = useState(0);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        fetchPhotos(scope.id, status, 1, controller.signal).then((result) => {
            setPhotos(result.data);
            setPage(result.meta.current_page);
            setLastPage(result.meta.last_page);
            setLoading(false);
        }).catch(() => {
            if (!controller.signal.aborted) setLoading(false);
        });
        return () => controller.abort();
    }, [scope.id, status]);

    useEffect(() => () => {
        if (preview) URL.revokeObjectURL(preview);
    }, [preview]);

    async function loadMore() {
        const next = page + 1;
        const result = await fetchPhotos(scope.id, status, next);
        setPhotos((current) => [...current, ...result.data]);
        setPage(result.meta.current_page);
        setLastPage(result.meta.last_page);
    }

    function chooseFile(next: File | null) {
        if (preview) URL.revokeObjectURL(preview);
        setFile(next);
        setPreview(next ? URL.createObjectURL(next) : null);
    }

    async function submitUpload(event: React.FormEvent) {
        event.preventDefault();
        if (!file) {
            toast.error('Selecciona una foto.', toaster);
            return;
        }
        setUploading(true);
        setProgress(0);
        const result = await uploadPhoto(scope.id, file, caption, setProgress);
        setUploading(false);
        if (!result.ok) {
            toast.error(result.message, toaster);
            return;
        }
        toast.success('Foto subida', toaster);
        chooseFile(null);
        setCaption('');
        setUploadOpen(false);
        if (status === 'active' || status === 'all') setPhotos((current) => [result.photo, ...current]);
    }

    async function archiveSelected() {
        if (!selected) return;
        setBusy(true);
        const result = await archivePhoto(selected.id, reason);
        setBusy(false);
        if (!result.ok) {
            toast.error(result.message, toaster);
            return;
        }
        toast.success('Foto archivada', toaster);
        setPhotos((current) => status === 'all'
            ? current.map((photo) => photo.id === result.photo.id ? result.photo : photo)
            : current.filter((photo) => photo.id !== result.photo.id));
        setSelected(null);
        setReason('');
    }

    async function restoreSelected() {
        if (!selected) return;
        setBusy(true);
        const result = await restorePhoto(selected.id);
        setBusy(false);
        if (!result.ok) {
            toast.error(result.message, toaster);
            return;
        }
        toast.success('Foto restaurada', toaster);
        setPhotos((current) => status === 'all'
            ? current.map((photo) => photo.id === result.photo.id ? result.photo : photo)
            : current.filter((photo) => photo.id !== result.photo.id));
        setSelected(null);
    }

    return (
        <AppLayout>
            <Head title={scope.name} />
            <Toaster id="photos" containerAriaLabel="Avisos de fotos" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-8">
                <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-sm text-slate-600">
                            {scope.site_url ? <a className="underline" href={scope.site_url}>{scope.job_site?.name}</a> : scope.job_site?.name}
                        </p>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{scope.name}</h1>
                        <p className="mt-2">
                            <span className="inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-800">{statusLabels[scope.status] ?? scope.status}</span>
                        </p>
                        {typeof scope.progress_percent === 'number' && (
                            <div className="mt-3 h-2 w-48 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={scope.progress_percent} aria-label="Avance del alcance">
                                <div className="h-full bg-slate-950" style={{ width: `${scope.progress_percent}%` }} />
                            </div>
                        )}
                        <p className="mt-2 text-sm text-slate-700">{scope.responsible?.name ?? 'Sin responsable'}</p>
                        <p className="text-sm text-slate-600">{scope.start_date ?? 'Sin inicio'} – {scope.estimated_end_date ?? 'Sin fin'}</p>
                    </div>
                    <a href={`/change-orders?job_scope_id=${scope.id}`} className="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold leading-[2.75rem] text-slate-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-950">Órdenes de cambio</a>
                    <a href={`/material-lists?job_scope_id=${scope.id}`} className="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold leading-[2.75rem] text-slate-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-950">Materiales</a>
                    {scope.can_edit && scope.edit_url && (
                        <a href={scope.edit_url} className="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold leading-[2.75rem] text-slate-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-950">Editar</a>
                    )}
                    {canCreate && (
                        <button type="button" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-950" onClick={() => setUploadOpen(true)}>
                            Añadir foto
                        </button>
                    )}
                </header>

                <div className="mb-4 flex gap-2" role="tablist" aria-label="Secciones del alcance">
                    {([['photos', 'Fotos'], ['shifts', 'Turnos'], ['detail', 'Detalle']] as const).map(([value, label]) => (
                        <button key={value} type="button" role="tab" aria-selected={section === value} className={`min-h-11 rounded-lg px-4 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-950 ${section === value ? 'bg-slate-950 text-white' : 'bg-white text-slate-800'}`} onClick={() => setSection(value)}>
                            {label}
                        </button>
                    ))}
                    <a role="tab" href={`/shifts/calendar?job_site_id=${scope.job_site?.id ?? ''}`} className="min-h-12 rounded-lg bg-white px-4 text-sm font-semibold leading-[3rem] text-slate-800">Calendario</a>
                    <a role="tab" href={`/change-orders?job_scope_id=${scope.id}`} className="min-h-12 rounded-lg bg-white px-4 text-sm font-semibold leading-[3rem] text-slate-800">Change Orders</a>
                    <a role="tab" href={`/material-lists?job_scope_id=${scope.id}`} className="min-h-12 rounded-lg bg-white px-4 text-sm font-semibold leading-[3rem] text-slate-800">Materiales</a>
                </div>
                {section === 'shifts' && (
                    <section aria-label="Turnos">
                        {shifts.length === 0 ? <p className="text-sm text-slate-600">No hay turnos en este alcance.</p> : (
                            <ul className="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                                {shifts.map((shift) => (
                                    <li key={shift.id} className="px-4 py-3 text-sm text-slate-700">
                                        <p className="font-semibold text-slate-950">{shift.worker ?? 'Sin trabajador'}</p>
                                        <p>{formatWhen(shift.starts_at, timeZone)} · {shift.status}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
                {section === 'detail' && (
                    <section aria-label="Detalle" className="space-y-3 text-sm text-slate-700">
                        <p><span className="font-semibold">Descripción. </span>{scope.description || 'Sin descripción'}</p>
                        <p><span className="font-semibold">Notas. </span>{scope.notes || 'Sin notas'}</p>
                    </section>
                )}
                {section === 'photos' && (
                <section aria-labelledby="progress-photos-heading">
                    <h2 id="progress-photos-heading" className="text-lg font-semibold text-slate-950">Fotos de avance</h2>
                    <div className="mt-3 flex gap-2" role="tablist" aria-label="Estado de las fotos">
                        {tabs.map((tab) => (
                            <button key={tab.value} type="button" role="tab" aria-selected={status === tab.value} className={`min-h-11 rounded-lg px-4 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-950 ${status === tab.value ? 'bg-slate-950 text-white' : 'bg-white text-slate-800'}`} onClick={() => setStatus(tab.value)}>
                                {tab.label}
                            </button>
                        ))}
                    </div>
                    {loading ? <LoadingState label="Cargando fotos…" /> : null}
                    {!loading && photos.length === 0 ? <p className="mt-4 text-sm text-slate-600">No hay fotos en esta pestaña.</p> : null}
                    <ul className="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                        {photos.map((photo) => (
                            <li key={photo.id}>
                                <button type="button" className="w-full overflow-hidden rounded-xl border border-slate-200 bg-white text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-950" aria-label={`Ver ${photo.caption || 'foto de avance'}`} onClick={() => { setSelected(photo); setReason(''); }}>
                                    {photo.thumbnail_url ? (
                                        <img className="aspect-square w-full object-cover" src={photo.thumbnail_url} alt="" referrerPolicy="no-referrer" />
                                    ) : (
                                        <span className="flex aspect-square items-center justify-center bg-slate-100 px-2 text-center text-xs text-slate-600">Foto archivada</span>
                                    )}
                                    <span className={`mx-2 mb-2 mt-2 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${photo.archived_at ? 'bg-slate-200 text-slate-700' : 'bg-emerald-100 text-emerald-800'}`}>
                                        {photo.archived_at ? 'Archivada' : 'Activa'}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                    {page < lastPage && (
                        <button type="button" className="mt-4 min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-950" onClick={loadMore}>
                            Cargar más
                        </button>
                    )}
                </section>
                )}
            </div>

            <Dialog open={selected !== null} onClose={() => setSelected(null)} className="relative z-50">
                <div className="fixed inset-0 bg-slate-950/50" aria-hidden="true" />
                <div className="fixed inset-0 overflow-y-auto p-4">
                    <DialogPanel className="mx-auto max-w-xl rounded-2xl bg-white p-5">
                        {selected && (
                            <>
                                <DialogTitle className="text-lg font-semibold text-slate-950">{selected.caption || 'Foto de avance'}</DialogTitle>
                                {selected.url ? (
                                    <img className="mt-4 max-h-96 w-full rounded-xl object-contain" src={selected.url} alt={selected.caption || 'Foto de avance'} referrerPolicy="no-referrer" />
                                ) : (
                                    <p className="mt-4 text-sm text-slate-600">Foto archivada. El enlace firmado no se emite hasta restaurarla.</p>
                                )}
                                <dl className="mt-4 space-y-2 text-sm text-slate-700">
                                    <div><dt className="font-semibold">Descripción</dt><dd>{selected.caption || 'Sin descripción'}</dd></div>
                                    <div><dt className="font-semibold">Captura</dt><dd>{formatWhen(selected.taken_at, timeZone)}</dd></div>
                                    <div><dt className="font-semibold">Subida por</dt><dd>{selected.uploaded_by?.name ?? 'Sin usuario'}</dd></div>
                                </dl>
                                {selected.lat !== null && selected.lng !== null && (
                                    <a className="mt-3 inline-flex underline" href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${selected.lat},${selected.lng}`)}`} target="_blank" rel="noopener noreferrer">
                                        Ver ubicación en Google Maps
                                    </a>
                                )}
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {selected.can_archive && (
                                        <>
                                            <label className="sr-only" htmlFor="archive-reason">Motivo de archivo</label>
                                            <input id="archive-reason" className="min-h-11 flex-1 rounded-lg border-slate-300 px-3 text-sm" placeholder="Motivo de archivo" maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} />
                                            <button type="button" className="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold disabled:opacity-60" disabled={busy} onClick={archiveSelected}>Archivar</button>
                                        </>
                                    )}
                                    {selected.can_restore && (
                                        <button type="button" className="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold disabled:opacity-60" disabled={busy} onClick={restoreSelected}>Restaurar</button>
                                    )}
                                    <button type="button" className="min-h-11 rounded-lg px-4 text-sm font-semibold text-slate-700" onClick={() => setSelected(null)}>Cerrar</button>
                                </div>
                            </>
                        )}
                    </DialogPanel>
                </div>
            </Dialog>

            <Dialog open={uploadOpen} onClose={() => { if (!uploading) setUploadOpen(false); }} className="relative z-50">
                <div className="fixed inset-0 bg-slate-950/50" aria-hidden="true" />
                <div className="fixed inset-0 overflow-y-auto p-4">
                    <DialogPanel className="mx-auto max-w-lg rounded-2xl bg-white p-5">
                        <DialogTitle className="text-lg font-semibold text-slate-950">Añadir foto</DialogTitle>
                        <form className="mt-4 grid gap-4" onSubmit={submitUpload}>
                            <label className="grid gap-2 text-sm font-semibold text-slate-800">
                                Imagen
                                <input className="text-sm" type="file" accept="image/jpeg,image/png,image/webp" disabled={uploading} onChange={(event) => chooseFile(event.target.files?.[0] ?? null)} />
                            </label>
                            {preview && <img className="max-h-48 rounded-xl object-contain" src={preview} alt="Vista previa de la foto seleccionada" />}
                            <label className="grid gap-2 text-sm font-semibold text-slate-800">
                                Descripción
                                <textarea className="rounded-lg border-slate-300" maxLength={500} value={caption} disabled={uploading} onChange={(event) => setCaption(event.target.value)} />
                            </label>
                            {uploading && (
                                <div className="h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress} aria-label="Progreso de subida">
                                    <div className="h-full bg-slate-950 motion-reduce:transition-none" style={{ width: `${progress}%` }} />
                                </div>
                            )}
                            <button type="submit" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-60" disabled={uploading}>
                                Subir
                            </button>
                        </form>
                    </DialogPanel>
                </div>
            </Dialog>
        </AppLayout>
    );
}
