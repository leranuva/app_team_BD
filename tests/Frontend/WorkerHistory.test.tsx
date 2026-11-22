// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import History from '@/Pages/Worker/History';

const get = vi.hoisted(() => vi.fn());
vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { get } }));

const row = {
    id: 8,
    job_site: 'Queens',
    clocked_in_at: '2026-11-23T13:00:00Z',
    clocked_out_at: '2026-11-23T22:00:00Z',
    duration_minutes: 540,
    overtime_minutes: 60,
};

afterEach(() => {
    cleanup();
    get.mockReset();
});

describe('worker history', () => {
    it('renders attendance rows without images', () => {
        const { container } = render(<History timeZone="America/New_York" attendances={{ data: [row], current_page: 1, last_page: 2 }} />);
        expect(screen.getByText('Queens')).toBeTruthy();
        expect(screen.getByText('Duración: 540 min')).toBeTruthy();
        expect(screen.getByText('Horas extra: 60 min')).toBeTruthy();
        expect(container.querySelector('img')).toBeNull();
    });

    it('asks for the next page', () => {
        render(<History timeZone="America/New_York" attendances={{ data: [row], current_page: 1, last_page: 2 }} />);
        expect(screen.getByRole('button', { name: 'Anterior' })).toHaveProperty('disabled', true);
        fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }));
        expect(get).toHaveBeenCalledWith('/worker/history', { page: 2 }, expect.objectContaining({ preserveState: true }));
    });
});
