// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import axios from 'axios';
import { Toaster, toast } from 'sonner';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ClockButton from '@/Components/Attendance/ClockButton';

const reload = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({ router: { reload } }));

const shift = { id: 7, starts_at: '2026-11-23T13:00:00Z', job_site: { name: 'Queens' } };
const timestamp = Date.parse('2026-11-23T13:00:00Z');
const geo = vi.fn();

beforeEach(() => {
    Object.defineProperty(navigator, 'geolocation', { configurable: true, value: { getCurrentPosition: geo } });
});
afterEach(() => {
    toast.getToasts().forEach(({ id }) => toast.dismiss(id));
    cleanup();
    geo.mockReset();
    reload.mockReset();
    vi.restoreAllMocks();
});

function mount() {
    render(<><Toaster id="attendance" /><ClockButton clock={{ open: null, shifts: [shift] }} timeZone="America/New_York" /></>);
}

it('requests location only on click and switches between entry and exit', async () => {
    const opened = { shift_id: shift.id, clocked_in_at: '2026-11-23T08:00:00-05:00', geofence_flagged: false };
    geo.mockImplementation((success) => success({ timestamp, coords: { latitude: 40.75, longitude: -73.9, accuracy: 20 } }));
    const post = vi.spyOn(axios, 'post')
        .mockResolvedValueOnce({ data: { data: opened, message: 'Entrada registrada.' } })
        .mockResolvedValueOnce({ data: { data: { ...opened, clocked_out_at: '2026-11-23T17:00:00-05:00', duration_minutes: 540 }, message: 'Salida registrada.' } });

    mount();
    expect(geo).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Registrar entrada' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registrar salida' }));
    await screen.findByText('Último turno registrado: 540 min');
    expect(post).toHaveBeenNthCalledWith(1, '/api/attendances/clock-in', {
        shift_id: 7, lat: 40.75, lng: -73.9, accuracy: 20, timestamp: new Date(timestamp).toISOString(),
    }, expect.anything());
    expect(post).toHaveBeenNthCalledWith(2, '/api/attendances/clock-out', expect.objectContaining({ shift_id: 7 }), expect.anything());
    expect(reload).toHaveBeenCalledTimes(2);
});

it('does not send a punch when location permission is denied', async () => {
    geo.mockImplementation((_success, fail) => fail({ code: 1 }));
    const post = vi.spyOn(axios, 'post');
    mount();
    fireEvent.click(screen.getByRole('button', { name: 'Registrar entrada' }));
    await screen.findByText('Permite el acceso a la ubicación para fichar.');
    expect(post).not.toHaveBeenCalled();
});
