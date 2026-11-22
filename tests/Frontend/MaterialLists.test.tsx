// @vitest-environment jsdom
import Index from '@/Pages/MaterialLists/Index';
import Show from '@/Pages/MaterialLists/Show';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { get: vi.fn(), reload: vi.fn() },
}));
vi.mock('axios', () => ({ default: { post: vi.fn(), isAxiosError: () => false } }));

const item = {
    id: 8,
    budgeted_quantity: '1.000',
    requested_quantity: '2.000',
    approved_quantity: '2.000',
    delivered_quantity: '2.000',
    consumed_quantity: null,
    unit_cost_cents: 1500,
    material: { id: 3, name: 'Cable', unit: 'm' },
};

const list = {
    id: 4,
    code: 'ML-1-2026-0001',
    title: 'Cable de losa',
    status: 'delivered',
    job_scope: { id: 2, name: 'Losa' },
    ordered_at: '2026-11-21T10:00:00-05:00',
    delivered_at: '2026-11-21T12:00:00-05:00',
    consumed_at: null,
    cancelled_reason: null,
    items: [item],
    can_submit: false,
    can_approve: false,
    can_mark_ordered: false,
    can_register_delivery: false,
    can_register_consumption: true,
    can_cancel: false,
};

afterEach(() => cleanup());

describe('material lists', () => {
    it('shows the create link only when the role can create', () => {
        const { rerender } = render(<Index lists={[{ id: 4, code: 'ML-1', title: 'Cable', status: 'draft', total_requested_value_cents: null, ordered_at: null, delivered_at: null, consumed_at: null, job_site: null, job_scope: null, requested_by: null }]} filters={{}} canCreate createUrl="/admin/material-lists/create" />);
        expect(screen.getByRole('link', { name: 'New' }).getAttribute('href')).toBe('/admin/material-lists/create');
        expect(screen.getAllByText('Draft').length).toBeGreaterThan(0);

        rerender(<Index lists={[]} filters={{ status: 'consumed' }} canCreate={false} createUrl="/admin/material-lists/create" />);
        expect(screen.queryByRole('link', { name: 'New' })).toBeNull();
        expect(screen.getByText('No lists match these filters.')).toBeTruthy();

        rerender(<Index lists={[]} filters={{ job_site_id: '9' }} canCreate={false} createUrl="/admin/material-lists/create" />);
        expect((screen.getByLabelText('Filter by job site') as HTMLInputElement).value).toBe('9');
    });

    it('shows consumption only when allowed and requires a quantity', () => {
        render(<Show list={list} />);
        expect(screen.getAllByText('Delivered').length).toBeGreaterThan(0);
        expect(screen.queryByRole('button', { name: 'Submit' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Record usage' }));
        const confirm = screen.getByRole('button', { name: 'Confirm' }) as HTMLButtonElement;
        const input = screen.getByLabelText('Quantity for Cable') as HTMLInputElement;
        fireEvent.change(input, { target: { value: '' } });
        expect(confirm.disabled).toBe(true);
        fireEvent.change(input, { target: { value: '1' } });
        expect(confirm.disabled).toBe(false);
    });
});
