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
    draft: 'Borrador', submitted: 'Enviada', under_review: 'En revisión',
    approved: 'Aprobada', rejected: 'Rechazada', cancelled: 'Cancelada',
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
            <Head title="Órdenes de cambio" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-8">
                <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <h1 className="text-3xl font-semibold tracking-tight text-slate-950">Órdenes de cambio</h1>
                    {canCreate && (
                        <a href={createUrl} className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold leading-[2.75rem] text-white">Nuevo</a>
                    )}
                </header>
                <form className="mb-4 flex flex-wrap gap-2" onSubmit={apply}>
                    <label className="text-sm">Obra
                        <input aria-label="Filtrar por obra" className="ml-2 min-h-11 rounded border px-2" value={form.job_site_id} onChange={(event) => setForm({ ...form, job_site_id: event.target.value })} />
                    </label>
                    <label className="text-sm">Alcance
                        <input aria-label="Filtrar por alcance" className="ml-2 min-h-11 rounded border px-2" value={form.job_scope_id} onChange={(event) => setForm({ ...form, job_scope_id: event.target.value })} />
                    </label>
                    <label className="text-sm">Estado
                        <select aria-label="Filtrar por estado" className="ml-2 min-h-11 rounded border px-2" value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>
                            <option value="">Todos</option>
                            {Object.entries(labels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>
                    <button type="submit" className="min-h-11 rounded-lg border px-4 text-sm font-semibold">Filtrar</button>
                </form>
                {orders.length === 0 ? <EmptyState title="No hay órdenes con ese filtro." /> : (
                <div className="overflow-x-auto">
                <table className="w-full min-w-[40rem] text-left text-sm">
                    <thead>
                        <tr>
                            <th>Código</th><th>Título</th><th>Estado</th><th>Monto</th><th>Enviada</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.map((order) => (
                            <tr key={order.id}>
                                <td><a className="underline" href={`/change-orders/${order.id}`}>{order.code ?? 'Sin código'}</a></td>
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
