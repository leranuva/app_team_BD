import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

const PENDING_CLOCK_KEY = 'pending-clock-in';

type PendingPunch = {
    shift_id?: number;
    job_site_id?: number;
    timestamp: string;
    lat: number;
    lng: number;
    accuracy: number;
    direction: 'in' | 'out';
};

type Punch = {
    shift_id: number; clocked_in_at: string; clocked_out_at?: string | null;
    geofence_flagged?: boolean; duration_minutes?: number | null;
    job_site?: { name: string } | null;
};
type ClockShift = {
    id: number;
    starts_at: string;
    available_from?: string | null;
    available_until?: string;
    job_site: { name: string } | null;
};

export type ClockPanel = {
    open: Punch | null;
    shifts: ClockShift[];
    sites?: { id: number; name: string }[];
};

function canClockIn(shift: ClockShift, now: number) {
    return (!shift.available_from || Date.parse(shift.available_from) <= now)
        && (!shift.available_until || now <= Date.parse(shift.available_until));
}

function reloadClock(field: boolean) {
    router.reload({ only: field ? ['clock', 'shifts'] : ['clock'] });
}

function getPosition(): Promise<GeolocationPosition> {
    if (!navigator.geolocation) {
        return Promise.reject(new Error('Location is unavailable in this browser. Open the portal over HTTPS or on localhost.'));
    }

    return new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, (error) => {
        const message = error.code === 1 ? 'Allow location access to clock in or out.'
            : error.code === 3 ? 'The location request timed out. Try again.'
            : 'Could not get your location. Check that GPS is enabled.';
        reject(new Error(message));
    }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15_000 }));
}

function browserStorage(): Storage | null {
    try {
        return window.localStorage ?? null;
    } catch {
        return null;
    }
}

function readPending(): PendingPunch | null {
    const store = browserStorage();
    if (!store) return null;
    try {
        const raw = store.getItem(PENDING_CLOCK_KEY);
        if (!raw) return null;
        const pending = JSON.parse(raw) as PendingPunch;
        if ((!pending?.shift_id && !pending?.job_site_id) || !pending.timestamp) {
            store.removeItem(PENDING_CLOCK_KEY);
            return null;
        }

        return pending;
    } catch {
        return null;
    }
}

async function flushPending(field: boolean): Promise<void> {
    const pending = readPending();
    if (!pending || navigator.onLine === false) return;

    try {
        const response = await axios.post(
            pending.direction === 'out' ? '/api/attendances/clock-out' : '/api/attendances/clock-in',
            {
                shift_id: pending.shift_id,
                job_site_id: pending.job_site_id,
                lat: pending.lat,
                lng: pending.lng,
                accuracy: pending.accuracy,
                timestamp: pending.timestamp,
            },
            { headers: { Accept: 'application/json' } },
        );
        browserStorage()?.removeItem(PENDING_CLOCK_KEY);
        toast.success(response.data.message, { toasterId: 'attendance' });
        reloadClock(field);
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 422) {
            browserStorage()?.removeItem(PENDING_CLOCK_KEY);
            toast.error('The time clock window closed. Contact your supervisor to correct the attendance record.', { toasterId: 'attendance' });
            return;
        }
    }
}

