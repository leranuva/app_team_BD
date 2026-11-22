export default function EmptyState({ title, action }: { title: string; action?: { href: string; label: string } }) {
    return (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-8 text-center">
            <p className="text-sm text-slate-700">{title}</p>
            {action && (
                <a href={action.href} className="mt-4 inline-flex min-h-12 items-center rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white">
                    {action.label}
                </a>
            )}
        </div>
    );
}
