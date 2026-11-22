import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { apiErrorMessage, retryAfterSeconds } from '@/lib/apiError';

export function useApiError(toasterId?: string) {
    return (error: unknown) => {
        const message = apiErrorMessage(error);
        toast.error(message, toasterId ? { toasterId } : undefined);
        if (message.startsWith('Sesión expirada')) {
            const returnTo = `${window.location.pathname}${window.location.search}`;
            router.visit(`/login?returnTo=${encodeURIComponent(returnTo)}`);
        }
        const wait = retryAfterSeconds(error);
        if (wait !== null) {
            toast.message(`Puedes reintentar en ${wait} s.`, toasterId ? { toasterId } : undefined);
        }
    };
}
