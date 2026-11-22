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
        render(<AppLayout><p>Start</p></AppLayout>);
        expect(screen.getAllByRole('link', { name: 'My shifts' }).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: 'Time clock' }).length).toBeGreaterThan(0);
        expect(screen.queryByRole('link', { name: 'Change Orders' })).toBeNull();
        expect(screen.getByText('Ana')).toBeTruthy();
        expect(screen.getAllByText('Carpintero').length).toBeGreaterThan(0);
    });

    it('shows supervisor modules and persists the active site', () => {
        (globalThis as { __role?: string }).__role = 'Supervisor';
        render(<AppLayout><p>Start</p></AppLayout>);
        expect(screen.getAllByRole('link', { name: 'Materials' }).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: 'Photos' }).length).toBeGreaterThan(0);
        fireEvent.change(screen.getByLabelText('Active job site'), { target: { value: '4' } });
        expect(readActiveSite()).toBe('4');
        expect(visit).toHaveBeenCalledWith(
            expect.stringContaining('job_site_id=4'),
            expect.objectContaining({ preserveState: false }),
        );
        expect(screen.getAllByRole('link', { name: 'Materials' })[0].getAttribute('href')).toBe('/material-lists?job_site_id=4');
        expect(screen.getAllByRole('link', { name: 'Shifts' })[0].getAttribute('href')).toBe('/shifts/calendar?job_site_id=4');
    });

    it('shows the catalog and administration for an administrator', () => {
        (globalThis as { __role?: string }).__role = 'Administrador';
        render(<AppLayout><p>Start</p></AppLayout>);
        expect(screen.getAllByRole('link', { name: 'Material catalog' })[0].getAttribute('href')).toBe('/admin/materials');
        expect(screen.getAllByRole('link', { name: 'Administration' })[0].getAttribute('href')).toBe('/admin');
        expect(screen.getAllByRole('link', { name: 'Dashboard' })[0].getAttribute('aria-current')).toBe('page');
    });
});
