// @vitest-environment jsdom
import Index from '@/Pages/ChangeOrders/Index';
import Show from '@/Pages/ChangeOrders/Show';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { get: vi.fn(), reload: vi.fn() },
}));
vi.mock('axios', () => ({ default: { post: vi.fn(), isAxiosError: () => false } }));

const order = {
    id: 4,
    code: 'CO-1-2026-0001',
    title: 'Muro',
    description: 'Extra',
    status: 'under_review',
    amount_dollars: 10,
    time_impact_days: 2,
    submitted_at: '2026-11-21T10:00:00-05:00',
    reviewed_at: '2026-11-21T11:00:00-05:00',
    approved_at: null,
    rejected_at: null,
    rejection_reason: null,
    cancelled_reason: null,
    requested_by: { id: 1, name: 'Ana' },
    reviewed_by: { id: 2, name: 'Luis' },
    approved_by: null,
    attachments: [{ original_name: 'orden.pdf', url: 'https://files.test/orden.pdf' }],
    can_submit: false,
    can_review: false,
    can_approve: true,
    can_reject: true,
    can_cancel: false,
};

afterEach(() => cleanup());

describe('change orders', () => {
    it('shows the create link only when the role can create', () => {
        const { rerender } = render(<Index orders={[{ ...order, status: 'draft', submitted_at: null }]} filters={{}} canCreate createUrl="/admin/change-orders/create" />);
        expect(screen.getByRole('link', { name: 'Nuevo' }).getAttribute('href')).toBe('/admin/change-orders/create');
        expect(screen.getAllByText('Borrador').length).toBeGreaterThan(0);

        rerender(<Index orders={[]} filters={{ status: 'approved' }} canCreate={false} createUrl="/admin/change-orders/create" />);
        expect(screen.queryByRole('link', { name: 'Nuevo' })).toBeNull();
        expect(screen.getByText('No hay órdenes con ese filtro.')).toBeTruthy();

        rerender(<Index orders={[]} filters={{ job_site_id: '4', status: 'approved' }} canCreate={false} createUrl="/admin/change-orders/create" />);
        expect((screen.getByLabelText('Filtrar por obra') as HTMLInputElement).value).toBe('4');
    });

    it('shows actions from the flags and requires a rejection reason', () => {
        render(<Show order={order} />);
        expect(screen.getByText('En revisión')).toBeTruthy();
        expect(screen.queryByText(/Fuera de presupuesto/)).toBeNull();
        expect(screen.getByRole('link', { name: 'orden.pdf' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Enviar' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Aprobar' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Rechazar' }));
        const confirm = screen.getByRole('button', { name: 'Confirmar' }) as HTMLButtonElement;
        expect(confirm.disabled).toBe(true);
        fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'Fuera de presupuesto' } });
        expect(confirm.disabled).toBe(false);
        fireEvent.click(confirm);
        expect(axios.post).toHaveBeenCalledWith('/api/change-orders/4/reject', { reason: 'Fuera de presupuesto' }, expect.any(Object));
        cleanup();
        render(<Show order={{ ...order, status: 'rejected', rejected_at: '2026-11-21T12:00:00-05:00', rejection_reason: 'Fuera de presupuesto', can_approve: false, can_reject: false }} />);
        expect(screen.getByText(/Fuera de presupuesto/)).toBeTruthy();
    });
});
