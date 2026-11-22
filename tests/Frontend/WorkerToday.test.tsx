// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Today from '@/Pages/Worker/Today';
import { installMemoryStorage } from './memoryStorage';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { reload: vi.fn() } }));

const shift = {
    id: 4,
    starts_at: '2026-11-23T13:00:00Z',
    ends_at: '2026-11-23T22:00:00Z',
    role_required: 'Carpintero',
    notes: null,
    job_site: { name: 'Queens', address: '31st Ave', maps_url: 'https://www.google.com/maps/search/?api=1&query=31st%20Ave' },
    job_scope: { id: 2, name: 'Encofrado' },
    supervisor: 'Ana Torres',
};

const clock = { open: null, shifts: [{ id: 4, starts_at: shift.starts_at, job_site: { name: 'Queens' } }] };

afterEach(() => {
    cleanup();
});

describe('worker today', () => {
    it('shows the assigned shift', () => {
        installMemoryStorage();
        render(<Today timeZone="America/New_York" shift={shift} materials={[{ id: 1, material: 'Yeso', quantity: '2', unit: 'bolsa' }]} clock={clock} />);
        expect(screen.getByRole('region', { name: 'Turno de hoy' })).toBeTruthy();
        expect(screen.getByText('Queens')).toBeTruthy();
        expect(screen.getByRole('link', { name: '31st Ave' }).getAttribute('href')).toContain('google.com/maps');
        expect(screen.getByText('08:00')).toBeTruthy();
        expect(screen.getByText('17:00')).toBeTruthy();
        expect(screen.getByText('Alcance: Encofrado')).toBeTruthy();
        expect(screen.getByText('Supervisor: Ana Torres')).toBeTruthy();
        expect(screen.getByText('Yeso · 2 bolsa')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Registrar entrada' }).className).toContain('min-h-16');
        expect(screen.getByRole('button', { name: 'Registrar entrada' }).className).toContain('text-lg');
        const page = document.querySelector('[data-field-page]');
        expect(page?.className).toContain('bg-slate-100');
        expect(page?.className).toContain('text-slate-950');
        expect(page?.className).toContain('text-base');
    });

    it('shows an empty state without a shift', () => {
        render(<Today timeZone="America/New_York" shift={null} materials={[]} clock={{ open: null, shifts: [] }} />);
        expect(screen.getByText('No tienes turno asignado hoy')).toBeTruthy();
        expect(screen.queryByRole('region', { name: 'Materiales del turno' })).toBeNull();
    });

    it('shows an open punch', () => {
        render(<Today timeZone="America/New_York" shift={shift} materials={[]} clock={{
            open: { shift_id: 4, clocked_in_at: '2026-11-23T13:00:00Z', job_site: { name: 'Queens' } },
            shifts: [],
        }} />);
        expect(screen.getByRole('button', { name: 'Registrar salida' })).toBeTruthy();
        expect(screen.getByText(/Entrada registrada/)).toBeTruthy();
    });

    it('offers a new entry after the punch is closed', () => {
        render(<Today timeZone="America/New_York" shift={shift} materials={[]} clock={clock} />);
        expect(screen.queryByText(/Entrada registrada/)).toBeNull();
        expect(screen.getByRole('button', { name: 'Registrar entrada' })).toBeTruthy();
    });
});
