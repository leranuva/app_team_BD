import AppLayout from '@/Layouts/AppLayout';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useState } from 'react';
import { toast, Toaster } from 'sonner';

export type MaterialListItemRow = {
    id: number;
    budgeted_quantity: string | null;
    requested_quantity: string | null;
    approved_quantity: string | null;
    delivered_quantity: string | null;
    consumed_quantity: string | null;
    unit_cost_cents: number | null;
    material: { id: number; name: string; unit: string; reference_photo_url?: string | null } | null;
};

export type MaterialListDetail = {
    id: number;
    code: string | null;
    title: string | null;
    status: string;
    job_site: { id: number; name: string } | null;
    job_scope: { id: number; name: string } | null;
    ordered_at: string | null;
    delivered_at: string | null;
    consumed_at: string | null;
    cancelled_reason: string | null;
    items: MaterialListItemRow[];
    can_submit: boolean;
    can_approve: boolean;
    can_mark_ordered: boolean;
    can_register_delivery: boolean;
    can_register_consumption: boolean;
    can_cancel: boolean;
};

const labels: Record<string, string> = {
    draft: 'Draft', submitted: 'Submitted', approved: 'Approved', ordered: 'Ordered',
    delivered: 'Delivered', partially_consumed: 'Partially consumed', consumed: 'Consumed', cancelled: 'Canceled',
};

const toaster = { toasterId: 'material-lists' } as const;

