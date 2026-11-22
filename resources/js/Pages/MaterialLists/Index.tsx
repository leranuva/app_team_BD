import EmptyState from '@/Components/EmptyState';
import AppLayout from '@/Layouts/AppLayout';
import { readActiveSite } from '@/lib/activeSite';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

export type MaterialListRow = {
    id: number;
    code: string | null;
    title: string | null;
    status: string;
    total_requested_value_cents: number | null;
    ordered_at: string | null;
    delivered_at: string | null;
    consumed_at: string | null;
    job_site: { id: number; name: string } | null;
    job_scope: { id: number; name: string } | null;
    requested_by: { id: number; name: string } | null;
};

const labels: Record<string, string> = {
    draft: 'Borrador', submitted: 'Enviada', approved: 'Aprobada', ordered: 'Ordenada',
    delivered: 'Entregada', partially_consumed: 'Consumo parcial', consumed: 'Consumida', cancelled: 'Cancelada',
    pending: 'Pendiente',
};

function money(cents: number | null): string {
    return cents === null ? 'sin costos' : `$${(cents / 100).toFixed(2)}`;
}

export default function Index({ lists, filters, canCreate, createUrl }: {
    lists: MaterialListRow[];
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
        router.get('/material-lists', { ...form, job_site_id: readActiveSite() }, { preserveState: false, replace: true });
    }, []);

    function apply(event: FormEvent) {
        event.preventDefault();
        router.get('/material-lists', form, { preserveState: true });
    }

    return (
        <AppLayout>
            <Head title="Listas de materiales" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-8">
                <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <h1 className="text-3xl font-semibold tracking-tight text-slate-950">Listas de materiales</h1>
                    {canCreate && (
                        <a href={createUrl} className="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-semibold leading-[2.75rem] text-white">Nueva</a>
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
                {lists.length === 0 ? <EmptyState title={canCreate ? 'No hay listas todavía. Crea la primera.' : 'No hay listas con ese filtro.'} action={canCreate ? { href: createUrl, label: 'Nueva' } : undefined} /> : (
                    <div className="overflow-x-auto">
                    <table className="w-full min-w-[40rem] text-left text-sm">
                        <thead>
                            <tr>
                                <th>Código</th><th>Título</th><th>Estado</th><th>Total</th><th>Entrega</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lists.map((list) => (
                                <tr key={list.id}>
                                    <td><a className="underline" href={`/material-lists/${list.id}`}>{list.code ?? 'Sin código'}</a></td>
                                    <td>{list.title}</td>
                                    <td><span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-950">{labels[list.status] ?? list.status}</span></td>
                                    <td>{money(list.total_requested_value_cents)}</td>
                                    <td>{list.delivered_at ?? '—'}</td>
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
