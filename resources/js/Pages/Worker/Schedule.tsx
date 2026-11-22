import Calendar from '@/Pages/Shifts/Calendar';

type Option = { value: string; label: string };
type Options = {
    job_sites: { id: number; name: string }[];
    statuses: Option[];
    roles: Option[];
    can_edit: boolean;
};

export default function Schedule({ options, timeZone }: { options: Options; timeZone: string }) {
    return <Calendar options={options} canEdit={false} timeZone={timeZone} title="Mi semana" />;
}