export default function Show({ list }: { list: MaterialListDetail }) {
    const [dialog, setDialog] = useState<'approve' | 'delivery' | 'consumption' | 'cancel' | null>(null);
    const [reason, setReason] = useState('');
    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const [busy, setBusy] = useState(false);

    function openQuantities(next: 'approve' | 'delivery' | 'consumption') {
        const seed: Record<number, string> = {};
        list.items.forEach((item) => {
            seed[item.id] = next === 'approve'
                ? (item.approved_quantity ?? item.requested_quantity ?? '')
                : (next === 'delivery' ? (item.delivered_quantity ?? item.approved_quantity ?? '') : (item.consumed_quantity ?? ''));
        });
        setQuantities(seed);
        setDialog(next);
    }

    const quantitiesReady = list.items.every((item) => (quantities[item.id] ?? '').trim() !== '');

    async function post(path: string, body: Record<string, unknown> = {}) {
        setBusy(true);
        try {
            await axios.post(`/api/material-lists/${list.id}/${path}`, body, { headers: { Accept: 'application/json' } });
            toast.success('List updated', toaster);
            setDialog(null);
            setReason('');
            router.reload();
        } catch (error) {
            const message = axios.isAxiosError(error) ? error.response?.data?.message ?? 'Could not update the list.' : 'Could not update the list.';
            toast.error(message, toaster);
        } finally {
            setBusy(false);
        }
    }

    function confirm(event: FormEvent) {
        event.preventDefault();
        if (dialog === 'cancel') {
            if (reason.trim() === '') return;
            void post('cancel', { reason: reason.trim() });
            return;
        }
        if (!quantitiesReady || dialog === null) return;
        const payload: Record<string, string> = {};
        list.items.forEach((item) => { payload[String(item.id)] = quantities[item.id]; });
        const path = dialog === 'approve' ? 'approve' : dialog === 'delivery' ? 'register-delivery' : 'register-consumption';
        const key = dialog === 'approve' ? 'approved' : dialog === 'delivery' ? 'delivered' : 'consumed';
        void post(path, { [key]: payload });
    }

    const timeline = [
        ['Ordered', list.ordered_at],
        ['Delivered', list.delivered_at],
        ['Consumed', list.consumed_at],
    ].filter((entry) => entry[1]);

    return (
        <AppLayout>
            <Head title={list.code ?? 'Material list'} />
            <Toaster id="material-lists" containerAriaLabel="Material list notifications" />
            <div className="mx-auto max-w-4xl px-4 py-8 sm:px-8">
                <p className="text-sm text-slate-600">
                    {list.code}
                    {list.job_site ? <> · <a className="underline" href={`/material-lists?job_site_id=${list.job_site.id}`}>{list.job_site.name}</a></> : null}
                    {list.job_scope ? <> · <a className="underline" href={`/job-scopes/${list.job_scope.id}`}>{list.job_scope.name}</a></> : null}
                </p>
                <h1 className="text-3xl font-semibold text-slate-950">{list.title}</h1>
                <p className="mt-2"><span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-950">{labels[list.status] ?? list.status}</span></p>
                <ol className="mt-6 space-y-2" aria-label="Timeline">
                    {timeline.map(([label, at]) => <li key={String(label)}>{label}: {at}</li>)}
                    {list.cancelled_reason ? <li>Canceled: {list.cancelled_reason}</li> : null}
                </ol>
                <table className="mt-6 w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>Material</th><th>Budgeted</th><th>Requested</th><th>Approved</th><th>Delivered</th><th>Consumed</th><th>Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        {list.items.map((item) => (
                            <tr key={item.id}>
                                <td className="py-2 pr-3">
                                    <div className="flex items-center gap-3">
                                        {item.material?.reference_photo_url && (
                                            <a href={item.material.reference_photo_url} target="_blank" rel="noreferrer"
                                                aria-label={`Open reference photo of ${item.material.name}`}>
                                                <img src={item.material.reference_photo_url} alt="" loading="lazy"
                                                    className="h-14 w-16 max-w-none rounded border border-slate-200 bg-white object-contain" />
                                            </a>
                                        )}
                                        {item.material?.name}
                                    </div>
                                </td>
                                <td>{item.budgeted_quantity}</td>
                                <td>{item.requested_quantity}</td>
                                <td>{item.approved_quantity ?? '—'}</td>
                                <td>{item.delivered_quantity ?? '—'}</td>
                                <td>{item.consumed_quantity ?? '—'}</td>
                                <td>{item.unit_cost_cents === null ? '—' : item.unit_cost_cents}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="mt-6 flex flex-wrap gap-2">
                    {list.can_submit && <button type="button" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white" disabled={busy} onClick={() => void post('submit')}>Submit</button>}
                    {list.can_approve && <button type="button" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white" onClick={() => openQuantities('approve')}>Approve</button>}
                    {list.can_mark_ordered && <button type="button" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white" disabled={busy} onClick={() => void post('mark-ordered')}>Mark as ordered</button>}
                    {list.can_register_delivery && <button type="button" className="min-h-11 rounded-lg border px-4 text-sm font-semibold" onClick={() => openQuantities('delivery')}>Record delivery</button>}
                    {list.can_register_consumption && <button type="button" className="min-h-11 rounded-lg border px-4 text-sm font-semibold" onClick={() => openQuantities('consumption')}>Record usage</button>}
                    {list.can_cancel && <button type="button" className="min-h-11 rounded-lg border px-4 text-sm font-semibold" onClick={() => setDialog('cancel')}>Cancel</button>}
                </div>
            </div>
            <Dialog open={dialog !== null} onClose={() => setDialog(null)} className="relative z-50" aria-modal="true">
                <div className="fixed inset-0 bg-slate-950/40" />
                <div className="fixed inset-0 flex items-center justify-center p-4">
                    <DialogPanel className="w-full max-w-md rounded-xl bg-white p-6">
                        <DialogTitle className="text-lg font-semibold">
                            {dialog === 'cancel' ? 'Cancel list' : dialog === 'approve' ? 'Approve quantities' : dialog === 'delivery' ? 'Record delivery' : 'Record usage'}
                        </DialogTitle>
                        <form className="mt-4 space-y-3" onSubmit={confirm}>
                            {dialog === 'cancel' ? (
                                <label className="block text-sm">Reason
                                    <textarea aria-label="Reason" required className="mt-1 min-h-24 w-full rounded border p-2" value={reason} onChange={(event) => setReason(event.target.value)} />
                                </label>
                            ) : list.items.map((item) => (
                                <label key={item.id} className="block text-sm">{item.material?.name}
                                    <input aria-label={`Quantity for ${item.material?.name ?? 'material'}`} required className="mt-1 min-h-11 w-full rounded border px-2" value={quantities[item.id] ?? ''} onChange={(event) => setQuantities({ ...quantities, [item.id]: event.target.value })} />
                                </label>
                            ))}
                            <button type="submit" className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white" disabled={busy || (dialog === 'cancel' ? reason.trim() === '' : !quantitiesReady)}>Confirm</button>
                        </form>
                    </DialogPanel>
                </div>
            </Dialog>
        </AppLayout>
    );
}
