// @vitest-environment jsdom
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import { calendarInitialView } from '@/Pages/Shifts/Calendar';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

describe('shared states', () => {
    it('renders the loading and empty states', () => {
        render(<><LoadingState label="Cargando turnos…" /><EmptyState title="No hay listas todavía. Crea la primera." action={{ href: '/material-lists', label: 'Nueva' }} /></>);
        expect(screen.getByRole('status').textContent).toContain('Cargando turnos…');
        expect(screen.getByText('No hay listas todavía. Crea la primera.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Nueva' }).getAttribute('href')).toBe('/material-lists');
    });

    it('uses the list week on a narrow viewport', () => {
        expect(calendarInitialView(false)).toBe('listWeek');
        expect(calendarInitialView(true)).toBe('timeGridWeek');
    });
});
