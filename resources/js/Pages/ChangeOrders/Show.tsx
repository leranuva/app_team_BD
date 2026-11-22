import AppLayout from '@/Layouts/AppLayout';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useState } from 'react';
import { toast, Toaster } from 'sonner';

export type ChangeOrderDetail = {
    id: number;
    code: string | null;
    title: string | null;
    description: string | null;
    status: string;
    amount_dollars: number;
    time_impact_days: number;
    submitted_at: string | null;
    reviewed_at: string | null;
    approved_at: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    cancelled_reason: string | null;
    job_site?: { id: number; name: string } | null;
    job_scope?: { id: number; name: string } | null;
    requested_by: { id: number; name: string } | null;
    reviewed_by: { id: number; name: string } | null;
    approved_by: { id: number; name: string } | null;
    attachments: { original_name: string; url: string | null }[];
    can_submit: boolean;
    can_review: boolean;
    can_approve: boolean;
    can_reject: boolean;
    can_cancel: boolean;
};

const labels: Record<string, string> = {
    draft: 'Borrador', submitted: 'Enviada', under_review: 'En revisión',
    approved: 'Aprobada', rejected: 'Rechazada', cancelled: 'Cancelada',
};

const toaster = { toasterId: 'change-orders' } as const;

export default function Show({ order }: { order: ChangeOrderDetail }) {
    const [dialog, setDialog] = useState<'reject' | 'cancel' | null>(null);
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    async function post(path: string, body: Record<string, string> = {}) {
        setBusy(true);
        try {
            await axios.post(`/api/change-orders/${order.id}/${path}`, body, { headers: { Accept: 'application/json' } });
            toast.success('Orden actualizada', toaster);
            setDialog(null);
            setReason('');
            router.reload();
        } catch (error) {
            const message = axios.isAxiosError(error) ? error.response?.data?.message ?? 'No se pudo actualizar la orden.' : 'No se pudo actualizar la orden.';
            toast.error(message, toaster);
        } finally {
            setBusy(false);
        }
    }

    function confirmReason(event: FormEvent) {
        event.preventDefault();
        if (reason.trim() === '' || dialog === null) return;
        void post(dialog === 'reject' ? 'reject' : 'cancel', { reason: reason.trim() });
    }

    const timeline = [
        ['Enviada', order.submitted_at, order.requested_by?.name],
        ['En revisión', order.reviewed_at, order.reviewed_by?.name],
        ['Aprobada', order.approved_at, order.approved_by?.name],
        ['Rechazada', order.rejected_at, order.rejection_reason],
    ].filter((entry) => entry[1]);

    return (
        <AppLayout>
            <Head title={order.code ?? 'Orden de cambio'} />
            <Toaster id="change-orders" containerAriaLabel="Avisos de órdenes de cambio" />
            <div className="mx-auto max-w-3xl px-4 py-8 sm:px-8">
                <p className="text-sm text-slate-600">
                    {order.code}
                    {order.job_site ? <> · <a className="underline" href={`/change-orders?job_site_id=${order.job_site.id}`}>{order.job_site.name}</a></> : null}
                    {order.job_scope ? <> · <a className="underline" href={`/job-scopes/${order.job_scope.id}`}>{order.job_scope.name}</a></> : null}
                </p>
                <h1 className="text-3xl font-semibold text-slate-950">{order.title}</h1>
                <p className="mt-2"><span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-950">{labels[order.status] ?? order.status}</span></p>
                <p className="mt-3 text-lg">${order.amount_dollars.toFixed(2)} · {order.time_impact_days} días</p>
                <p className="mt-2 text-sm text-slate-700">{order.description}</p>
                <ol className="mt-6 space-y-2" aria-label="Línea de tiempo">
                    {timeline.map(([label, at, actor]) => (
                        <li key={String(label)}>{label}: {at}{actor ? ` · ${actor}` : ''}</li>
                    ))}
                </ol>
                <ul className="mt-6 space-y-2">
                    {order.attachments.map((file) => (
                        <li key={file.original_name}>
                            {file.url ? <a className="underline" href={file.url}>{file.original_name}</a> : file.original_name}
                        </li>
                    ))}
                </ul>
                <div className="mt-6 flex flex-wrap gap-2">
                    {order.can_submit && <button type="button" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white" disabled={busy} onClick={() => void post('submit')}>Enviar</button>}
                    {order.can_review && <button type="button" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white" disabled={busy} onClick={() => void post('start-review')}>Iniciar revisión</button>}
                    {order.can_approve && (
                        <form className="flex gap-2" onSubmit={(event) => { event.preventDefault(); void post('approve', note.trim() === '' ? {} : { note }); }}>
                            <input aria-label="Nota de aprobación" className="min-h-11 rounded border px-2" value={note} onChange={(event) => setNote(event.target.value)} />
                            <button type="submit" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white" disabled={busy}>Aprobar</button>
                        </form>
                    )}
                    {order.can_reject && <button type="button" className="min-h-11 rounded-lg border px-4 text-sm font-semibold" onClick={() => setDialog('reject')}>Rechazar</button>}
                    {order.can_cancel && <button type="button" className="min-h-11 rounded-lg border px-4 text-sm font-semibold" onClick={() => setDialog('cancel')}>Cancelar</button>}
                </div>
            </div>
            <Dialog open={dialog !== null} onClose={() => setDialog(null)} className="relative z-50" aria-modal="true">
                <div className="fixed inset-0 bg-slate-950/40" />
                <div className="fixed inset-0 flex items-center justify-center p-4">
                    <DialogPanel className="w-full max-w-md rounded-xl bg-white p-6">
                        <DialogTitle className="text-lg font-semibold">{dialog === 'cancel' ? 'Cancelar orden' : 'Rechazar orden'}</DialogTitle>
                        <form className="mt-4 space-y-3" onSubmit={confirmReason}>
                            <label className="block text-sm">Motivo
                                <textarea aria-label="Motivo" required className="mt-1 min-h-24 w-full rounded border p-2" value={reason} onChange={(event) => setReason(event.target.value)} />
                            </label>
                            <button type="submit" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white" disabled={reason.trim() === '' || busy}>Confirmar</button>
                        </form>
                    </DialogPanel>
                </div>
            </Dialog>
        </AppLayout>
    );
}
