// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import axios from 'axios';
import { toast } from 'sonner';
import { afterEach, expect, it, vi } from 'vitest';
import Show from '@/Pages/JobScopes/Show';
import type { ScopePhotoItem } from '@/Pages/JobScopes/photoApi';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null }));

const scope = { id: 7, name: 'Encofrado', status: 'active', job_site: { id: 3, name: 'Brooklyn Heights' } };

const activePhoto: ScopePhotoItem = {
    id: 9, job_scope_id: 7, caption: 'Muro norte', url: 'https://example.invalid/full', thumbnail_url: 'https://example.invalid/thumb',
    taken_at: '2026-11-21T14:30:00-05:00', uploaded_by: { id: 4, name: 'Ana Torres' }, lat: 40.7128, lng: -74.006,
    archived_at: null, can_archive: true, can_restore: false,
};

const archivedPhoto: ScopePhotoItem = {
    ...activePhoto, id: 10, caption: 'Losa', url: null, thumbnail_url: null, archived_at: '2026-11-21T18:00:00-05:00', can_archive: false, can_restore: true,
};

function page(items: ScopePhotoItem[]) {
    return { data: { data: items, meta: { current_page: 1, last_page: 1, total: items.length } } };
}

afterEach(() => {
    toast.getToasts().forEach(({ id }) => toast.dismiss(id));
    cleanup();
    vi.restoreAllMocks();
});

it('shows the scope header, the section tabs and the site link', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue(page([]));
    render(<Show
        scope={{ ...scope, progress_percent: 40, description: 'Muro de contención', responsible: { id: 3, name: 'Ana Torres' }, site_url: '/admin/job-sites/3', can_edit: true, edit_url: '/admin/job-scopes/7/edit' }}
        canCreate
        canViewAll={false}
        timeZone="America/New_York"
        shifts={[{ id: 1, worker: 'Luis Peña', starts_at: '2026-11-21T14:30:00-05:00', status: 'Programado' }]}
    />);
    expect(screen.getByRole('progressbar', { name: 'Avance del alcance' }).getAttribute('aria-valuenow')).toBe('40');
    expect(screen.getByText('Activo')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Brooklyn Heights' }).getAttribute('href')).toBe('/admin/job-sites/3');
    expect(screen.getByRole('link', { name: 'Editar' }).getAttribute('href')).toBe('/admin/job-scopes/7/edit');
    fireEvent.click(screen.getByRole('tab', { name: 'Turnos' }));
    expect(await screen.findByText('Luis Peña')).toBeTruthy();
    fireEvent.click(screen.getByRole('tab', { name: 'Detalle' }));
    expect(screen.getByText(/Muro de contención/)).toBeTruthy();
});

it('renders active and archived tabs from the API', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) => {
        const status = (config as { params?: { status?: string } }).params?.status;
        return page(status === 'archived' ? [archivedPhoto] : [activePhoto]);
    });
    render(<Show scope={scope} canCreate canViewAll={false} timeZone="America/New_York" />);
    expect(await screen.findByRole('button', { name: 'Ver Muro norte' })).toBeTruthy();
    expect(screen.queryByRole('tab', { name: 'Todas' })).toBeNull();
    fireEvent.click(screen.getByRole('tab', { name: 'Archivadas' }));
    expect(await screen.findByText('Archivada')).toBeTruthy();
});

it('opens the detail modal with the photo metadata', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue(page([activePhoto]));
    render(<Show scope={scope} canCreate canViewAll timeZone="America/New_York" />);
    fireEvent.click(await screen.findByRole('button', { name: 'Ver Muro norte' }));
    expect(await screen.findByRole('dialog')).toBeTruthy();
    expect(screen.getByText('Ana Torres')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Ver ubicación en Google Maps' }).getAttribute('href')).toContain('40.7128');
});

it('adds a successful upload to the grid and toasts a rejected file', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue(page([]));
    const post = vi.spyOn(axios, 'post')
        .mockResolvedValueOnce({ data: { data: activePhoto } })
        .mockRejectedValueOnce({ isAxiosError: true, response: { status: 422, data: { message: 'Datos inválidos', errors: { file: ['La foto no puede superar 10 MB.'] } } } });
    render(<Show scope={scope} canCreate canViewAll={false} timeZone="America/New_York" />);
    await screen.findByText('No hay fotos en esta pestaña.');
    fireEvent.click(screen.getByRole('button', { name: 'Añadir foto' }));
    const input = screen.getByLabelText('Imagen') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['jpeg'], 'muro.jpg', { type: 'image/jpeg' })] } });
    fireEvent.click(screen.getByRole('button', { name: 'Subir' }));
    expect(await screen.findByRole('button', { name: 'Ver Muro norte' })).toBeTruthy();
    expect(await screen.findByText('Foto subida')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Añadir foto' }));
    fireEvent.change(screen.getByLabelText('Imagen'), { target: { files: [new File(['gif'], 'mala.gif', { type: 'image/gif' })] } });
    fireEvent.click(screen.getByRole('button', { name: 'Subir' }));
    expect(await screen.findByText('La foto no puede superar 10 MB.')).toBeTruthy();
    expect(post).toHaveBeenCalledTimes(2);
});

it('moves a photo between the active and archived tabs', async () => {
    const archived = { ...activePhoto, archived_at: '2026-11-21T18:00:00-05:00', url: null, thumbnail_url: null, can_archive: false, can_restore: true };
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) => page((config as { params?: { status?: string } }).params?.status === 'archived' ? [archived] : [activePhoto]));
    vi.spyOn(axios, 'patch')
        .mockResolvedValueOnce({ data: { data: archived } })
        .mockResolvedValueOnce({ data: { data: activePhoto } });
    render(<Show scope={scope} canCreate={false} canViewAll={false} timeZone="America/New_York" />);
    fireEvent.click(await screen.findByRole('button', { name: 'Ver Muro norte' }));
    fireEvent.click(screen.getByRole('button', { name: 'Archivar' }));
    await screen.findByText('Foto archivada');
    expect(screen.queryByRole('button', { name: 'Ver Muro norte' })).toBeNull();

    fireEvent.click(screen.getByRole('tab', { name: 'Archivadas' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Ver Muro norte' }));
    expect(screen.getByText('Archivada')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Restaurar' }));
    await screen.findByText('Foto restaurada');
    expect(screen.queryByRole('button', { name: 'Ver Muro norte' })).toBeNull();
});

it('toasts when restoring a photo whose original file is gone', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) => page((config as { params?: { status?: string } }).params?.status === 'archived' ? [archivedPhoto] : []));
    vi.spyOn(axios, 'patch').mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { message: 'El archivo original ya no está disponible' } } });
    render(<Show scope={scope} canCreate={false} canViewAll={false} timeZone="America/New_York" />);
    fireEvent.click(screen.getByRole('tab', { name: 'Archivadas' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Ver Losa' }));
    fireEvent.click(screen.getByRole('button', { name: 'Restaurar' }));
    expect(await screen.findByText('El archivo original ya no está disponible')).toBeTruthy();
});
