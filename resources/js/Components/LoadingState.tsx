export default function LoadingState({ label = 'Cargando…' }: { label?: string }) {
    return (
        <div role="status" className="flex items-center gap-3 py-6 text-sm text-slate-700" aria-live="polite">
            <span className="inline-block h-5 w-5 animate-spin rounded-full border-2 border-slate-300 border-t-slate-950 motion-reduce:animate-none" aria-hidden="true" />
            {label}
        </div>
    );
}
