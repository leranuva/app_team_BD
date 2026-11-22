import EmptyState from '@/Components/EmptyState';
import AppLayout from '@/Layouts/AppLayout';
import { readActiveSite } from '@/lib/activeSite';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

export type ChangeOrderListItem = {
    id: number;
    code: string | null;
    title: string | null;
    status: string;
    amount_dollars: number;
    time_impact_days: number;
    submitted_at: string | null;
    job_site: { id: number; name: string } | null;
    job_scope: { id: number; name: string } | null;
};

const labels: Record<string, string> = {
    draft: 'Draft', submitted: 'Submitted', under_review: 'Under review',
    approved: 'Approved', rejected: 'Rejected', cancelled: 'Canceled',
};

export default function Index({ orders, filters, canCreate, createUrl }: {
    orders: ChangeOrderListItem[];
    filters: { job_site_id?: string; job_scope_id?: string; status?: string };
    canCreate: boolean;
    createUrl: string;
}) {
    const [form, setForm] = useState({
        job_site_id: filters.job_site_id ?? '',
        job_scope_id: filters.job_scope_id ?? '',
        status: filters.status ?? '',
    });

    useEffect(() => {
        setForm({
            job_site_id: filters.job_site_id ?? '',
            job_scope_id: filters.job_scope_id ?? '',
            status: filters.status ?? '',
        });
    }, [filters.job_site_id, filters.job_scope_id, filters.status]);

    useEffect(() => {
        if (filters.job_site_id || !readActiveSite()) return;
        router.get('/change-orders', { ...form, job_site_id: readActiveSite() }, { preserveState: false, replace: true });
    }, []);

    function apply(event: FormEvent) {
        event.preventDefault();
        router.get('/change-orders', form, { preserveState: true });
    }

    return (
        <AppLayout>
            <Head title="Change orders" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-8">
                <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <h1 className="text-3xl font-semibold tracking-tight text-slate-950">Change orders</h1>
                    {canCreate && (
                        <a href={createUrl} className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold leading-[2.75rem] text-white">New</a>
                    )}
                </header>
                <form className="mb-4 flex flex-wrap gap-2" onSubmit={apply}>
                    <label className="text-sm">Job site
                        <input aria-label="Filter by job site" className="ml-2 min-h-11 rounded border px-2" value={form.job_site_id} onChange={(event) => setForm({ ...form, job_site_id: event.target.value })} />
                    </label>
                    <label className="text-sm">Scope
                        <input aria-label="Filter by scope" className="ml-2 min-h-11 rounded border px-2" value={form.job_scope_id} onChange={(event) => setForm({ ...form, job_scope_id: event.target.value })} />
                    </label>
                    <label className="text-sm">Status
                        <select aria-label="Filter by status" className="ml-2 min-h-11 rounded border px-2" value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>
                            <option value="">All</option>
                            {Object.entries(labels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>
                    <button type="submit" className="min-h-11 rounded-lg border px-4 text-sm font-semibold">Filter</button>
                </form>
                {orders.length === 0 ? <EmptyState title="No change orders match these filters." /> : (
                <div className="overflow-x-auto">
                <table className="w-full min-w-[40rem] text-left text-sm">
                    <thead>
                        <tr>
                            <th>Code</th><th>Title</th><th>Status</th><th>Amount</th><th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.map((order) => (
                            <tr key={order.id}>
                                <td><a className="underline" href={`/change-orders/${order.id}`}>{order.code ?? 'No code'}</a></td>
                                <td>{order.title}</td>
                                <td><span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-950">{labels[order.status] ?? order.status}</span></td>
                                <td>${order.amount_dollars.toFixed(2)}</td>
                                <td>{order.submitted_at ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                </div>
                )}
            </div>
        </AppLayout>
    );
}
