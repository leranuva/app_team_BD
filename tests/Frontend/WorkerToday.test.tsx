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
    clocked_in_at: null,
    clocked_out_at: null,
    materials: [],
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
        render(<Today timeZone="America/New_York" shifts={[{ ...shift, materials: [{ id: 1, material: 'Yeso', quantity: '2', unit: 'bolsa' }] }]} clock={clock} />);
        expect(screen.getByRole('region', { name: 'Queens' })).toBeTruthy();
        expect(screen.getByText('Queens')).toBeTruthy();
        expect(screen.getByRole('link', { name: '31st Ave' }).getAttribute('href')).toContain('google.com/maps');
        expect(screen.getByText('08:00')).toBeTruthy();
        expect(screen.getByText('17:00')).toBeTruthy();
        expect(screen.getByText('Scope: Encofrado')).toBeTruthy();
        expect(screen.getByText('Supervisor: Ana Torres')).toBeTruthy();
        expect(screen.getByText('Yeso · 2 bolsa')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Clock in' }).className).toContain('min-h-16');
        expect(screen.getByRole('button', { name: 'Clock in' }).className).toContain('text-lg');
        const page = document.querySelector('[data-field-page]');
        expect(page?.className).toContain('bg-slate-100');
        expect(page?.className).toContain('text-slate-950');
        expect(page?.className).toContain('text-base');
    });

    it('shows an empty state without a shift', () => {
        render(<Today timeZone="America/New_York" shifts={[]} clock={{ open: null, shifts: [] }} />);
        expect(screen.getByText('You have no shift assigned today')).toBeTruthy();
        expect(screen.queryByRole('region', { name: 'Shift materials' })).toBeNull();
    });

    it('shows an open punch', () => {
        render(<Today timeZone="America/New_York" shifts={[shift]} clock={{
            open: { shift_id: 4, clocked_in_at: '2026-11-23T13:00:00Z', job_site: { name: 'Queens' } },
            shifts: [],
        }} />);
        expect(screen.getByRole('button', { name: 'Clock out' })).toBeTruthy();
        expect(screen.getByText(/Clocked in at/)).toBeTruthy();
    });

    it('offers a new entry after the punch is closed', () => {
        render(<Today timeZone="America/New_York" shifts={[shift]} clock={clock} />);
        expect(screen.queryByText(/Clocked in at/)).toBeNull();
        expect(screen.getByRole('button', { name: 'Clock in' })).toBeTruthy();
    });
});
