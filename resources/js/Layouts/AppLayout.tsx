import { router, usePage } from '@inertiajs/react';
import { ReactNode, useState } from 'react';
import { hrefWithActiveSite, readActiveSite, writeActiveSite } from '@/lib/activeSite';
import ApplicationLogo from '@/Components/ApplicationLogo';

type Site = { id: number; name: string };
type Auth = { user: { id: number; name: string; email: string } | null; role?: string | null; role_label?: string | null; sites?: Site[] };

const workerLinks = [
    { href: '/worker/today', label: 'Today' },
    { href: '/worker/schedule', label: 'My week' },
    { href: '/worker/history', label: 'History' },
    { href: '/dashboard', label: 'Dashboard' },
    { href: '/shifts/calendar', label: 'My shifts' },
    { href: '/dashboard#fichaje', label: 'Time clock' },
];

const supervisorLinks = [
    { href: '/dashboard', label: 'Dashboard' },
    { href: '/supervisor/team', label: 'Team' },
    { href: '/supervisor/scopes', label: 'Progress' },
    { href: '/supervisor/hours', label: 'Hours' },
    { href: '/dashboard#obras', label: 'My job sites' },
    { href: '/shifts/calendar', label: 'Shifts' },
    { href: '/change-orders', label: 'Change Orders' },
    { href: '/material-lists', label: 'Materials' },
    { href: '/dashboard#fotos', label: 'Photos' },
];

function linksFor(role: string | null | undefined) {
    if (role === 'Super Admin' || role === 'Administrador') {
        return [
            ...supervisorLinks,
            { href: '/admin/materials', label: 'Material catalog' },
            { href: '/admin', label: 'Administration', external: true },
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
    const currentPath = page.url.split(/[?#]/)[0];
    const initials = auth.user?.name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

    function navigationLink(item: (typeof items)[number], compact = false) {
        const external = 'external' in item;
        const active = !external && !item.href.includes('#')
            && (currentPath === item.href || currentPath.startsWith(item.href + '/'));

        return (
            <a key={item.href} href={external ? item.href : hrefWithActiveSite(item.href, siteId)}
                {...(external ? { target: '_blank', rel: 'noreferrer' } : {})}
                aria-current={active ? 'page' : undefined} title={item.label}
                className="workspace-link">
                {compact ? <span aria-hidden="true">{item.label.slice(0, 2)}</span> : item.label}
                {compact && <span className="sr-only">{item.label}</span>}
                {external && !compact && <span aria-hidden="true" className="ml-auto">↗</span>}
            </a>
        );
    }

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
        <div className="workspace min-h-screen text-slate-950">
            <a href="#workspace-content" className="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-3">Skip to content</a>
            <aside className={`workspace-sidebar fixed inset-y-0 left-0 z-30 hidden md:flex md:flex-col ${collapsed ? 'md:w-20' : 'md:w-64'}`}>
                <div className="flex min-h-20 items-center justify-center gap-2 px-3">
                    {!collapsed && <a href="/dashboard" className="brand-lockup mr-auto">
                        <ApplicationLogo className="brand-mark" />
                    </a>}
                    <button type="button" className="workspace-menu-toggle" aria-expanded={!collapsed} aria-label={collapsed ? 'Expand menu' : 'Collapse menu'} onClick={() => setCollapsed((value) => !value)}>
                        {collapsed ? '»' : '«'}
                    </button>
                </div>
                <nav aria-label="Main" className="flex flex-1 flex-col gap-1 overflow-y-auto p-3">
                    {items.map((item) => navigationLink(item, collapsed))}
                </nav>
            </aside>
            <div className={collapsed ? 'md:pl-20' : 'md:pl-64'}>
                <header className="workspace-topbar flex min-h-20 flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-8">
                    <div className="flex items-center gap-3">
                        <span className="workspace-avatar" aria-hidden="true">{initials || 'LO'}</span>
                        <div>
                        <p className="font-semibold">{auth.user?.name ?? 'Guest'}</p>
                        {role && <p className="text-xs font-medium text-slate-600">{auth.role_label ?? role}</p>}
                        </div>
                    </div>
                    {sites.length > 1 && (
                        <label className="text-sm font-semibold">Active job site
                            <select aria-label="Active job site" className="ml-2 min-h-12 rounded-lg border-slate-300" value={siteId} onChange={(event) => chooseSite(event.target.value)}>
                                <option value="">All</option>
                                {sites.map((site) => <option key={site.id} value={String(site.id)}>{site.name}</option>)}
                            </select>
                        </label>
                    )}
                    <button type="button" className="min-h-12 rounded-lg border px-3 text-sm font-semibold md:hidden" aria-expanded={open} aria-label="Open profile menu" onClick={() => setOpen((value) => !value)}>Profile</button>
                    <div className={`${open ? 'flex' : 'hidden'} w-full flex-col gap-2 md:flex md:w-auto md:flex-row`}>
                        <a href="/profile" className="inline-flex min-h-12 items-center text-sm font-semibold">Profile</a>
                        <button type="button" className="min-h-12 text-left text-sm font-semibold" onClick={() => router.post('/logout')}>Sign out</button>
                    </div>
                </header>
                <main id="workspace-content" tabIndex={-1} className="workspace-content pb-24 md:pb-8">
                    {header && <div className="workspace-page-heading px-4 sm:px-8">{header}</div>}
                    {children}
                </main>
            </div>
            <nav aria-label="Mobile" className="workspace-mobile fixed inset-x-0 bottom-0 z-30 flex gap-1 overflow-x-auto p-2 md:hidden">
                {items.map((item) => navigationLink(item))}
            </nav>
        </div>
    );
}
