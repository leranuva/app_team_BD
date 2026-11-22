// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import axios, { AxiosError } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TeamToday from '@/Pages/Supervisor/TeamToday';
import { installMemoryStorage } from './memoryStorage';

const reload = vi.hoisted(() => vi.fn());
const get = vi.hoisted(() => vi.fn());
vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { reload, get } }));

const sites = [{ id: 3, name: 'Queens' }, { id: 4, name: 'Brooklyn' }];
const team = [{
    id: 9,
    name: 'Luis Peña',
    status: 'clocked_in' as const,
    job_site: 'Queens',
    clocked_in_at: '2026-11-23T13:00:00Z',
    attendance_id: 15,
    can_correct: true,
}];

beforeEach(() => {
    installMemoryStorage();
    vi.useFakeTimers();
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    reload.mockReset();
    get.mockReset();
    vi.restoreAllMocks();
});

describe('supervisor team today', () => {
    it('renders the team and filters by site', () => {
        render(<TeamToday timeZone="America/New_York" sites={sites} jobSiteId={3} team={team} />);
        expect(screen.getByText('Luis Peña')).toBeTruthy();
        expect(screen.getByText(/Clocked in/)).toBeTruthy();
        expect(screen.getByText(/08:00/)).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Job site'), { target: { value: '4' } });
        expect(get).toHaveBeenCalledWith('/supervisor/team', { job_site_id: '4' }, expect.anything());
    });

    it('reloads the team every minute and clears the interval on unmount and site change', () => {
        const view = render(<TeamToday timeZone="America/New_York" sites={sites} jobSiteId={3} team={team} />);
        expect(reload).not.toHaveBeenCalled();
        vi.advanceTimersByTime(60_000);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith({ only: ['team'] });

        view.rerender(<TeamToday timeZone="America/New_York" sites={sites} jobSiteId={4} team={team} />);
        vi.advanceTimersByTime(60_000);
        expect(reload).toHaveBeenCalledTimes(2);

        view.unmount();
        vi.advanceTimersByTime(60_000);
        expect(reload).toHaveBeenCalledTimes(2);
    });

    it('sends a correction when the backend allows it', async () => {
        vi.useRealTimers();
        let resolve: (value: { data: object }) => void = () => {};
        const patch = vi.spyOn(axios, 'patch').mockReturnValue(new Promise((done) => { resolve = done; }));
        render(<TeamToday timeZone="America/New_York" sites={sites} jobSiteId={3} team={team} />);
        fireEvent.click(screen.getByRole('button', { name: 'Correct attendance' }));
        fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Olvidó fichar la salida' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save correction' }));
        const save = screen.getByRole('button', { name: 'Save correction' });
        expect(save).toHaveProperty('disabled', true);
        resolve({ data: {} });
        await screen.findByText('Attendance corrected. Weekly hours are being recalculated.');
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(patch).toHaveBeenCalledWith('/api/attendances/15/correct', expect.objectContaining({
            correction_reason: 'Olvidó fichar la salida',
        }), expect.anything());
        expect(reload).toHaveBeenCalledWith({ only: ['team'] });
    });

    it('closes the modal when the supervisor lacks permission', async () => {
        vi.useRealTimers();
        const error = new AxiosError('forbidden');
        error.response = { status: 403, data: {} } as AxiosError['response'];
        vi.spyOn(axios, 'patch').mockRejectedValue(error);
        render(<TeamToday timeZone="America/New_York" sites={sites} jobSiteId={3} team={team} />);
        fireEvent.click(screen.getByRole('button', { name: 'Correct attendance' }));
        fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Olvidó fichar la salida' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save correction' }));
        await screen.findByText('You do not have permission to correct this attendance record.');
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('shows field errors for a 422 response', async () => {
        vi.useRealTimers();
        const error = new AxiosError('invalid');
        error.response = { status: 422, data: { errors: { correction_reason: ['The reason must contain at least 10 characters.'] } } } as AxiosError['response'];
        vi.spyOn(axios, 'patch').mockRejectedValue(error);
        render(<TeamToday timeZone="America/New_York" sites={sites} jobSiteId={3} team={team} />);
        fireEvent.click(screen.getByRole('button', { name: 'Correct attendance' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save correction' }));
        await screen.findByText('The reason must contain at least 10 characters.');
        expect(screen.getByRole('dialog')).toBeTruthy();
    });

    it('refreshes the list when the attendance no longer exists', async () => {
        vi.useRealTimers();
        const error = new AxiosError('missing');
        error.response = { status: 404, data: {} } as AxiosError['response'];
        vi.spyOn(axios, 'patch').mockRejectedValue(error);
        render(<TeamToday timeZone="America/New_York" sites={sites} jobSiteId={3} team={team} />);
        fireEvent.click(screen.getByRole('button', { name: 'Correct attendance' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save correction' }));
        await screen.findByText('This attendance record no longer exists.');
        expect(reload).toHaveBeenCalledWith({ only: ['team'] });
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('hides correction when the worker cannot be corrected', () => {
        render(<TeamToday timeZone="America/New_York" sites={sites} jobSiteId={3} team={[{ ...team[0], can_correct: false }]} />);
        expect(screen.queryByRole('button', { name: 'Correct attendance' })).toBeNull();
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});
