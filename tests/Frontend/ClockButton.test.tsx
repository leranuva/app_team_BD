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
        .mockResolvedValueOnce({ data: { data: opened, message: 'Clock-in recorded.' } })
        .mockResolvedValueOnce({ data: { data: { ...opened, clocked_out_at: '2026-11-23T17:00:00-05:00', duration_minutes: 540 }, message: 'Clock-out recorded.' } });

    mount();
    expect(geo).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Clock in' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Clock out' }));
    await screen.findByText('Last recorded shift: 540 min');
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
    fireEvent.click(screen.getByRole('button', { name: 'Clock in' }));
    await screen.findByText('Allow location access to clock in or out.');
    expect(post).not.toHaveBeenCalled();
});

it('offers the next job site as soon as the current punch closes', async () => {
    const next = { id: 9, starts_at: '2026-11-23T17:00:00Z', job_site: { name: 'Brooklyn' } };
    const opened = { shift_id: shift.id, clocked_in_at: shift.starts_at, job_site: shift.job_site };
    geo.mockImplementation((success) => success({ timestamp, coords: { latitude: 40.75, longitude: -73.9, accuracy: 20 } }));
    const post = vi.spyOn(axios, 'post')
        .mockResolvedValueOnce({ data: { data: { ...opened, clocked_out_at: '2026-11-23T16:00:00Z' }, message: 'Clock-out recorded.' } })
        .mockResolvedValueOnce({ data: { data: { shift_id: next.id, clocked_in_at: next.starts_at }, message: 'Clock-in recorded.' } });
    render(<ClockButton field clock={{ open: opened, shifts: [next] }} timeZone="America/New_York" />);

    fireEvent.click(screen.getByRole('button', { name: 'Clock out' }));
    expect((await screen.findByRole('combobox', { name: 'Shift' }) as HTMLSelectElement).value).toBe('9');
    fireEvent.click(screen.getByRole('button', { name: 'Clock in' }));
    await screen.findByRole('button', { name: 'Clock out' });
    expect(post).toHaveBeenNthCalledWith(2, '/api/attendances/clock-in', expect.objectContaining({ shift_id: next.id }), expect.anything());
    expect(reload).toHaveBeenCalledWith({ only: ['clock', 'shifts'] });
});

it('allows clocking in at another assigned site without a scheduled shift', async () => {
    const opened = { shift_id: shift.id, clocked_in_at: shift.starts_at, job_site: shift.job_site };
    geo.mockImplementation((success) => success({ timestamp, coords: { latitude: 40.75, longitude: -73.9, accuracy: 20 } }));
    const post = vi.spyOn(axios, 'post')
        .mockResolvedValueOnce({ data: { data: { ...opened, clocked_out_at: shift.starts_at }, message: 'Clock-out recorded.' } })
        .mockResolvedValueOnce({ data: { data: { shift_id: 15, clocked_in_at: shift.starts_at }, message: 'Clock-in recorded.' } });
    render(<ClockButton field clock={{
        open: opened, shifts: [], sites: [{ id: 1, name: 'Queens' }, { id: 2, name: 'Brooklyn' }],
    }} timeZone="America/New_York" />);

    fireEvent.click(screen.getByRole('button', { name: 'Clock out' }));
    const selector = await screen.findByRole('combobox', { name: 'Job site or shift' });
    fireEvent.change(selector, { target: { value: 'site:2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Clock in' }));
    await screen.findByRole('button', { name: 'Clock out' });
    expect(post).toHaveBeenNthCalledWith(2, '/api/attendances/clock-in', expect.objectContaining({ job_site_id: 2 }), expect.anything());
});
