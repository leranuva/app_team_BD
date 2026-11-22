import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';

type Row = {
    id: number;
    worker: string | null;
    clocked_in_at: string;
    clocked_out_at: string | null;
    regular_minutes: number | null;
    overtime_minutes: number | null;
};

function clock(value: string, timeZone: string) {
    return new Intl.DateTimeFormat('es-US', {
        weekday: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone,
    }).format(new Date(value));
}

export default function HoursApproval({ timeZone, week_start, rows, approval }: {
    timeZone: string;
    week_start: string;
    rows: Row[];
    approval: { available: boolean; note: string };
}) {
    return (
        <AppLayout header={<h1 className="text-2xl font-semibold text-slate-950">Horas de la semana</h1>}>
            <Head title="Horas de la semana" />
            <div className="mx-auto max-w-4xl px-4 py-6 text-base text-slate-950">
                <p>Semana desde {week_start}. Zona {timeZone}.</p>
                <p className="mt-3 rounded-lg bg-slate-200 p-4 font-medium">{approval.note}</p>
                <ul className="mt-5 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                    {rows.length === 0 && <li className="px-4 py-5">No hay asistencias esta semana.</li>}
                    {rows.map((row) => (
                        <li key={row.id} className="px-4 py-4">
                            <p className="font-semibold">{row.worker ?? 'Trabajador'}</p>
                            <p>Entrada: <time dateTime={row.clocked_in_at}>{clock(row.clocked_in_at, timeZone)}</time></p>
                            <p>Salida: {row.clocked_out_at ? <time dateTime={row.clocked_out_at}>{clock(row.clocked_out_at, timeZone)}</time> : '—'}</p>
                            <p>Regulares: {row.regular_minutes ?? 0} min · Extra: {row.overtime_minutes ?? 0} min</p>
                        </li>
                    ))}
                </ul>
                {approval.available && (
                    <button type="button" className="mt-4 min-h-12 rounded-lg bg-slate-950 px-4 font-semibold text-white">Aprobar horas</button>
                )}
            </div>
        </AppLayout>
    );
}
