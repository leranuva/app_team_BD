import axios from 'axios';
import { DateTime } from 'luxon';

export type ShiftEvent = {
    id: number; title: string; start: string; end: string; updated_at: string | null;
    status: string; role_required: string | null; notes?: string | null; supervisor_name?: string | null;
    job_site: { id: number; name: string; address: string } | null;
    job_scope: { id: number; name: string } | null;
    worker: { id: number; name: string; role: string } | null;
    extendedProps: { worker_name: string | null; job_site_name: string | null; status: string; role_required: string | null; can_edit: boolean };
};

export type Range = { from: string; to: string };
export type Filters = { job_site_id: string; role_required: string; status: string; worker_id: string };

export function paddedRange(range: Range, zone: string): Range {
    return {
        from: DateTime.fromISO(range.from, { zone }).minus({ days: 7 }).toISODate()!,
        to: DateTime.fromISO(range.to, { zone }).plus({ days: 7 }).toISODate()!,
    };
}

export async function fetchShifts(range: Range, filters: Filters, signal: AbortSignal): Promise<ShiftEvent[]> {
    const params = { ...range, ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')) };
    const response = await axios.get('/api/shifts', { params, signal, headers: { Accept: 'application/json' } });
    return response.data.data;
}

export async function reassignShift(event: ShiftEvent, start: string, end: string) {
    try {
        const response = await axios.patch('/api/shifts/' + event.id + '/reassign', {
            start_at: start, end_at: end, expected_updated_at: event.updated_at,
        }, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        return { event: response.data.data as ShiftEvent, message: 'Turno reasignado', kind: 'saved' } as const;
    } catch (error) {
        if (!axios.isAxiosError(error)) throw error;
        const status = error.response?.status;
        const data = error.response?.data;
        if (status === 409 && data?.current) {
            return { event: data.current as ShiftEvent, message: 'Otro usuario modificó el turno; se actualizó la vista.', kind: 'conflict' } as const;
        }
        const validation = data?.errors as Record<string, string[]> | undefined;
        const message = status === 422
            ? validation?.start_at?.[0] ?? validation?.end_at?.[0] ?? Object.values(validation ?? {})[0]?.[0] ?? 'Revisa el horario.'
            : status === 403 ? 'Sin permisos para reasignar este turno.'
            : status === 429 ? 'Demasiadas operaciones, espera un momento.'
            : 'No se pudo guardar el turno. Inténtalo de nuevo.';
        return { kind: 'failed', message, retryable: status === undefined || status >= 500 } as const;
    }
}
