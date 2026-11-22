import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { DateTime } from 'luxon';
import { useEffect, useState } from 'react';
import { Toaster } from 'sonner';
import type { ShiftEvent } from '@/Pages/Shifts/calendarApi';

function inputTime(value: string, zone: string) {
    return DateTime.fromISO(value, { zone }).toFormat("yyyy-MM-dd'T'HH:mm");
}

function toOffset(value: string, original: string, zone: string) {
    const date = DateTime.fromISO(value, { zone });
    if (!date.isValid || inputTime(date.toISO()!, zone) !== value) return null;
    const originalOffset = DateTime.fromISO(original, { zone }).offset;
    const possibilities = date.getPossibleOffsets();
    return (possibilities.find((instant) => instant.offset === originalOffset) ?? date).toISO();
}

export default function ShiftDetailModal({ event, zone, canEdit, touch, pending, statusLabel, roleLabel, onClose, onSave }: {
    event: ShiftEvent; zone: string; canEdit: boolean; touch: boolean; pending: boolean;
    statusLabel: string; roleLabel: string; onClose: () => void;
    onSave: (start: string, end: string) => Promise<boolean>;
}) {
    const [editing, setEditing] = useState(canEdit && touch);
    const [start, setStart] = useState(inputTime(event.start, zone));
    const [end, setEnd] = useState(inputTime(event.end, zone));
    const [error, setError] = useState('');
    useEffect(() => {
        setStart(inputTime(event.start, zone));
        setEnd(inputTime(event.end, zone));
        setError('');
    }, [event.start, event.end, event.updated_at, zone]);
    const format = (value: string) => DateTime.fromISO(value, { zone }).setLocale('en-US').toLocaleString(DateTime.DATETIME_MED);

    return (
        <Dialog open onClose={() => { if (!pending) onClose(); }} className="relative z-50">
            <div className="fixed inset-0 bg-slate-950/50" aria-hidden="true" />
            <div className="fixed inset-0 overflow-y-auto p-4 sm:p-8">
                <div className="flex min-h-full items-center justify-center">
                    <DialogPanel className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl sm:p-7">
                        <Toaster id="shift-modal" containerAriaLabel="Shift editing notifications" />
                        <DialogTitle className="text-xl font-semibold text-slate-950">{event.title}</DialogTitle>
                        <p className="mt-2 text-sm text-slate-700">{format(event.start)} – {format(event.end)}</p>
                        <dl className="mt-5 grid gap-3 text-sm text-slate-700">
                            <div><dt className="font-semibold">Job site</dt><dd>{event.job_site?.name ?? '—'}</dd>
                                {event.job_site?.address && <dd><a className="underline" target="_blank" rel="noreferrer" href={'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(event.job_site.address)}>{event.job_site.address}</a></dd>}
                            </div>
                            {event.job_scope && <div><dt className="font-semibold">Scope</dt><dd>{event.job_scope.name}</dd></div>}
                            {event.supervisor_name && <div><dt className="font-semibold">Supervisor</dt><dd>{event.supervisor_name}</dd></div>}
                            <div><dt className="font-semibold">Worker</dt><dd>{event.worker?.name ?? '—'}</dd></div>
                            <div><dt className="font-semibold">Required role</dt><dd>{roleLabel}</dd></div>
                            <div><dt className="font-semibold">Status</dt><dd>{statusLabel}</dd></div>
                            {event.notes && <div><dt className="font-semibold">Notes</dt><dd className="whitespace-pre-wrap">{event.notes}</dd></div>}
                            {event.updated_at && <div><dt className="font-semibold">Last updated</dt><dd>{format(event.updated_at)}</dd></div>}
                        </dl>
                        {editing && canEdit && (
                            <form id="shift-time-form" className="mt-5 grid gap-3" onSubmit={async (submit) => {
                                submit.preventDefault();
                                const from = toOffset(start, event.start, zone);
                                const to = toOffset(end, event.end, zone);
                                if (!from || !to) {
                                    setError('Enter a valid New York date and time.');
                                    return;
                                }
                                setError('');
                                if (await onSave(from, to)) setEditing(false);
                            }}>
                                <label className="grid gap-1 text-sm font-medium">Start time (New York)
                                    <input required type="datetime-local" className="min-h-11 rounded-lg border-slate-300" value={start} onChange={(e) => setStart(e.target.value)} />
                                </label>
                                <label className="grid gap-1 text-sm font-medium">End time (New York)
                                    <input required type="datetime-local" className="min-h-11 rounded-lg border-slate-300" value={end} onChange={(e) => setEnd(e.target.value)} />
                                </label>
                                {error && <p role="alert" className="text-sm text-red-800">{error}</p>}
                            </form>
                        )}
                        <div className="mt-6 flex flex-wrap justify-end gap-3">
                            <button type="button" disabled={pending} onClick={onClose} className="min-h-11 rounded-lg border border-slate-300 px-4">Close</button>
                            {canEdit && (editing
                                ? <button type="submit" form="shift-time-form" disabled={pending} className="min-h-11 rounded-lg bg-slate-900 px-4 text-white disabled:opacity-60">{pending ? 'Saving…' : 'Save shift times'}</button>
                                : <button type="button" onClick={() => setEditing(true)} className="min-h-11 rounded-lg bg-slate-900 px-4 text-white">Edit shift times</button>)}
                        </div>
                    </DialogPanel>
                </div>
            </div>
        </Dialog>
    );
}
