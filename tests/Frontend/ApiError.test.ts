// @vitest-environment jsdom
import axios from 'axios';
import { apiErrorMessage, installSessionGuard, retryAfterSeconds } from '@/lib/apiError';
import { describe, expect, it, vi } from 'vitest';

function http(status: number, data: unknown = {}, headers: Record<string, string> = {}) {
    return { isAxiosError: true, response: { status, data, headers } };
}

describe('api errors', () => {
    it('maps each status to a Spanish message', () => {
        vi.spyOn(axios, 'isAxiosError').mockReturnValue(true);
        expect(apiErrorMessage(http(401))).toBe('Sesión expirada. Vuelve a iniciar sesión.');
        expect(apiErrorMessage(http(403))).toBe('Sin permisos para esta acción.');
        expect(apiErrorMessage(http(404))).toBe('El recurso ya no existe.');
        expect(apiErrorMessage(http(409, { message: 'Otro usuario la cambió.' }))).toBe('Otro usuario la cambió.');
        expect(apiErrorMessage(http(422, { errors: { title: ['El título es obligatorio.'] } }))).toBe('El título es obligatorio.');
        expect(apiErrorMessage(http(429))).toBe('Demasiadas solicitudes. Espera un momento.');
        expect(retryAfterSeconds(http(429, {}, { 'retry-after': '12' }))).toBe(12);
        expect(apiErrorMessage(http(500))).toBe('Error del servidor. Intenta de nuevo.');
        expect(apiErrorMessage(new Error('offline'))).toBe('Error de conexión.');
    });

    it('redirects to login and keeps the return path on 401', async () => {
        const assign = vi.fn();
        vi.stubGlobal('location', { pathname: '/material-lists', search: '?status=draft', assign });
        const client = axios.create({
            adapter: () => Promise.reject(new axios.AxiosError('expired', '401', undefined, undefined, {
                status: 401, data: {}, headers: {}, config: { headers: new axios.AxiosHeaders() }, statusText: 'Unauthorized',
            })),
        });
        installSessionGuard(client);
        await expect(client.get('/api/material-lists')).rejects.toBeTruthy();
        expect(assign).toHaveBeenCalledWith('/login?returnTo=' + encodeURIComponent('/material-lists?status=draft'));
    });
});
