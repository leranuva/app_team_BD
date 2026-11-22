import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import FullCalendar from '@fullcalendar/react';
import type { EventClickArg, EventDropArg, EventInput, EventMountArg, EventSourceFunc } from '@fullcalendar/core';
import dayGrid from '@fullcalendar/daygrid';
import timeGrid from '@fullcalendar/timegrid';
import list from '@fullcalendar/list';
import interaction, { type EventResizeDoneArg } from '@fullcalendar/interaction';
import luxon from '@fullcalendar/luxon3';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast, Toaster } from 'sonner';
import ShiftDetailModal from '@/Components/Shifts/ShiftDetailModal';
import { reassignShift, type Filters, type ShiftEvent } from './calendarApi';
import { useShiftsFeed } from './useShiftsFeed';
import { shiftAppearance } from '@/features/shifts/shiftColors';
import LoadingState from '@/Components/LoadingState';
import { readActiveSite, writeActiveSite } from '@/lib/activeSite';

const plugins = [dayGrid, timeGrid, list, interaction, luxon];

type Option = { value: string; label: string };
type Options = {
    job_sites: { id: number; name: string }[];
    statuses: Option[];
    roles: Option[];
    can_edit: boolean;
};

function readFilters(sites: Option[], options: Options, canEdit: boolean): Filters {
    const params = new URLSearchParams(window.location.search);

    function selectValue(key: keyof Filters, valid: Option[]) {
        const value = params.get(key) ?? '';
        return valid.some((option) => option.value === value) ? value : '';
    }

    const stored = readActiveSite();

    return {
        job_site_id: selectValue('job_site_id', sites) || (sites.some((site) => site.value === stored) ? stored : ''),
        role_required: canEdit ? selectValue('role_required', options.roles) : '',
        status: selectValue('status', options.statuses),
        worker_id: /^[1-9]\d*$/.test(params.get('worker_id') ?? '') ? params.get('worker_id')! : '',
    };
}

