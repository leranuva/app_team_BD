import { router } from '@inertiajs/react';
import axios from 'axios';
import { useRef, useState } from 'react';
import { toast } from 'sonner';

type Punch = {
    shift_id: number; clocked_in_at: string; clocked_out_at?: string | null;
    geofence_flagged: boolean; duration_minutes?: number | null;
    job_site?: { name: string } | null;
};
type ClockPanel = {
    open: Punch | null;
    shifts: { id: number; starts_at: string; job_site: { name: string } | null }[];
};

function getPosition(): Promise<GeolocationPosition> {
    if (!navigator.geolocation) {
        return Promise.reject(new Error('Este navegador no ofrece ubicación. Abre el portal con HTTPS o en localhost.'));
    }

    return new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, (error) => {
        const message = error.code === 1 ? 'Permite el acceso a la ubicación para fichar.'
            : error.code === 3 ? 'Se agotó el tiempo para obtener la ubicación. Inténtalo de nuevo.'
            : 'No se pudo obtener la ubicación. Comprueba que el GPS esté activo.';
        reject(new Error(message));
    }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15_000 }));
}

export default function ClockButton({ clock, timeZone }: { clock: ClockPanel; timeZone: string }) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [optimistic, setOptimistic] = useState<{ source: ClockPanel; punch: Punch } | null>(null);
    const [lastDuration, setLastDuration] = useState<number | null>(null);
    const [stage, setStage] = useState<'idle' | 'location' | 'saving'>('idle');
    const busy = useRef(false);
    const result = optimistic?.source === clock ? optimistic.punch : null;
    const open = result?.clocked_out_at ? null : result ?? clock.open;
    const choices = clock.shifts.filter((shift) => shift.id !== result?.shift_id || !result.clocked_out_at);
    const selected = choices.find((shift) => shift.id === selectedId) ?? choices[0];
    const format = new Intl.DateTimeFormat('es-US', { dateStyle: 'medium', timeStyle: 'short', timeZone });
    const busyLabel = stage === 'location' ? 'Obteniendo ubicación…' : 'Registrando…';

    async function punch() {
        const shiftId = open?.shift_id ?? selected?.id;
        if (busy.current || !shiftId) return;
        busy.current = true;
        setStage('location');
        try {
            const position = await getPosition();
            setStage('saving');
            const response = await axios.post(open ? '/api/attendances/clock-out' : '/api/attendances/clock-in', {
                shift_id: shiftId,
                lat: position.coords.latitude,
                lng: position.coords.longitude,
                accuracy: position.coords.accuracy,
                timestamp: new Date(position.timestamp).toISOString(),
            }, { headers: { Accept: 'application/json' } });

            const attendance: Punch = response.data.data;
            setOptimistic({ source: clock, punch: { ...attendance, job_site: open?.job_site ?? selected?.job_site } });
            if (attendance.clocked_out_at) setLastDuration(attendance.duration_minutes ?? null);
            if (attendance.geofence_flagged) {
                toast.warning(response.data.message + ' La precisión del GPS requiere revisión.', { toasterId: 'attendance' });
            } else {
                toast.success(response.data.message, { toasterId: 'attendance' });
            }
            router.reload({ only: ['clock'] });
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const response = error.response;
                const errors = response?.data?.errors as Record<string, string[]> | undefined;
                const message = response?.status === 401 || response?.status === 419 ? 'Tu sesión expiró. Actualiza la página e inicia sesión.'
                    : response?.status === 403 ? 'Sin permiso para fichar en este turno.'
                    : response?.status === 429 ? 'Demasiados intentos. Espera un momento.'
                    : Object.values(errors ?? {})[0]?.[0] ?? 'No se pudo confirmar el fichaje. Inténtalo de nuevo.';
                toast.error(message, { toasterId: 'attendance' });
                if (!response || response.status >= 500) router.reload({ only: ['clock'] });
            } else {
                toast.error(error instanceof Error ? error.message : 'No se pudo obtener la ubicación.', { toasterId: 'attendance' });
            }
        } finally {
            busy.current = false;
            setStage('idle');
        }
    }

    return (
        <section aria-label="Fichaje" className="mb-5 rounded-xl border border-slate-200 bg-white p-5">
            <h2 className="text-lg font-semibold text-slate-950">Fichaje</h2>
            {open ? (
                <p className="mt-2 text-sm text-slate-700">
                    {open.job_site?.name && <strong>{open.job_site.name} · </strong>}
                    Entrada registrada a las {format.format(new Date(open.clocked_in_at))}
                </p>
            ) : choices.length > 0 ? (
                <label className="mt-3 grid max-w-md gap-2 text-sm font-medium">
                    Turno
                    <select className="min-h-11 rounded-lg border-slate-300" value={selected?.id ?? ''}
                        disabled={stage !== 'idle'} onChange={(event) => setSelectedId(Number(event.target.value))}>
                        {choices.map((shift) => <option key={shift.id} value={shift.id}>
                            {shift.job_site?.name} · {format.format(new Date(shift.starts_at))}
                        </option>)}
                    </select>
                </label>
            ) : (
                <p className="mt-2 text-sm text-slate-700">No tienes turnos pendientes de fichar.</p>
            )}
            {(open || selected) && (
                <button type="button" onClick={() => { void punch(); }} disabled={stage !== 'idle'}
                    className="mt-4 min-h-12 rounded-lg bg-slate-900 px-6 font-semibold text-white disabled:opacity-70">
                    {stage === 'idle' ? (open ? 'Registrar salida' : 'Registrar entrada') : busyLabel}
                </button>
            )}
            <p role="status" className="mt-2 text-sm text-slate-700">
                {stage !== 'idle' ? busyLabel : lastDuration !== null
                    ? 'Último turno registrado: ' + lastDuration + ' min' : ''}
            </p>
        </section>
    );
}
