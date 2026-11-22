// @vitest-environment jsdom
import Dashboard from '@/Pages/Dashboard';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/Components/Attendance/ClockButton', () => ({ default: () => <p>Fichaje</p> }));

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
        expect(screen.getByRole('heading', { name: 'Turno de hoy' })).toBeTruthy();
        expect(screen.getByText('sin turno')).toBeTruthy();
        expect(screen.getByText('Sin fichaje abierto')).toBeTruthy();
    });

    it('gives a supervisor shortcuts', () => {
        render(<Dashboard {...base} canOpenAdmin profile="supervisor" />);
        expect(screen.getByRole('link', { name: 'Nueva lista' }).getAttribute('href')).toBe('/material-lists');
        expect(screen.getByRole('navigation', { name: 'Accesos del supervisor' })).toBeTruthy();
    });

    it('summarizes the operation for an administrator', () => {
        render(<Dashboard {...base} canOpenAdmin profile="admin" clock={{ open: { geofence_flagged: true }, shifts: [] }} />);
        expect(screen.getByText('Obras activas: 1')).toBeTruthy();
        expect(screen.getByText('Alerta de geofence')).toBeTruthy();
    });
});
