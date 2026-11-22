// @vitest-environment jsdom
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import { calendarInitialView } from '@/Pages/Shifts/Calendar';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

describe('shared states', () => {
    it('renders the loading and empty states', () => {
        render(<><LoadingState label="Loading shifts…" /><EmptyState title="No material lists yet. Create the first one." action={{ href: '/material-lists', label: 'New' }} /></>);
        expect(screen.getByRole('status').textContent).toContain('Loading shifts…');
        expect(screen.getByText('No material lists yet. Create the first one.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'New' }).getAttribute('href')).toBe('/material-lists');
    });

    it('uses the list week on a narrow viewport', () => {
        expect(calendarInitialView(false)).toBe('listWeek');
        expect(calendarInitialView(true)).toBe('timeGridWeek');
    });
});
