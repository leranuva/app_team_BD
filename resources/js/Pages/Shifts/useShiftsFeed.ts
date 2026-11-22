import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { fetchShifts, paddedRange, type Filters, type Range, type ShiftEvent } from './calendarApi';

type Failure = { message: string; forbidden?: boolean; until?: number };
type Pending = { key: string; controller: AbortController; promise: Promise<ShiftEvent[]> };

export function useShiftsFeed(filters: Filters, zone: string) {
    const cache = useRef(new Map<string, { data: ShiftEvent[]; fetchedAt: number }>());
    const inFlight = useRef<Pending | null>(null);
    const retryAfter = useRef(0);
    const [failure, setFailure] = useState<Failure | null>(null);
    const [loading, setLoading] = useState(false);
    const [retryLocked, setRetryLocked] = useState(false);

    useEffect(() => () => inFlight.current?.controller.abort(), []);
    useEffect(() => {
        const remaining = (failure?.until ?? 0) - Date.now();
        setRetryLocked(remaining > 0);
        if (remaining <= 0) return;
        const timer = window.setTimeout(() => setRetryLocked(false), remaining);
        return () => window.clearTimeout(timer);
    }, [failure]);

    const load = useCallback((range: Range): Promise<ShiftEvent[]> => {
        const key = JSON.stringify([range.from, range.to, filters.job_site_id, filters.status, filters.role_required, filters.worker_id]);
        const previous = inFlight.current;
        if (previous?.key === key) return previous.promise;
        previous?.controller.abort();

        const cached = cache.current.get(key);
        if (cached && Date.now() - cached.fetchedAt < 60_000) {
            setFailure(null);
            setLoading(false);
            return Promise.resolve(cached.data);
        }
        if (Date.now() < retryAfter.current) {
            setLoading(false);
            return Promise.reject(new Error('Wait before trying again.'));
        }

        const controller = new AbortController();
        const { signal } = controller;
        const promise = (async () => {
            try {
                await new Promise<void>((resolve, reject) => {
                    const timer = window.setTimeout(resolve, 300);
                    signal.addEventListener('abort', () => {
                        window.clearTimeout(timer);
                        reject(signal.reason);
                    }, { once: true });
                });
                setLoading(true);
                setFailure(null);
                let result: ShiftEvent[];
                try {
                    result = await fetchShifts(paddedRange(range, zone), filters, signal);
                } catch (error) {
                    if (signal.aborted || !axios.isAxiosError(error) || error.response?.status !== 422) throw error;
                    toast.warning('Retried with the visible date range.', { toasterId: 'shifts' });
                    result = await fetchShifts(range, filters, signal);
                }
                if (signal.aborted) throw signal.reason;
                if (cache.current.size >= 30) cache.current.delete(cache.current.keys().next().value!);
                cache.current.set(key, { fetchedAt: Date.now(), data: result });
                return result;
            } catch (error) {
                if (signal.aborted) throw error;
                const response = axios.isAxiosError(error) ? error.response : undefined;
                if (response?.status === 403 || response?.status === 401) {
                    setFailure({ message: 'You do not have permission to view this calendar.', forbidden: true });
                } else if (response?.status === 429) {
                    const header = response.headers['retry-after'];
                    const delay = header == null ? 0 : Math.max(0, Number.isFinite(Number(header))
                        ? Number(header) * 1000 : Date.parse(String(header)) - Date.now()) || 0;
                    retryAfter.current = Date.now() + delay;
                    setFailure({
                        message: delay ? 'Too many requests. Try again in ' + Math.ceil(delay / 1000) + ' s.' : 'Too many requests. Try again shortly.',
                        until: retryAfter.current,
                    });
                } else {
                    setFailure({ message: response?.status === 422
                        ? 'Too many shifts in this date range. Switch to week view or adjust the filters.'
                        : 'Could not load shifts. Try again.' });
                }
                throw error;
            } finally {
                if (inFlight.current?.controller === controller) {
                    inFlight.current = null;
                    setLoading(false);
                }
            }
        })();
        inFlight.current = { key, controller, promise };
        return promise;
    }, [filters, zone]);

    const invalidate = useCallback(() => {
        const pending = inFlight.current;
        inFlight.current = null;
        pending?.controller.abort();
        cache.current.clear();
        setLoading(false);
    }, []);

    return { load, invalidate, loading, failure, retryLocked };
}
