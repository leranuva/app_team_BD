// @vitest-environment jsdom
import Dashboard from '@/Pages/Dashboard';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/Components/Attendance/ClockButton', () => ({ default: () => <p>Time clock</p> }));

const base = {
    timeZone: 'America/New_York',
    sites: [{ id: 1, name: 'Brooklyn', address: 'Atlantic' }],
    shifts: [],
    lists: [],
    clock: { open: null, shifts: [] },
    photoScope: null,
    mySites: [],
};

describe('dashboard by role', () => {
    it('tells a worker when there is no shift today', () => {
        render(<Dashboard {...base} canOpenAdmin={false} profile="worker" />);
        expect(screen.getByRole('heading', { name: 'Today’s shift' })).toBeTruthy();
        expect(screen.getByText('No shift')).toBeTruthy();
        expect(screen.getByText('No open time entry')).toBeTruthy();
    });

    it('gives a supervisor shortcuts', () => {
        render(<Dashboard {...base} canOpenAdmin profile="supervisor" />);
        expect(screen.getByRole('link', { name: 'New list' }).getAttribute('href')).toBe('/material-lists');
        expect(screen.getByRole('navigation', { name: 'Supervisor shortcuts' })).toBeTruthy();
    });

    it('summarizes the operation for an administrator', () => {
        render(<Dashboard {...base} canOpenAdmin profile="admin" clock={{ open: { geofence_flagged: true }, shifts: [] }} />);
        const summary = screen.getByRole('region', { name: 'Operations overview' });
        expect(summary.textContent).toContain('Active job sites1');
        expect(summary.textContent).toContain('Time entry needs review');
    });
});
