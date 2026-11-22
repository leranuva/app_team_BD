import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

function pngSize(path: string): { width: number; height: number } {
    const file = readFileSync(path);

    return { width: file.readUInt32BE(16), height: file.readUInt32BE(20) };
}

describe('pwa manifest', () => {
    it('can be installed without a service worker', () => {
        const manifest = JSON.parse(readFileSync(resolve('public/manifest.json'), 'utf8'));
        expect(manifest.name).toBe('Logística de Obra');
        expect(manifest.display).toBe('standalone');
        expect(manifest.theme_color).toBe('#020617');
        expect(manifest.background_color).toBe('#f1f5f9');
        expect(manifest.start_url).toBe('/worker/today');
        expect(manifest.icons.map((icon: { sizes: string }) => icon.sizes)).toEqual(['192x192', '512x512']);
        expect(pngSize(resolve('public/icons/icon-192.png'))).toEqual({ width: 192, height: 192 });
        expect(pngSize(resolve('public/icons/icon-512.png'))).toEqual({ width: 512, height: 512 });
        expect(existsSync(resolve('public/sw.js'))).toBe(false);
    });
});
