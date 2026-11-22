import { router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import { apiErrorMessage, retryAfterSeconds } from '@/lib/apiError';

export function useApiError(toasterId?: string) {
    return (error: unknown) => {
        const message = apiErrorMessage(error);
        toast.error(message, toasterId ? { toasterId } : undefined);
        const status = axios.isAxiosError(error) ? error.response?.status : undefined;
        if (status === 401 || status === 419) {
            const returnTo = `${window.location.pathname}${window.location.search}`;
            router.visit(`/login?returnTo=${encodeURIComponent(returnTo)}`);
        }
        const wait = retryAfterSeconds(error);
        if (wait !== null) {
            toast.message(`You can try again in ${wait}s.`, toasterId ? { toasterId } : undefined);
        }
    };
}
