import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';

type Row = {
    id: number;
    job_site: string | null;
    clocked_in_at: string;
    clocked_out_at: string | null;
    duration_minutes: number | null;
    overtime_minutes: number | null;
};

function clock(value: string, timeZone: string) {
    return new Intl.DateTimeFormat('es-US', {
        day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone,
    }).format(new Date(value));
}

export default function History({ timeZone, attendances }: {
    timeZone: string;
    attendances: { data: Row[]; current_page: number; last_page: number };
}) {
    function go(page: number) {
        router.get('/worker/history', { page }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AppLayout header={<h1 className="text-2xl font-semibold text-slate-950">Historial</h1>}>
            <Head title="Historial de asistencia" />
            <div className="mx-auto max-w-3xl bg-slate-100 px-4 py-6 text-base text-slate-950">
                <p className="mb-4 text-base">Últimas 4 semanas. Solo consulta.</p>
                {attendances.data.length === 0 ? (
                    <p>No hay asistencias en este periodo.</p>
                ) : (
                    <ul className="divide-y divide-slate-300 rounded-xl border border-slate-300 bg-slate-100">
                        {attendances.data.map((row) => (
                            <li key={row.id} className="px-4 py-4">
                                <p className="font-semibold">{row.job_site ?? 'Obra'}</p>
                                <p className="mt-1">Entrada: <time dateTime={row.clocked_in_at}>{clock(row.clocked_in_at, timeZone)}</time></p>
                                <p>Salida: {row.clocked_out_at ? <time dateTime={row.clocked_out_at}>{clock(row.clocked_out_at, timeZone)}</time> : '—'}</p>
                                <p>Duración: {row.duration_minutes === null ? '—' : `${row.duration_minutes} min`}</p>
                                {row.overtime_minutes !== null && row.overtime_minutes > 0 && (
                                    <p>Horas extra: {row.overtime_minutes} min</p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-4 flex gap-3">
                    <button type="button" className="min-h-16 rounded-lg border border-slate-950 px-4 text-lg font-semibold disabled:opacity-40" disabled={attendances.current_page <= 1} onClick={() => go(attendances.current_page - 1)}>
                        Anterior
                    </button>
                    <button type="button" className="min-h-16 rounded-lg border border-slate-950 px-4 text-lg font-semibold disabled:opacity-40" disabled={attendances.current_page >= attendances.last_page} onClick={() => go(attendances.current_page + 1)}>
                        Siguiente
                    </button>
                </div>
            </div>
        </AppLayout>
    );
}
