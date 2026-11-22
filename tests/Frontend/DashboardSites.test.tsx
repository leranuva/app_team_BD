// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Dashboard from '@/Pages/Dashboard';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/Components/Attendance/ClockButton', () => ({ default: () => null }));

const base = { timeZone: 'America/New_York', sites: [], shifts: [], lists: [], clock: { open: null, shifts: [] }, photoScope: null };

afterEach(cleanup);

it('lists a supervisor sites with active scope and photo counts', () => {
    render(<Dashboard {...base} canOpenAdmin mySites={[{ id: 1, name: 'Brooklyn Heights', active_scopes_count: 2, active_photos_count: 5, read_only: false }]} />);
    expect(screen.getByRole('heading', { name: 'Mis obras' })).toBeTruthy();
    expect(screen.getByText('Brooklyn Heights')).toBeTruthy();
    expect(screen.getByText('2 alcances activos · 5 fotos activas')).toBeTruthy();
});

it('lists a worker sites as read only', () => {
    render(<Dashboard {...base} canOpenAdmin={false} mySites={[{ id: 2, name: 'Queens', active_scopes_count: null, active_photos_count: null, read_only: true }]} />);
    expect(screen.getByText('Queens')).toBeTruthy();
    expect(screen.getByText('Solo lectura')).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Obras' })).toBeNull();
});
