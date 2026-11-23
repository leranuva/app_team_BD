window.blueDraftJobSiteAddress = function (wire, path) {
    const geosearch = 'https://geosearch.planninglabs.nyc/v2/autocomplete';
    const parent = path.slice(0, -'address'.length);
    const latitude = parent + 'latitude';
    const longitude = parent + 'longitude';
    let locatedAddress = wire.$get(path);
    let timer;
    let request;

    return {
        results: [],
        active: -1,
        open: false,
        loading: false,
        message: '',

        search(value) {
            this.close();

            if (locatedAddress && value !== locatedAddress) {
                wire.$set(latitude, null, false);
                wire.$set(longitude, null, false);
                locatedAddress = null;
            }

            const text = value.trim();
            if (text.length >= 4) {
                timer = setTimeout(() => this.lookup(text), 320);
            }
        },

        async lookup(text) {
            const current = new AbortController();
            request = current;
            this.loading = true;
            this.message = 'Looking up addresses…';
            const timeout = setTimeout(() => current.abort(), 8_000);

            try {
                const params = new URLSearchParams({ text, size: '6' });
                const response = await fetch(`${geosearch}?${params}`, { signal: current.signal, credentials: 'omit' });
                if (!response.ok) throw new Error('Address lookup failed');
                const { features } = await response.json();
                if (request !== current) return;

                this.results = features.flatMap(({ properties, geometry }) => {
                    const label = properties?.label;
                    const [lng, lat] = geometry?.coordinates ?? [];

                    if (!properties?.housenumber || !properties.street
                        || typeof label !== 'string' || label.length === 0 || label.length > 255
                        || !Number.isFinite(lat) || !Number.isFinite(lng)
                        || Math.abs(lat) > 90 || Math.abs(lng) > 180) return [];

                    return [{ label, latitude: lat.toFixed(6), longitude: lng.toFixed(6) }];
                });
                this.open = this.results.length > 0;
                this.message = this.open
                    ? `${this.results.length} address suggestions available.`
                    : 'No matching NYC addresses. You can enter the address and coordinates manually.';
            } catch {
                if (request === current) {
                    this.message = 'Address lookup is unavailable. You can enter the address and coordinates manually.';
                }
            } finally {
                clearTimeout(timeout);
                if (request === current) {
                    request = null;
                    this.loading = false;
                }
            }
        },

        move(direction) {
            if (!this.open) return;
            if (this.active < 0) {
                this.active = direction > 0 ? 0 : this.results.length - 1;
                return;
            }
            this.active = (this.active + direction + this.results.length) % this.results.length;
        },

        select(index) {
            const result = this.results[index];
            if (!result) return;

            locatedAddress = result.label;
            wire.$set(path, result.label, false);
            wire.$set(latitude, result.latitude, false);
            wire.$set(longitude, result.longitude, false);
            this.close();
        },

        close() {
            clearTimeout(timer);
            request?.abort();
            request = null;
            this.results = [];
            this.active = -1;
            this.open = false;
            this.loading = false;
            this.message = '';
        },

        destroy() {
            this.close();
        },
    };
};
