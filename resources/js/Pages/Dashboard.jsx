import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ClockButton from '@/Components/Attendance/ClockButton';
import { Head } from '@inertiajs/react';
import { Toaster } from 'sonner';

function AssignmentList({ title, empty, items, renderDetails }) {
    return (
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 className="border-b border-slate-100 px-5 py-4 text-lg font-semibold text-slate-950">{title}</h2>
            {items.length ? (
                <ul className="divide-y divide-slate-100">
                    {items.map((item) => (
                        <li className="px-5 py-4 text-sm text-slate-600" key={item.id}>
                            {renderDetails(item)}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="px-5 py-5 text-sm text-slate-600">{empty}</p>
            )}
        </section>
    );
}

export default function Dashboard({ timeZone, canOpenAdmin, sites, shifts, lists, clock }) {
    const dateFormat = new Intl.DateTimeFormat('es-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone,
    });

    return (
        <AuthenticatedLayout>
            <Head title="Operaciones" />
            <Toaster id="attendance" containerAriaLabel="Avisos de fichaje" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-8 sm:py-12">
                <header className="mb-6 flex flex-wrap items-center justify-between gap-5 rounded-2xl bg-slate-950 px-6 py-8 text-white sm:px-9">
                    <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Operaciones de obra</h1>
                    {canOpenAdmin && (
                        <a href="/admin" className="inline-flex min-h-11 items-center rounded-lg bg-amber-300 px-5 text-sm font-semibold text-slate-950 hover:bg-amber-200">
                            Abrir panel administrativo
                        </a>
                    )}
                </header>
                <ClockButton clock={clock} timeZone={timeZone} />
                <div className="grid items-start gap-5 md:grid-cols-2">
                    <AssignmentList title="Próximos turnos" empty="No hay turnos próximos." items={shifts}
                        renderDetails={(shift) => (
                            <>
                                <p className="font-medium text-slate-900">{shift.job_site.name}</p>
                                <p className="mt-1"><time dateTime={shift.starts_at}>{dateFormat.format(new Date(shift.starts_at))}</time></p>
                                <p>Fin {dateFormat.format(new Date(shift.ends_at))}</p>
                            </>
                        )}
                    />
                    <AssignmentList title="Listas de materiales pendientes" empty="No hay listas pendientes." items={lists}
                        renderDetails={(list) => (
                            <>
                                <p className="font-medium text-slate-900">{list.name}</p>
                                <p className="mt-1">{list.job_site.name}</p>
                            </>
                        )}
                    />
                    {canOpenAdmin && (
                        <AssignmentList title="Obras" empty="Todavía no hay obras disponibles." items={sites}
                            renderDetails={(site) => (
                                <>
                                    <p className="font-medium text-slate-900">{site.name}</p>
                                    <p className="mt-1">{site.address}</p>
                                </>
                            )}
                        />
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
