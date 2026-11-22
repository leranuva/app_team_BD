import axios, { type AxiosInstance } from 'axios';

export function apiErrorMessage(error: unknown): string {
    if (!axios.isAxiosError(error) || !error.response) {
        return 'Error de conexión.';
    }

    const data = error.response.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;

    switch (error.response.status) {
        case 401:
            return 'Sesión expirada. Vuelve a iniciar sesión.';
        case 403:
            return 'Sin permisos para esta acción.';
        case 404:
            return 'El recurso ya no existe.';
        case 409:
            return data?.message || 'Conflicto de concurrencia.';
        case 422:
            return first || data?.message || 'Revisa los datos.';
        case 429:
            return 'Demasiadas solicitudes. Espera un momento.';
        default:
            return error.response.status >= 500
                ? 'Error del servidor. Intenta de nuevo.'
                : (data?.message || 'No se pudo completar la acción.');
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
