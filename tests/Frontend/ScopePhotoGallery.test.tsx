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
        shifts={[{ id: 1, worker: 'Luis Peña', starts_at: '2026-11-21T14:30:00-05:00', status: 'Scheduled' }]}
    />);
    expect(screen.getByRole('progressbar', { name: 'Scope progress' }).getAttribute('aria-valuenow')).toBe('40');
    expect(screen.getByRole('tab', { name: 'Active' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Brooklyn Heights' }).getAttribute('href')).toBe('/admin/job-sites/3');
    expect(screen.getByRole('link', { name: 'Edit' }).getAttribute('href')).toBe('/admin/job-scopes/7/edit');
    fireEvent.click(screen.getByRole('tab', { name: 'Shifts' }));
    expect(await screen.findByText('Luis Peña')).toBeTruthy();
    fireEvent.click(screen.getByRole('tab', { name: 'Details' }));
    expect(screen.getByText(/Muro de contención/)).toBeTruthy();
});

it('renders active and archived tabs from the API', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) => {
        const status = (config as { params?: { status?: string } }).params?.status;
        return page(status === 'archived' ? [archivedPhoto] : [activePhoto]);
    });
    render(<Show scope={scope} canCreate canViewAll={false} timeZone="America/New_York" />);
    expect(await screen.findByRole('button', { name: 'View Muro norte' })).toBeTruthy();
    expect(screen.queryByRole('tab', { name: 'All' })).toBeNull();
    fireEvent.click(screen.getByRole('tab', { name: 'Archived' }));
    expect(await screen.findByRole('button', { name: 'View Losa' })).toBeTruthy();
});

it('opens the detail modal with the photo metadata', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue(page([activePhoto]));
    render(<Show scope={scope} canCreate canViewAll timeZone="America/New_York" />);
    fireEvent.click(await screen.findByRole('button', { name: 'View Muro norte' }));
    expect(await screen.findByRole('dialog')).toBeTruthy();
    expect(screen.getByText('Ana Torres')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'View location in Google Maps' }).getAttribute('href')).toContain('40.7128');
});

it('adds a successful upload to the grid and toasts a rejected file', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue(page([]));
    const post = vi.spyOn(axios, 'post')
        .mockResolvedValueOnce({ data: { data: activePhoto } })
        .mockRejectedValueOnce({ isAxiosError: true, response: { status: 422, data: { message: 'Datos inválidos', errors: { file: ['The photo must be 10 MB or smaller.'] } } } });
    render(<Show scope={scope} canCreate canViewAll={false} timeZone="America/New_York" />);
    await screen.findByText('There are no photos in this tab.');
    fireEvent.click(screen.getByRole('button', { name: 'Add photo' }));
    const input = screen.getByLabelText('Image') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['jpeg'], 'muro.jpg', { type: 'image/jpeg' })] } });
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }));
    expect(await screen.findByRole('button', { name: 'View Muro norte' })).toBeTruthy();
    expect(await screen.findByText('Photo uploaded')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Add photo' }));
    fireEvent.change(screen.getByLabelText('Image'), { target: { files: [new File(['gif'], 'mala.gif', { type: 'image/gif' })] } });
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }));
    expect(await screen.findByText('The photo must be 10 MB or smaller.')).toBeTruthy();
    expect(post).toHaveBeenCalledTimes(2);
});

it('moves a photo between the active and archived tabs', async () => {
    const archived = { ...activePhoto, archived_at: '2026-11-21T18:00:00-05:00', url: null, thumbnail_url: null, can_archive: false, can_restore: true };
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) => page((config as { params?: { status?: string } }).params?.status === 'archived' ? [archived] : [activePhoto]));
    vi.spyOn(axios, 'patch')
        .mockResolvedValueOnce({ data: { data: archived } })
        .mockResolvedValueOnce({ data: { data: activePhoto } });
    render(<Show scope={scope} canCreate={false} canViewAll={false} timeZone="America/New_York" />);
    fireEvent.click(await screen.findByRole('button', { name: 'View Muro norte' }));
    fireEvent.click(screen.getByRole('button', { name: 'Archive' }));
    await screen.findByText('Photo archived');
    expect(screen.queryByRole('button', { name: 'View Muro norte' })).toBeNull();

    fireEvent.click(screen.getByRole('tab', { name: 'Archived' }));
    fireEvent.click(await screen.findByRole('button', { name: 'View Muro norte' }));
    expect(screen.getByText('This photo is archived. Restore it to generate a signed link.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Restore' }));
    await screen.findByText('Photo restored');
    expect(screen.queryByRole('button', { name: 'View Muro norte' })).toBeNull();
});

it('toasts when restoring a photo whose original file is gone', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) => page((config as { params?: { status?: string } }).params?.status === 'archived' ? [archivedPhoto] : []));
    vi.spyOn(axios, 'patch').mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { message: 'The original file is no longer available' } } });
    render(<Show scope={scope} canCreate={false} canViewAll={false} timeZone="America/New_York" />);
    fireEvent.click(screen.getByRole('tab', { name: 'Archived' }));
    fireEvent.click(await screen.findByRole('button', { name: 'View Losa' }));
    fireEvent.click(screen.getByRole('button', { name: 'Restore' }));
    expect(await screen.findByText('The original file is no longer available')).toBeTruthy();
});
