// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ScopeProgress from '@/Pages/Supervisor/ScopeProgress';
import { installMemoryStorage } from './memoryStorage';

const reload = vi.hoisted(() => vi.fn());
vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { reload, get: vi.fn() } }));

const scope = {
    id: 7,
    name: 'Encofrado',
    status: 'active',
    progress_percent: 40,
    job_site: { id: 3, name: 'Queens' },
    responsible: 'Ana Torres',
    can_edit: true,
    pending_change_orders: 1,
    pending_material_lists: 2,
    today_shifts: [],
    photos: [],
};

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('scope progress', () => {
    it('renders the scope summary', () => {
        installMemoryStorage();
        render(<ScopeProgress timeZone="America/New_York" sites={[{ id: 3, name: 'Queens' }]} jobSiteId={3} scopes={[scope]} />);
        expect(screen.getByRole('heading', { name: 'Encofrado' })).toBeTruthy();
        expect(screen.getByText(/Ana Torres/)).toBeTruthy();
        expect(screen.getByText('Órdenes pendientes: 1 · Listas pendientes: 2')).toBeTruthy();
        expect(screen.getByRole('progressbar', { name: 'Avance de Encofrado' }).getAttribute('aria-valuenow')).toBe('40');
    });

    it('saves a partial update', async () => {
        const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: {} });
        render(<ScopeProgress timeZone="America/New_York" sites={[{ id: 3, name: 'Queens' }]} jobSiteId={3} scopes={[scope]} />);
        fireEvent.change(screen.getByLabelText('Actualizar avance de Encofrado'), { target: { value: '55' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));
        await screen.findByText('Avance actualizado.');
        expect(patch).toHaveBeenCalledWith('/api/job-scopes/7', { progress: 55 }, expect.anything());
    });

    it('rejects 100 percent unless the scope is completed', () => {
        const patch = vi.spyOn(axios, 'patch');
        render(<ScopeProgress timeZone="America/New_York" sites={[{ id: 3, name: 'Queens' }]} jobSiteId={3} scopes={[scope]} />);
        fireEvent.change(screen.getByLabelText('Actualizar avance de Encofrado'), { target: { value: '100' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));
        expect(screen.getByRole('alert').textContent).toContain('100 %');
        expect(patch).not.toHaveBeenCalled();
    });
});
