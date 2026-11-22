// @vitest-environment jsdom
import AppLayout from '@/Layouts/AppLayout';
import { readActiveSite } from '@/lib/activeSite';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: {
                user: { id: 1, name: 'Ana', email: 'ana@example.test' },
                role: (globalThis as { __role?: string }).__role ?? 'Carpintero',
                sites: [{ id: 3, name: 'Brooklyn' }, { id: 4, name: 'Queens' }],
            },
        },
        url: '/dashboard',
    }),
    router: { visit, post: vi.fn() },
}));

afterEach(() => {
    cleanup();
    window.localStorage?.clear?.();
    visit.mockReset();
});

describe('app layout', () => {
    it('shows the worker navigation', () => {
        (globalThis as { __role?: string }).__role = 'Carpintero';
        render(<AppLayout><p>Inicio</p></AppLayout>);
        expect(screen.getAllByRole('link', { name: 'Mis turnos' }).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: 'Fichaje' }).length).toBeGreaterThan(0);
        expect(screen.queryByRole('link', { name: 'Change Orders' })).toBeNull();
        expect(screen.getByText('Ana')).toBeTruthy();
        expect(screen.getAllByText('Carpintero').length).toBeGreaterThan(0);
    });

    it('shows supervisor modules and persists the active site', () => {
        (globalThis as { __role?: string }).__role = 'Supervisor';
        render(<AppLayout><p>Inicio</p></AppLayout>);
        expect(screen.getAllByRole('link', { name: 'Materiales' }).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: 'Fotos' }).length).toBeGreaterThan(0);
        fireEvent.change(screen.getByLabelText('Obra activa'), { target: { value: '4' } });
        expect(readActiveSite()).toBe('4');
        expect(visit).toHaveBeenCalledWith(
            expect.stringContaining('job_site_id=4'),
            expect.objectContaining({ preserveState: false }),
        );
        expect(screen.getAllByRole('link', { name: 'Materiales' })[0].getAttribute('href')).toBe('/material-lists?job_site_id=4');
        expect(screen.getAllByRole('link', { name: 'Turnos' })[0].getAttribute('href')).toBe('/shifts/calendar?job_site_id=4');
    });

    it('shows the catalog and Filament for an administrator', () => {
        (globalThis as { __role?: string }).__role = 'Administrador';
        render(<AppLayout><p>Inicio</p></AppLayout>);
        expect(screen.getAllByRole('link', { name: 'Catálogo de materiales' })[0].getAttribute('href')).toBe('/admin/materials');
        expect(screen.getAllByRole('link', { name: 'Filament' })[0].getAttribute('href')).toBe('/admin');
    });
});
