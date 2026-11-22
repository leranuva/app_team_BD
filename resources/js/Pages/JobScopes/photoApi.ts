import axios from 'axios';

export type ScopePhotoItem = {
    id: number;
    job_scope_id: number;
    caption: string | null;
    url: string | null;
    thumbnail_url: string | null;
    taken_at: string | null;
    uploaded_by: { id: number; name: string } | null;
    lat: number | null;
    lng: number | null;
    archived_at: string | null;
    can_archive: boolean;
    can_restore: boolean;
};

export type PhotoStatus = 'active' | 'archived' | 'all';
export type PhotoPage = { data: ScopePhotoItem[]; meta: { current_page: number; last_page: number; total: number } };

const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

export function failureMessage(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) return fallback;
    const status = error.response?.status;
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    if (status === 422) {
        return data?.message === 'El archivo original ya no está disponible'
            ? data.message
            : first ?? data?.message ?? 'Revisa los datos de la foto.';
    }
    if (status === 403) return 'Sin permisos';
    if (status === 429) return 'Demasiadas operaciones, espera un momento.';
    return fallback;
}

export async function fetchPhotos(scopeId: number, status: PhotoStatus, page: number, signal?: AbortSignal): Promise<PhotoPage> {
    const response = await axios.get(`/api/job-scopes/${scopeId}/photos`, {
        params: { status, page },
        signal,
        headers,
    });
    return { data: response.data.data, meta: response.data.meta };
}

export async function uploadPhoto(scopeId: number, file: File, caption: string, onProgress: (value: number) => void) {
    const body = new FormData();
    body.append('file', file);
    if (caption.trim() !== '') body.append('caption', caption.trim());
    try {
        const response = await axios.post(`/api/job-scopes/${scopeId}/photos`, body, {
            headers,
            onUploadProgress: (event) => onProgress(event.total ? Math.round((event.loaded * 100) / event.total) : 0),
        });
        return { ok: true as const, photo: response.data.data as ScopePhotoItem };
    } catch (error) {
        return { ok: false as const, message: failureMessage(error, 'No se pudo subir la foto.') };
    }
}

export async function archivePhoto(id: number, reason: string) {
    try {
        const response = await axios.patch(`/api/scope-photos/${id}/archive`, {
            reason: reason.trim() === '' ? null : reason.trim(),
        }, { headers });
        return { ok: true as const, photo: response.data.data as ScopePhotoItem };
    } catch (error) {
        return { ok: false as const, message: failureMessage(error, 'No se pudo archivar la foto.') };
    }
}

export async function restorePhoto(id: number) {
    try {
        const response = await axios.patch(`/api/scope-photos/${id}/restore`, {}, { headers });
        return { ok: true as const, photo: response.data.data as ScopePhotoItem };
    } catch (error) {
        return { ok: false as const, message: failureMessage(error, 'No se pudo restaurar la foto.') };
    }
}