function FilterSelect({ label, defaultLabel, options, value, onChange }: {
    label: string;
    defaultLabel: string;
    options: Option[];
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className="grid gap-2 text-sm font-semibold text-slate-800">
            {label}
            <select
                className="min-h-11 w-full rounded-lg border-slate-300 bg-white text-base font-normal text-slate-950 focus:border-slate-600 focus:ring-slate-600"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">{defaultLabel}</option>
                {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
        </label>
    );
}

export function calendarInitialView(desktop: boolean): 'timeGridWeek' | 'listWeek' {
    return desktop ? 'timeGridWeek' : 'listWeek';
}

export default function Calendar({ options, canEdit, timeZone, title = 'Shift calendar' }: {
    options: Options; canEdit: boolean; timeZone: string; title?: string;
}) {
    const sites = options.job_sites.map(({ id, name }) => ({ value: String(id), label: name }));
    const [filters, setFilters] = useState(() => readFilters(sites, options, canEdit));
    const [touch] = useState(() => window.matchMedia('(pointer: coarse)').matches);
    const [initialView] = useState(() => calendarInitialView(window.matchMedia('(min-width: 768px)').matches));
    const [selected, setSelected] = useState<ShiftEvent | null>(null);
    const [pending, setPending] = useState<number | null>(null);
    const calendar = useRef<FullCalendar>(null);
    const saving = useRef(false);
    const { load, invalidate, loading, failure, retryLocked } = useShiftsFeed(filters, timeZone);

    useEffect(() => {
        const url = new URL(window.location.href);
        for (const [key, value] of Object.entries(filters)) {
            if (value) {
                url.searchParams.set(key, value);
            } else {
                url.searchParams.delete(key);
            }
        }
        if (url.href !== window.location.href) {
            window.history.replaceState(window.history.state, '', url);
        }
    }, [filters]);

    const source = useCallback<EventSourceFunc>((info, success, fail) => {
        const range = { from: info.startStr, to: info.endStr };
        load(range).then((shifts) => success(shifts.map((event): EventInput => ({
            id: String(event.id),
            title: event.title,
            start: event.start,
            end: event.end,
            editable: canEdit && !touch && event.extendedProps.can_edit && Boolean(event.updated_at),
            ...shiftAppearance(event.status),
            extendedProps: { ...event.extendedProps, updated_at: event.updated_at, shift: event },
        }))), (error) => fail(error));
    }, [load, canEdit, touch]);

    function refetch() {
        calendar.current?.getApi().refetchEvents();
    }

    async function save(event: ShiftEvent, start: string, end: string, revert?: () => void, toasterId = revert ? 'shifts' : 'shift-modal') {
        if (saving.current || !canEdit || !event.extendedProps.can_edit || !event.updated_at) {
            revert?.();
            return false;
        }
        saving.current = true;
        setPending(event.id);
        try {
            const result = await reassignShift(event, start, end);
            if (result.kind === 'failed') {
                toast.error(result.message, {
                    toasterId,
                    action: result.retryable && toasterId === 'shifts'
                        ? { label: 'Try again', onClick: () => { void save(event, start, end, undefined, toasterId); } }
                        : undefined,
                });
                revert?.();
                return false;
            }

            const item = calendar.current?.getApi().getEventById(String(result.event.id));
            item?.setDates(result.event.start, result.event.end);
            item?.setProp('title', result.event.title);
            for (const [key, value] of Object.entries(shiftAppearance(result.event.status))) {
                item?.setProp(key, value);
            }
            item?.setExtendedProp('shift', result.event);
            item?.setExtendedProp('updated_at', result.event.updated_at);
            for (const [key, value] of Object.entries(result.event.extendedProps)) {
                item?.setExtendedProp(key, value);
            }
            item?.setProp('editable', canEdit && !touch && result.event.extendedProps.can_edit && Boolean(result.event.updated_at));
            setSelected((previous) => previous?.id === result.event.id
                ? (result.kind === 'saved' ? null : result.event)
                : previous);
            invalidate();
            refetch();
            if (result.kind === 'conflict') {
                toast.warning(result.message, { toasterId });
            } else {
                toast.success(result.message, { toasterId: 'shifts' });
            }
            return result.kind === 'saved';
        } catch {
            toast.error('Could not save the shift. Try again.', { toasterId });
            revert?.();
            return false;
        } finally {
            saving.current = false;
            setPending(null);
        }
    }

    function onMove(info: EventDropArg | EventResizeDoneArg) {
        const event = info.event.extendedProps.shift as ShiftEvent | undefined;
        if (!event || !info.event.start || !info.event.end) {
            info.revert();
            return;
        }
        void save(event, info.event.startStr, info.event.endStr, info.revert);
    }

    function onEventClick(info: EventClickArg) {
        const event = info.event.extendedProps.shift as ShiftEvent | undefined;
        if (event) {
            setSelected(event);
        }
    }

    function decorateEvent(info: EventMountArg) {
        const { worker_name, job_site_name, role_required } = info.event.extendedProps;
        info.el.title = [worker_name, job_site_name, options.roles.find((role) => role.value === role_required)?.label].filter(Boolean).join(' · ');
    }

    function changeFilter(key: keyof Filters, value: string) {
        if (key === 'job_site_id') {
            writeActiveSite(value);
        }
        setFilters((previous) => ({ ...previous, [key]: value }));
    }

    return (
        <AppLayout>
            <Head title={title} />
            <Toaster id="shifts" containerAriaLabel="Calendar notifications" />
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-8">
                <h1 className="mb-6 text-3xl font-semibold tracking-tight text-slate-950">{title}</h1>
                <section aria-label="Shift filters" className={`grid gap-4 rounded-xl border border-slate-200 bg-white p-5 ${canEdit ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}`}>
                    <FilterSelect label="Job site" defaultLabel="All job sites" options={sites}
                        value={filters.job_site_id} onChange={(value) => changeFilter('job_site_id', value)} />
                    {canEdit && <FilterSelect label="Role" defaultLabel="All roles" options={options.roles}
                        value={filters.role_required} onChange={(value) => changeFilter('role_required', value)} />}
                    <FilterSelect label="Status" defaultLabel="All statuses" options={options.statuses}
                        value={filters.status} onChange={(value) => changeFilter('status', value)} />
                </section>
                <section aria-label="Scheduled shifts" className="mt-5 rounded-xl border border-slate-200 bg-white p-3 sm:p-5">
                    {loading ? <LoadingState label="Loading shifts…" /> : null}
                    {failure && (
                        <div role="alert" className="my-3 flex flex-wrap items-center gap-3 rounded-lg bg-amber-50 p-3 text-sm text-slate-900">
                            <span>{failure.message}</span>
                            {failure.forbidden
                                ? <a href="/dashboard" className="font-semibold underline">Back to operations</a>
                                : <button type="button" disabled={retryLocked}
                                    onClick={refetch}
                                    className="min-h-9 rounded border border-slate-500 px-3 font-semibold disabled:opacity-50">Try again</button>}
                        </div>
                    )}
                    <div hidden={failure?.forbidden} className="shift-calendar">
                        <FullCalendar ref={calendar} plugins={plugins} initialView={initialView} locale="en" timeZone={timeZone}
                            headerToolbar={{ start: 'prev,next today', center: 'title', end: 'dayGridMonth,timeGridWeek,listWeek' }}
                            buttonHints={{ prev: 'Previous date range', next: 'Next date range', today: 'Go to today', dayGridMonth: 'Month view', timeGridWeek: 'Week view', listWeek: 'List view' }}
                            height="auto" allDaySlot={false} nowIndicator dayMaxEvents weekNumbers firstDay={1}
                            events={source} lazyFetching={false} eventInteractive eventDidMount={decorateEvent}
                            editable={canEdit && !touch} eventResizableFromStart={canEdit && !touch}
                            eventClick={onEventClick} eventDrop={onMove} eventResize={onMove} />
                    </div>
                </section>
            </div>
            {selected && <ShiftDetailModal key={selected.id} event={selected} zone={timeZone}
                canEdit={canEdit && selected.extendedProps.can_edit && Boolean(selected.updated_at)} touch={touch}
                pending={pending === selected.id}
                statusLabel={options.statuses.find((status) => status.value === selected.status)?.label ?? '—'}
                roleLabel={options.roles.find((role) => role.value === selected.role_required)?.label ?? '—'}
                onClose={() => setSelected(null)} onSave={(start, end) => save(selected, start, end)} />}
        </AppLayout>
    );
}
