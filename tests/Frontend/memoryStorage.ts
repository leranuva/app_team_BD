export function installMemoryStorage(): void {
    const store = new Map<string, string>();
    const memory: Storage = {
        getItem: (key) => store.get(key) ?? null,
        setItem: (key, value) => { store.set(key, String(value)); },
        removeItem: (key) => { store.delete(key); },
        clear: () => { store.clear(); },
        key: (index) => [...store.keys()][index] ?? null,
        get length() { return store.size; },
    };

    Object.defineProperty(window, 'localStorage', { configurable: true, value: memory });
    Object.defineProperty(globalThis, 'localStorage', { configurable: true, value: memory });
}
