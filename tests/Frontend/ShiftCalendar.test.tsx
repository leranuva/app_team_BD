// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Calendar from '@/Pages/Shifts/Calendar';

const revert = vi.hoisted(() => vi.fn());

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@fullcalendar/react', async () => {
    const { forwardRef, useCallback, useEffect, useImperativeHandle, useState } = await import('react');

    return {
        default: forwardRef(function TestCalendar(props: any, ref) {
            const [events, setEvents] = useState<any[]>([]);
            const load = useCallback(() => {
                const range = { startStr: '2026-11-16T00:00:00-05:00', endStr: '2026-11-23T00:00:00-05:00' };
                props.events(range, setEvents, () => {});
            }, [props.events]);
            useImperativeHandle(ref, () => ({ getApi: () => ({
                refetchEvents: load,
                getEventById: (id: string) => ({
                    setDates: (start: string, end: string) => setEvents((previous) => previous.map((event) => event.id === id ? { ...event, start, end } : event)),
                    setExtendedProp: () => {},
                    setProp: (key: string, value: unknown) => setEvents((previous) => previous.map(
                        (event) => event.id === id ? { ...event, [key]: value } : event,
                    )),
                }),
            }) }), [load]);
            useEffect(() => {
                load();
                load();
            }, [load]);
            const event = events[0];
            return (
                <div>
                    <button onClick={load}>Consultar mismo rango</button>
                    <output data-testid="editable">{String(props.editable)}</output>
                    <output data-testid="event-start">{event?.start}</output>
                    <output data-testid="event-color">{event?.backgroundColor}</output>
                    {event && <button onClick={() => props.eventDrop({
                        event: { ...event, start: new Date('2026-11-21T14:00:00Z'), end: new Date('2026-11-21T18:00:00Z'),
                            startStr: '2026-11-21T09:00:00-05:00', endStr: '2026-11-21T13:00:00-05:00' },
                        revert,
                    })}>Simular arrastre</button>}
                    {event && <button onClick={() => props.eventClick({ event })}>Abrir turno</button>}
                    {event && <button onClick={() => props.eventResize({
                        event: { ...event, start: new Date(event.start), end: new Date('2026-11-21T19:00:00Z'),
                            startStr: event.start, endStr: '2026-11-21T14:00:00-05:00' },
                        revert,
                    })}>Simular ajuste de duración</button>}
                </div>
            );
        }),
    };
});

const original = {
    id: 1, title: 'Turno de Carlos', start: '2026-11-21T08:00:00-05:00', end: '2026-11-21T12:00:00-05:00',
    updated_at: '2026-11-20T16:42:00-05:00', status: 'programmed', role_required: 'carpintero',
    extendedProps: { can_edit: true },
};
const options = {
    can_edit: true,
    job_sites: [{ id: 3, name: 'Brooklyn' }],
    statuses: [{ value: 'programmed', label: 'Scheduled' }],
    roles: [{ value: 'carpintero', label: 'Carpintero' }],
};
let coarse = false;

beforeEach(() => {
    coarse = false;
    window.history.replaceState(null, '', '/shifts/calendar');
    Object.defineProperty(window, 'matchMedia', { configurable: true, value: vi.fn((query: string) => ({
        matches: query === '(pointer: coarse)' && coarse,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    })) });
    vi.spyOn(axios, 'get').mockResolvedValue({ data: { data: [original] } });
});
afterEach(() => {
    toast.getToasts().forEach(({ id }) => toast.dismiss(id));
    cleanup();
    revert.mockReset();
    vi.restoreAllMocks();
});

async function mount() {
    render(<Calendar options={options} canEdit timeZone="America/New_York" />);
    return screen.findByRole('button', { name: 'Simular arrastre' });
}

