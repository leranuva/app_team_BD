// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import axios, { AxiosError } from 'axios';
import { Toaster, toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClockButton from '@/Components/Attendance/ClockButton';
import { installMemoryStorage } from './memoryStorage';

const reload = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({ router: { reload } }));

const shift = { id: 7, starts_at: '2026-11-23T13:00:00Z', job_site: { name: 'Queens' } };
const timestamp = Date.parse('2026-11-23T13:00:00Z');
const geo = vi.fn();
let online = true;

beforeEach(() => {
    installMemoryStorage();
    online = true;
    Object.defineProperty(navigator, 'geolocation', { configurable: true, value: { getCurrentPosition: geo } });
    Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => online });
    geo.mockImplementation((success: (position: { timestamp: number; coords: { latitude: number; longitude: number; accuracy: number } }) => void) => {
        success({ timestamp, coords: { latitude: 40.75, longitude: -73.9, accuracy: 12 } });
    });
});

afterEach(() => {
    toast.getToasts().forEach(({ id }) => toast.dismiss(id));
    cleanup();
    reload.mockReset();
    vi.restoreAllMocks();
});

function mount() {
    render(<><Toaster id="attendance" /><ClockButton clock={{ open: null, shifts: [shift] }} timeZone="America/New_York" /></>);
}

describe('offline punch', () => {
    it('stores the punch while offline and sends it when the connection returns', async () => {
        online = false;
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { data: {}, message: 'Clock-in recorded.' } });
        mount();
        fireEvent.click(screen.getByRole('button', { name: 'Clock in' }));
        await screen.findByText('Time entry pending sync. It will be sent when you reconnect.');
        expect(post).not.toHaveBeenCalled();
        const pending = JSON.parse(localStorage.getItem('pending-clock-in') ?? '{}');
        expect(pending).toMatchObject({ shift_id: 7, lat: 40.75, lng: -73.9, accuracy: 12 });

        online = true;
        window.dispatchEvent(new Event('online'));
        await screen.findByText('Clock-in recorded.');
        expect(post).toHaveBeenCalledWith('/api/attendances/clock-in', expect.objectContaining({ shift_id: 7, lat: 40.75 }), expect.anything());
        expect(localStorage.getItem('pending-clock-in')).toBeNull();
    });

    it('tells the worker to contact the supervisor when the window expired', async () => {
        const error = new AxiosError('expired');
        error.response = { status: 422, data: { errors: { timestamp: ['ventana'] } } } as AxiosError['response'];
        localStorage.setItem('pending-clock-in', JSON.stringify({
            shift_id: 7, lat: 1, lng: 2, accuracy: 3, timestamp: new Date(timestamp).toISOString(), direction: 'in',
        }));
        vi.spyOn(axios, 'post').mockRejectedValue(error);
        mount();
        await screen.findByText('The time clock window closed. Contact your supervisor to correct the attendance record.');
        expect(localStorage.getItem('pending-clock-in')).toBeNull();
    });
});