export default function ClockButton({ clock, timeZone, field = false }: { clock: ClockPanel; timeZone: string; field?: boolean }) {
    const [selectedId, setSelectedId] = useState('');
    const [optimistic, setOptimistic] = useState<{ source: ClockPanel; punch: Punch } | null>(null);
    const [lastDuration, setLastDuration] = useState<number | null>(null);
    const [stage, setStage] = useState<'idle' | 'location' | 'saving'>('idle');
    const [now, setNow] = useState(Date.now);
    const busy = useRef(false);
    const result = optimistic?.source === clock ? optimistic.punch : null;
    const open = result?.clocked_out_at ? null : result ?? clock.open;
    const choices = clock.shifts.filter((shift) => shift.id !== result?.shift_id || !result.clocked_out_at);
    const sites = clock.sites ?? [];
    const chosenSite = sites.find((site) => `site:${site.id}` === selectedId);
    const selected = chosenSite ? undefined : choices.find((shift) => String(shift.id) === selectedId)
        ?? choices.find((shift) => canClockIn(shift, now))
        ?? (sites.length === 0 ? choices[0] : undefined);
    const site = chosenSite ?? (!selected ? sites[0] : undefined);
    const selectedAvailable = Boolean(site || (selected && canClockIn(selected, now)));
    const format = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short', timeZone });
    const busyLabel = stage === 'location' ? 'Getting your location…' : 'Saving…';

    useEffect(() => {
        const sync = () => { void flushPending(field); };
        window.addEventListener('online', sync);
        if (navigator.onLine) sync();

        return () => window.removeEventListener('online', sync);
    }, [field]);

    useEffect(() => {
        if (!clock.shifts.some((shift) => shift.available_from || shift.available_until)) return;

        const timer = window.setInterval(() => setNow(Date.now()), 5_000);
        return () => window.clearInterval(timer);
    }, [clock.shifts]);

    async function punch() {
        const shiftId = open?.shift_id ?? selected?.id;
        if (busy.current || (!shiftId && !site) || (!open && !selectedAvailable)) return;
        if (readPending()) {
            toast.message('A time entry is still pending. Reconnect to sync it before recording another entry.', { toasterId: 'attendance' });
            return;
        }
        busy.current = true;
        setStage('location');
        try {
            const position = await getPosition();
            const payload: PendingPunch = {
                shift_id: shiftId,
                job_site_id: shiftId ? undefined : site?.id,
                lat: position.coords.latitude,
                lng: position.coords.longitude,
                accuracy: position.coords.accuracy,
                timestamp: new Date(position.timestamp).toISOString(),
                direction: open ? 'out' : 'in',
            };
            if (navigator.onLine === false) {
                const store = browserStorage();
                if (!store) {
                    toast.error('Could not save the time entry on this device.', { toasterId: 'attendance' });
                    return;
                }
                store.setItem(PENDING_CLOCK_KEY, JSON.stringify(payload));
                toast.message('Time entry pending sync. It will be sent when you reconnect.', { toasterId: 'attendance' });
                return;
            }
            setStage('saving');
            const response = await axios.post(open ? '/api/attendances/clock-out' : '/api/attendances/clock-in', {
                shift_id: payload.shift_id,
                ...(payload.job_site_id ? { job_site_id: payload.job_site_id } : {}),
                lat: payload.lat,
                lng: payload.lng,
                accuracy: payload.accuracy,
                timestamp: payload.timestamp,
            }, { headers: { Accept: 'application/json' } });

            const attendance: Punch = response.data.data;
            setOptimistic({ source: clock, punch: { ...attendance, job_site: open?.job_site ?? selected?.job_site ?? site } });
            if (attendance.clocked_out_at) setLastDuration(attendance.duration_minutes ?? null);
            if (attendance.geofence_flagged) {
                toast.warning(response.data.message + ' GPS accuracy needs review.', { toasterId: 'attendance' });
            } else {
                toast.success(response.data.message, { toasterId: 'attendance' });
            }
            reloadClock(field);
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const response = error.response;
                const errors = response?.data?.errors as Record<string, string[]> | undefined;
                const message = response?.status === 401 || response?.status === 419 ? 'Your session expired. Reload the page and sign in.'
                    : response?.status === 403 ? 'You do not have permission to clock in or out for this shift.'
                    : response?.status === 429 ? 'Too many attempts. Try again shortly.'
                    : Object.values(errors ?? {})[0]?.[0] ?? 'Could not confirm the time entry. Try again.';
                toast.error(message, { toasterId: 'attendance' });
                if (!response || response.status >= 500) reloadClock(field);
            } else {
                toast.error(error instanceof Error ? error.message : 'Could not get your location.', { toasterId: 'attendance' });
            }
        } finally {
            busy.current = false;
            setStage('idle');
        }
    }

    return (
        <section aria-label="Time clock" className={field
            ? 'mb-5 rounded-xl border border-slate-300 bg-slate-100 p-5 text-base text-slate-950'
            : 'mb-5 rounded-xl border border-slate-200 bg-white p-5'}>
            <h2 className="text-lg font-semibold text-slate-950">Time clock</h2>
            {open ? (
                <p className={field ? 'mt-2 text-base text-slate-950' : 'mt-2 text-sm text-slate-700'}>
                    {open.job_site?.name && <strong>{open.job_site.name} · </strong>}
                    Clocked in at {format.format(new Date(open.clocked_in_at))}
                    {(choices.length > 0 || sites.length > 0) && <span className="mt-1 block">Clock out here before starting at your next job site.</span>}
                </p>
            ) : choices.length > 0 || sites.length > 0 ? (
                <label className={field ? 'mt-3 grid max-w-md gap-2 text-base font-medium text-slate-950' : 'mt-3 grid max-w-md gap-2 text-sm font-medium'}>
                    {sites.length > 0 ? 'Job site or shift' : 'Shift'}
                    <select className={field ? 'min-h-16 rounded-lg border-slate-300 text-base' : 'min-h-11 rounded-lg border-slate-300'} value={site ? `site:${site.id}` : selected?.id ?? ''}
                        disabled={stage !== 'idle'} onChange={(event) => setSelectedId(event.target.value)}>
                        {choices.map((shift) => <option key={shift.id} value={shift.id}>
                            {shift.job_site?.name} · {format.format(new Date(shift.starts_at))}
                        </option>)}
                        {sites.length > 0 && <optgroup label="Resume work">
                            {sites.map((option) => <option key={option.id} value={`site:${option.id}`}>{option.name}</option>)}
                        </optgroup>}
                    </select>
                </label>
            ) : (
                <p className={field ? 'mt-2 text-base text-slate-950' : 'mt-2 text-sm text-slate-700'}>You have no shifts to clock in or out for.</p>
            )}
            {(open || selected || site) && (
                <button type="button" onClick={() => { void punch(); }} disabled={stage !== 'idle' || (!open && !selectedAvailable)}
                    className={field
                        ? 'mt-4 min-h-16 min-w-16 rounded-lg bg-slate-950 px-6 text-lg font-semibold text-white disabled:opacity-70'
                        : 'mt-4 min-h-12 min-w-12 rounded-lg bg-slate-900 px-6 font-semibold text-white disabled:opacity-70'}>
                    {stage === 'idle' ? (open ? 'Clock out' : 'Clock in') : busyLabel}
                </button>
            )}
            <p role="status" className={field ? 'mt-2 text-base text-slate-950' : 'mt-2 text-sm text-slate-700'}>
                {stage !== 'idle' && busyLabel}
                {stage === 'idle' && lastDuration !== null && `Last recorded shift: ${lastDuration} min`}
                {stage === 'idle' && !open && selected && !selectedAvailable && (
                    <span className="block">
                        {selected.available_from && now < Date.parse(selected.available_from)
                            ? `Clock-in opens ${format.format(new Date(selected.available_from))}.`
                            : 'Clock-in has closed for this shift.'}
                    </span>
                )}
            </p>
        </section>
    );
}
