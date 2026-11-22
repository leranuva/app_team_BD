// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import HoursApproval from '@/Pages/Supervisor/HoursApproval';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null }));

afterEach(cleanup);

describe('hours approval', () => {
    it('stays read only while supervisor approval is not stored', () => {
        render(<HoursApproval timeZone="America/New_York" week_start="2026-11-16" approval={{ available: false, note: 'La aprobación de horas se gestiona fuera del sistema en este bloque.' }} rows={[{
            id: 1, worker: 'Luis Peña', clocked_in_at: '2026-11-23T13:00:00Z', clocked_out_at: '2026-11-23T22:00:00Z', regular_minutes: 480, overtime_minutes: 60,
        }]} />);
        expect(screen.getByText('Luis Peña')).toBeTruthy();
        expect(screen.getByText(/Regulares: 480 min/)).toBeTruthy();
        expect(screen.getByText('La aprobación de horas se gestiona fuera del sistema en este bloque.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Aprobar horas' })).toBeNull();
    });
});
