import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-slate-950 px-4 pt-10 sm:justify-center sm:pt-0">
            <div>
                <Link href="/" className="flex items-center gap-3 text-lg font-semibold text-white">
                    <ApplicationLogo className="h-10 w-10 rounded-lg bg-amber-300 p-2 text-slate-950" />
                    Logística de Obra
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden rounded-xl bg-white px-6 py-6 shadow-md sm:max-w-md">
                {children}
            </div>
        </div>
    );
}
