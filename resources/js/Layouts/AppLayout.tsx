import { router, usePage } from '@inertiajs/react';
import { ReactNode, useState } from 'react';
import { hrefWithActiveSite, readActiveSite, writeActiveSite } from '@/lib/activeSite';

type Site = { id: number; name: string };
type Auth = { user: { id: number; name: string; email: string } | null; role?: string | null; sites?: Site[] };

const workerLinks = [
    { href: '/dashboard', label: 'Dashboard' },
    { href: '/shifts/calendar', label: 'Mis turnos' },
    { href: '/dashboard#fichaje', label: 'Fichaje' },
];

const supervisorLinks = [
    { href: '/dashboard', label: 'Dashboard' },
    { href: '/dashboard#obras', label: 'Mis obras' },
    { href: '/shifts/calendar', label: 'Turnos' },
    { href: '/change-orders', label: 'Change Orders' },
    { href: '/material-lists', label: 'Materiales' },
    { href: '/dashboard#fotos', label: 'Fotos' },
];

function linksFor(role: string | null | undefined) {
    if (role === 'Super Admin' || role === 'Administrador') {
        return [
            ...supervisorLinks,
            { href: '/admin/materials', label: 'Catálogo de materiales' },
            { href: '/admin', label: 'Filament', external: true },
        ];
    }
    if (role === 'Supervisor') {
        return supervisorLinks;
    }

    return workerLinks;
}

export default function AppLayout({ header, children }: { header?: ReactNode; children: ReactNode }) {
    const page = usePage<{ auth: Auth }>();
    const auth = page.props.auth ?? { user: null, sites: [] };
    const role = auth.role ?? null;
    const items = linksFor(role);
    const sites = auth.sites ?? [];
    const [collapsed, setCollapsed] = useState(false);
    const [open, setOpen] = useState(false);
    const [siteId, setSiteId] = useState(readActiveSite);

    function chooseSite(id: string) {
        setSiteId(id);
        writeActiveSite(id);
        const url = new URL(window.location.href);
        if (id) {
            url.searchParams.set('job_site_id', id);
        } else {
            url.searchParams.delete('job_site_id');
        }
        router.visit(url.pathname + url.search + url.hash, { preserveState: false, preserveScroll: true });
    }

    return (
        <div className="min-h-screen bg-slate-100 text-slate-950">
            <aside className={`fixed inset-y-0 left-0 z-30 hidden border-r border-slate-200 bg-white md:flex md:flex-col ${collapsed ? 'md:w-20' : 'md:w-64'}`}>
                <div className="flex items-center justify-between px-4 py-4">
                    {!collapsed && <span className="text-sm font-semibold">Logística</span>}
                    <button type="button" className="min-h-12 min-w-12 rounded-lg text-sm font-semibold" aria-expanded={!collapsed} aria-label={collapsed ? 'Expandir menú' : 'Contraer menú'} onClick={() => setCollapsed((value) => !value)}>
                        {collapsed ? '»' : '«'}
                    </button>
                </div>
                <nav aria-label="Principal" className="flex flex-1 flex-col gap-1 px-2">
                    {items.map((item) => (
                        <a key={item.label} href={'external' in item ? item.href : hrefWithActiveSite(item.href, siteId)} {...('external' in item ? { target: '_blank', rel: 'noreferrer' } : {})} className="flex min-h-12 items-center rounded-lg px-3 text-sm font-semibold hover:bg-slate-100">
                            {collapsed ? item.label.slice(0, 1) : item.label}
                        </a>
                    ))}
                </nav>
            </aside>
            <div className={collapsed ? 'md:pl-20' : 'md:pl-64'}>
                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                    <div>
                        <p className="font-semibold">{auth.user?.name ?? 'Invitado'}</p>
                        {role && <span className="mt-1 inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-950">{role}</span>}
                    </div>
                    {sites.length > 1 && (
                        <label className="text-sm font-semibold">Obra activa
                            <select aria-label="Obra activa" className="ml-2 min-h-12 rounded-lg border-slate-300" value={siteId} onChange={(event) => chooseSite(event.target.value)}>
                                <option value="">Todas</option>
                                {sites.map((site) => <option key={site.id} value={String(site.id)}>{site.name}</option>)}
                            </select>
                        </label>
                    )}
                    <button type="button" className="min-h-12 rounded-lg border px-3 text-sm font-semibold md:hidden" aria-expanded={open} aria-label="Abrir menú de perfil" onClick={() => setOpen((value) => !value)}>Perfil</button>
                    <div className={`${open ? 'flex' : 'hidden'} w-full flex-col gap-2 md:flex md:w-auto md:flex-row`}>
                        <a href="/profile" className="inline-flex min-h-12 items-center text-sm font-semibold">Perfil</a>
                        <button type="button" className="min-h-12 text-left text-sm font-semibold" onClick={() => router.post('/logout')}>Cerrar sesión</button>
                    </div>
                </header>
                {header && <div className="border-b border-slate-200 bg-white px-4 py-4">{header}</div>}
                <div className="pb-24 md:pb-8">{children}</div>
            </div>
            <nav aria-label="Móvil" className="fixed inset-x-0 bottom-0 z-30 flex gap-1 overflow-x-auto border-t border-slate-200 bg-white p-2 md:hidden">
                {items.filter((item) => !('external' in item)).map((item) => (
                    <a key={item.label} href={hrefWithActiveSite(item.href, siteId)} className="inline-flex min-h-12 shrink-0 items-center rounded-lg px-3 text-sm font-semibold">
                        {item.label}
                    </a>
                ))}
            </nav>
        </div>
    );
}
