// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Schedule from '@/Pages/Worker/Schedule';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <main>{children}</main> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@fullcalendar/react', () => ({
    default: function TestCalendar(props: { editable: boolean; eventClick: (info: { event: { extendedProps: { shift: unknown } } }) => void }) {
        const shift = {
            id: 1,
            title: 'Turno de Luis',
            start: '2026-11-23T13:00:00Z',
            end: '2026-11-23T21:00:00Z',
            status: 'programmed',
            role_required: 'carpintero',
            job_site: { name: 'Queens', address: '31st Ave' },
            job_scope: { name: 'Encofrado' },
            supervisor_name: 'Ana Torres',
            notes: 'Llevar andamio',
            worker: { name: 'Luis' },
            extendedProps: { can_edit: false },
        };
        return (
            <div>
                <output data-testid="editable">{String(props.editable)}</output>
                <button type="button" onClick={() => props.eventClick({ event: { extendedProps: { shift } } })}>Abrir turno</button>
            </div>
        );
    },
}));

const options = {
    can_edit: false,
    job_sites: [{ id: 3, name: 'Queens' }],
    statuses: [{ value: 'programmed', label: 'Scheduled' }],
    roles: [{ value: 'carpintero', label: 'Carpintero' }],
};

beforeEach(() => {
    window.history.replaceState(null, '', '/worker/schedule');
    Object.defineProperty(window, 'matchMedia', { configurable: true, value: vi.fn(() => ({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    })) });
});

afterEach(cleanup);

describe('worker schedule', () => {
    it('renders a read-only week and opens the shift detail', async () => {
        window.history.replaceState(null, '', '/worker/schedule?role_required=carpintero');
        render(<Schedule options={options} timeZone="America/New_York" />);
        expect(screen.getByRole('heading', { name: 'My week' })).toBeTruthy();
        expect(screen.getByTestId('editable').textContent).toBe('false');
        expect(screen.queryByRole('combobox', { name: 'Role' })).toBeNull();
        expect(new URLSearchParams(window.location.search).has('role_required')).toBe(false);
        fireEvent.click(screen.getByRole('button', { name: 'Abrir turno' }));
        expect(await screen.findByRole('dialog')).toBeTruthy();
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('Queens')).toBeTruthy();
        expect(within(dialog).getByText('Encofrado')).toBeTruthy();
        expect(within(dialog).getByText('Ana Torres')).toBeTruthy();
        expect(within(dialog).getByText('Llevar andamio')).toBeTruthy();
    });
});
