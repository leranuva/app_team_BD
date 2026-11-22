export const ACTIVE_SITE_KEY = 'obra-activa';

const memory = new Map<string, string>();

function storage(): Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> {
    try {
        if (typeof window.localStorage?.getItem === 'function') {
            return window.localStorage;
        }
    } catch {
        // The browser blocked storage. The memory map keeps the choice for this tab.
    }

    return {
        getItem: (key) => memory.get(key) ?? null,
        setItem: (key, value) => memory.set(key, value),
        removeItem: (key) => memory.delete(key),
    };
}

export function readActiveSite(): string {
    return storage().getItem(ACTIVE_SITE_KEY) ?? '';
}

export function writeActiveSite(id: string): void {
    const store = storage();
    if (id) {
        store.setItem(ACTIVE_SITE_KEY, id);
    } else {
        store.removeItem(ACTIVE_SITE_KEY);
    }
}

export function hrefWithActiveSite(href: string, siteId: string): string {
    if (!siteId || href.startsWith('/admin')) {
        return href;
    }

    const url = new URL(href, 'http://local.test');
    url.searchParams.set('job_site_id', siteId);

    return `${url.pathname}${url.search}${url.hash}`;
}
