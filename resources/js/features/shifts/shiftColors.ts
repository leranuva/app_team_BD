type ShiftStatus = 'programmed' | 'confirmed' | 'completed' | 'cancelled';
type ShiftColor = { bg: string; border: string; text: string };

export const shiftColors: Record<ShiftStatus, ShiftColor> = {
    programmed: { bg: '#E0E7FF', border: '#6366F1', text: '#1E1B4B' },
    confirmed: { bg: '#DCFCE7', border: '#22C55E', text: '#14532D' },
    completed: { bg: '#DBEAFE', border: '#3B82F6', text: '#1E3A8A' },
    cancelled: { bg: '#F3F4F6', border: '#9CA3AF', text: '#1F2937' },
};

export function shiftAppearance(status: string) {
    const color = Object.hasOwn(shiftColors, status) ? shiftColors[status as ShiftStatus] : shiftColors.programmed;

    return {
        backgroundColor: color.bg,
        borderColor: color.border,
        textColor: color.text,
        classNames: status === 'cancelled' ? ['shift-cancelled'] : [],
    };
}
