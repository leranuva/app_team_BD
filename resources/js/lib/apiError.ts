import axios, { type AxiosInstance } from 'axios';

export function apiErrorMessage(error: unknown): string {
    if (!axios.isAxiosError(error) || !error.response) {
        return 'Connection error.';
    }

    const data = error.response.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;

    switch (error.response.status) {
        case 401:
        case 419:
            return 'Your session expired. Sign in again.';
        case 403:
            return 'You do not have permission to perform this action.';
        case 404:
            return 'This item no longer exists.';
        case 409:
            return data?.message || 'Another user updated this item.';
        case 422:
            return first || data?.message || 'Check the information you entered.';
        case 429:
            return 'Too many requests. Try again shortly.';
        default:
            return error.response.status >= 500
                ? 'Server error. Try again.'
                : (data?.message || 'Could not complete the action.');
    }
}

export function retryAfterSeconds(error: unknown): number | null {
    if (!axios.isAxiosError(error) || error.response?.status !== 429) {
        return null;
    }

    const header = error.response.headers?.['retry-after'];
    const seconds = Number(header);

    return Number.isFinite(seconds) && seconds > 0 ? seconds : null;
}

export function installSessionGuard(client: AxiosInstance): void {
    client.interceptors.response.use((response) => response, (error: unknown) => {
        if (axios.isAxiosError(error) && error.response?.status === 401 && typeof window !== 'undefined') {
            const returnTo = `${window.location.pathname}${window.location.search}`;
            window.location.assign(`/login?returnTo=${encodeURIComponent(returnTo)}`);
        }

        return Promise.reject(error);
    });
}
