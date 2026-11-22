export default function EmptyState({ title, action, field = false }: { title: string; action?: { href: string; label: string }; field?: boolean }) {
    return (
        <div className={field
            ? 'rounded-xl border border-dashed border-slate-400 bg-slate-100 px-5 py-8 text-center text-slate-950'
            : 'rounded-xl border border-dashed border-slate-300 bg-white px-5 py-8 text-center'}>
            <p className={field ? 'text-base font-medium text-slate-950' : 'text-sm text-slate-700'}>{title}</p>
            {action && (
                <a href={action.href} className="mt-4 inline-flex min-h-12 items-center rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white">
                    {action.label}
                </a>
            )}
        </div>
    );
}
