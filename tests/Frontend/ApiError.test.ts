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
        expect(apiErrorMessage(http(401))).toBe('Your session expired. Sign in again.');
        expect(apiErrorMessage(http(403))).toBe('You do not have permission to perform this action.');
        expect(apiErrorMessage(http(404))).toBe('This item no longer exists.');
        expect(apiErrorMessage(http(409, { message: 'Otro usuario la cambió.' }))).toBe('Otro usuario la cambió.');
        expect(apiErrorMessage(http(422, { errors: { title: ['El título es obligatorio.'] } }))).toBe('El título es obligatorio.');
        expect(apiErrorMessage(http(429))).toBe('Too many requests. Try again shortly.');
        expect(retryAfterSeconds(http(429, {}, { 'retry-after': '12' }))).toBe(12);
        expect(apiErrorMessage(http(500))).toBe('Server error. Try again.');
        expect(apiErrorMessage(new Error('offline'))).toBe('Connection error.');
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