describe('shift calendar', () => {
    it('starts from shared filters and deduplicates identical visible ranges', async () => {
        window.history.replaceState(null, '', '/shifts/calendar?job_site_id=3&status=programmed');
        await mount();
        expect((screen.getByRole('combobox', { name: 'Job site' }) as HTMLSelectElement).value).toBe('3');
        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(axios.get).toHaveBeenCalledWith('/api/shifts', expect.objectContaining({
            params: { from: '2026-11-09', to: '2026-11-30', job_site_id: '3', status: 'programmed' },
        }));
    });

    it('applies a successful drop and sends the version', async () => {
        const current = { ...original, start: '2026-11-21T09:00:00-05:00', updated_at: '2026-11-20T16:43:00-05:00' };
        const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: { data: current } });
        fireEvent.click(await mount());
        await screen.findByText('Shift rescheduled');
        expect(patch).toHaveBeenCalledWith('/api/shifts/1/reassign', {
            start_at: '2026-11-21T09:00:00-05:00', end_at: '2026-11-21T13:00:00-05:00', expected_updated_at: original.updated_at,
        }, expect.anything());
        expect(screen.getByTestId('event-start').textContent).toBe(current.start);
        expect(revert).not.toHaveBeenCalled();
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
    });

    it('uses current after 409 without reverting', async () => {
        const current = { ...original, start: '2026-11-21T11:00:00-05:00', updated_at: '2026-11-20T16:44:00-05:00', status: 'confirmed' };
        vi.spyOn(axios, 'patch').mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { current } } });
        fireEvent.click(await mount());
        await screen.findByText('Another user changed this shift. The calendar is now up to date.');
        expect(screen.getByTestId('event-start').textContent).toBe(current.start);
        expect(screen.getByTestId('event-color').textContent).toBe('#DCFCE7');
        expect(revert).not.toHaveBeenCalled();
    });

    it('reverts an overlapping drop after 422', async () => {
        vi.spyOn(axios, 'patch').mockRejectedValue({
            isAxiosError: true, response: { status: 422, data: { errors: { start_at: ['El turno se solapa.'] } } },
        });
        fireEvent.click(await mount());
        await screen.findByText('El turno se solapa.');
        expect(revert).toHaveBeenCalledOnce();
    });

    it('saves a resize through the same versioned endpoint', async () => {
        const current = { ...original, end: '2026-11-21T14:00:00-05:00' };
        const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: { data: current } });
        await mount();
        fireEvent.click(screen.getByRole('button', { name: 'Simular ajuste de duración' }));
        await screen.findByText('Shift rescheduled');
        expect(patch).toHaveBeenCalledWith('/api/shifts/1/reassign', {
            start_at: original.start, end_at: current.end, expected_updated_at: original.updated_at,
        }, expect.anything());
        expect(revert).not.toHaveBeenCalled();
    });

    it.each([
        [403, 'You do not have permission to reschedule this shift.'],
        [429, 'Too many requests. Try again shortly.'],
    ])('reverts after a %i response and shows the reason', async (status, message) => {
        vi.spyOn(axios, 'patch').mockRejectedValue({ isAxiosError: true, response: { status } });
        fireEvent.click(await mount());
        await screen.findByText(message);
        expect(revert).toHaveBeenCalledOnce();
    });

    it('offers a manual retry after a network error', async () => {
        const patch = vi.spyOn(axios, 'patch').mockRejectedValueOnce({ isAxiosError: true })
            .mockResolvedValueOnce({ data: { data: original } });
        fireEvent.click(await mount());
        fireEvent.click(await screen.findByRole('button', { name: 'Try again' }));
        await screen.findByText('Shift rescheduled');
        expect(patch).toHaveBeenCalledTimes(2);
        expect(revert).toHaveBeenCalledOnce();
    });

    it('disables dragging on touch screens', async () => {
        coarse = true;
        await mount();
        await waitFor(() => expect(screen.getByTestId('editable').textContent).toBe('false'));
        fireEvent.click(screen.getByRole('button', { name: 'Abrir turno' }));
        const dialog = await screen.findByRole('dialog');
        await waitFor(() => expect(dialog.contains(document.activeElement)).toBe(true));
        expect(screen.getByLabelText('Start time (New York)')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Save shift times' })).toBeTruthy();
        fireEvent.keyDown(window, { key: 'Escape' });
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    it('uses the refreshed version when saving again from the touch modal after a conflict', async () => {
        coarse = true;
        const current = { ...original, start: '2026-11-21T09:00:00-05:00', end: '2026-11-21T13:00:00-05:00',
            updated_at: '2026-11-20T16:44:00-05:00' };
        const patch = vi.spyOn(axios, 'patch')
            .mockRejectedValueOnce({ isAxiosError: true, response: { status: 409, data: { current } } })
            .mockResolvedValueOnce({ data: { data: current } });
        await mount();
        fireEvent.click(screen.getByRole('button', { name: 'Abrir turno' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Save shift times' }));
        await screen.findByText('Another user changed this shift. The calendar is now up to date.');
        expect((screen.getByLabelText('Start time (New York)') as HTMLInputElement).value).toBe('2026-11-21T09:00');
        fireEvent.click(screen.getByRole('button', { name: 'Save shift times' }));
        await waitFor(() => expect(patch).toHaveBeenCalledTimes(2));
        expect(patch).toHaveBeenNthCalledWith(2, '/api/shifts/1/reassign',
            expect.objectContaining({ expected_updated_at: current.updated_at }), expect.anything());
    });

    it('debounces two filter changes and caches their resulting range', async () => {
        await mount();
        fireEvent.change(screen.getByRole('combobox', { name: 'Job site' }), { target: { value: '3' } });
        fireEvent.change(screen.getByRole('combobox', { name: 'Status' }), { target: { value: 'programmed' } });
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
        expect(axios.get).toHaveBeenLastCalledWith('/api/shifts', expect.objectContaining({
            params: expect.objectContaining({ job_site_id: '3', status: 'programmed' }),
        }));
        fireEvent.click(screen.getByRole('button', { name: 'Consultar mismo rango' }));
        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(vi.mocked(window.matchMedia).mock.calls.filter(([query]) => query === '(pointer: coarse)')).toHaveLength(1);
    });

    it('requests the site carried in the url after an active-site change', async () => {
        window.history.replaceState(null, '', '/shifts/calendar?job_site_id=9&status=programmed');
        render(<Calendar options={{ ...options, job_sites: [...options.job_sites, { id: 9, name: 'Queens' }] }} canEdit timeZone="America/New_York" />);
        await waitFor(() => expect(axios.get).toHaveBeenCalledWith('/api/shifts', expect.objectContaining({
            params: expect.objectContaining({ job_site_id: '9', status: 'programmed' }),
        })));
        expect((screen.getByRole('combobox', { name: 'Job site' }) as HTMLSelectElement).value).toBe('9');
    });

    it('renders a read-only calendar for a read-only account', async () => {
        render(<Calendar options={{ ...options, can_edit: false }} canEdit={false} timeZone="America/New_York" />);
        await screen.findByRole('button', { name: 'Abrir turno' });
        expect(screen.getByTestId('editable').textContent).toBe('false');
    });
});
