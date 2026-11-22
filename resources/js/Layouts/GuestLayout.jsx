import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="auth-layout">
            <div className="auth-brand">
                <Link href="/" className="brand-lockup">
                    <ApplicationLogo className="brand-mark auth-brand-mark" />
                </Link>
                <p className="auth-brand-title">Blue Draft Team</p>
                <p className="auth-brand-description">Scheduling, attendance and project tracking.</p>
            </div>
            <main className="auth-main">
                <div className="auth-card">{children}</div>
            </main>
        </div>
    );
}
