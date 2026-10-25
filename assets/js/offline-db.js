/**
 * FIS Real Estate — Offline Database Manager (Part 15.3)
 * IndexedDB wrapper for offline data caching.
 * Stores recently viewed properties, units, tenants, leases for offline access.
 */

class OfflineDB {
    constructor() {
        this.dbName = 'FIS_RE_DB';
        this.version = 1;
        this.db = null;
    }

    /**
     * Initialize the database
     */
    async init() {
        if (this.db) return this.db;
        try {
            return await this._openDB(this.version);
        } catch (error) {
            if (error.name === 'VersionError') {
                console.warn('[OfflineDB] Version conflict, opening existing...');
                try {
                    return await this._openDB();
                } catch (e) {
                    await this._deleteDB();
                    return await this._openDB(this.version);
                }
            }
            throw error;
        }
    }

    _openDB(version) {
        return new Promise((resolve, reject) => {
            const request = version
                ? indexedDB.open(this.dbName, version)
                : indexedDB.open(this.dbName);

            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                this.db = request.result;
                console.log('[OfflineDB] Ready (v' + this.db.version + ')');
                resolve(this.db);
            };
            request.onupgradeneeded = (event) => {
                this._createStores(event.target.result);
            };
        });
    }

    _deleteDB() {
        return new Promise((resolve, reject) => {
            if (this.db) { this.db.close(); this.db = null; }
            const req = indexedDB.deleteDatabase(this.dbName);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
            req.onblocked = () => resolve();
        });
    }

    /**
     * Create object stores
     */
    _createStores(db) {
        // Recently viewed data caches
        if (!db.objectStoreNames.contains('properties')) {
            const s = db.createObjectStore('properties', { keyPath: 'id' });
            s.createIndex('cached_at', 'cached_at', { unique: false });
        }
        if (!db.objectStoreNames.contains('units')) {
            const s = db.createObjectStore('units', { keyPath: 'id' });
            s.createIndex('property_id', 'property_id', { unique: false });
            s.createIndex('cached_at', 'cached_at', { unique: false });
        }
        if (!db.objectStoreNames.contains('tenants')) {
            const s = db.createObjectStore('tenants', { keyPath: 'id' });
            s.createIndex('cached_at', 'cached_at', { unique: false });
        }
        if (!db.objectStoreNames.contains('leases')) {
            const s = db.createObjectStore('leases', { keyPath: 'id' });
            s.createIndex('tenant_id', 'tenant_id', { unique: false });
            s.createIndex('cached_at', 'cached_at', { unique: false });
        }
        if (!db.objectStoreNames.contains('payments_cache')) {
            const s = db.createObjectStore('payments_cache', { keyPath: 'id' });
            s.createIndex('cached_at', 'cached_at', { unique: false });
        }

        // Dropdown data caches (for offline forms)
        if (!db.objectStoreNames.contains('dropdown_properties')) {
            db.createObjectStore('dropdown_properties', { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains('dropdown_units')) {
            db.createObjectStore('dropdown_units', { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains('dropdown_tenants')) {
            db.createObjectStore('dropdown_tenants', { keyPath: 'id' });
        }

        // Sync queue — pending offline operations
        if (!db.objectStoreNames.contains('sync_queue')) {
            const q = db.createObjectStore('sync_queue', { keyPath: 'id', autoIncrement: true });
            q.createIndex('timestamp', 'timestamp', { unique: false });
            q.createIndex('module', 'module', { unique: false });
            q.createIndex('status', 'status', { unique: false });
        }

        // Dashboard cache (for offline dashboard)
        if (!db.objectStoreNames.contains('dashboard_cache')) {
            db.createObjectStore('dashboard_cache', { keyPath: 'key' });
        }
    }

    // ============================================================
    // Generic CRUD
    // ============================================================

    async save(store, item) {
        const tx = this.db.transaction([store], 'readwrite');
        return new Promise((resolve, reject) => {
            const req = tx.objectStore(store).put(item);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async get(store, id) {
        const tx = this.db.transaction([store], 'readonly');
        return new Promise((resolve, reject) => {
            const req = tx.objectStore(store).get(id);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async getAll(store) {
        const tx = this.db.transaction([store], 'readonly');
        return new Promise((resolve, reject) => {
            const req = tx.objectStore(store).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
        });
    }

    async delete(store, id) {
        const tx = this.db.transaction([store], 'readwrite');
        return new Promise((resolve, reject) => {
            const req = tx.objectStore(store).delete(id);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
        });
    }

    async clear(store) {
        const tx = this.db.transaction([store], 'readwrite');
        return new Promise((resolve, reject) => {
            const req = tx.objectStore(store).clear();
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
        });
    }

    async count(store) {
        const tx = this.db.transaction([store], 'readonly');
        return new Promise((resolve, reject) => {
            const req = tx.objectStore(store).count();
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async replaceAll(store, items) {
        const tx = this.db.transaction([store], 'readwrite');
        const os = tx.objectStore(store);
        return new Promise((resolve, reject) => {
            const clearReq = os.clear();
            clearReq.onsuccess = () => {
                if (items.length === 0) { resolve(); return; }
                let done = 0;
                items.forEach((item) => {
                    const r = os.put(item);
                    r.onsuccess = () => { done++; if (done === items.length) resolve(); };
                    r.onerror = () => reject(r.error);
                });
            };
            clearReq.onerror = () => reject(clearReq.error);
        });
    }

    // ============================================================
    // Cache data with timestamp
    // ============================================================

    async cacheRecord(store, data) {
        data.cached_at = Date.now();
        return this.save(store, data);
    }

    async cacheRecords(store, dataArray) {
        const now = Date.now();
        const items = dataArray.map((d) => ({ ...d, cached_at: now }));
        const tx = this.db.transaction([store], 'readwrite');
        const os = tx.objectStore(store);
        return new Promise((resolve, reject) => {
            let done = 0;
            if (items.length === 0) { resolve(); return; }
            items.forEach((item) => {
                const r = os.put(item);
                r.onsuccess = () => { done++; if (done === items.length) resolve(); };
                r.onerror = () => reject(r.error);
            });
        });
    }

    /**
     * Evict old records (keep only last N)
     */
    async evictOld(store, maxRecords) {
        maxRecords = maxRecords || 100;
        const all = await this.getAll(store);
        if (all.length <= maxRecords) return;
        // Sort by cached_at ascending
        all.sort((a, b) => (a.cached_at || 0) - (b.cached_at || 0));
        const toRemove = all.slice(0, all.length - maxRecords);
        for (const item of toRemove) {
            await this.delete(store, item.id);
        }
    }

    // ============================================================
    // Sync Queue
    // ============================================================

    async addToQueue(operation) {
        const item = {
            ...operation,
            timestamp: Date.now(),
            status: 'pending'
        };
        return this.save('sync_queue', item);
    }

    async getQueue() {
        const all = await this.getAll('sync_queue');
        return all.sort((a, b) => a.timestamp - b.timestamp);
    }

    async getPendingQueue() {
        const all = await this.getQueue();
        return all.filter((i) => i.status === 'pending');
    }

    async getQueueCount() {
        return this.count('sync_queue');
    }

    async updateQueueItem(id, updates) {
        const item = await this.get('sync_queue', id);
        if (!item) return;
        Object.assign(item, updates);
        return this.save('sync_queue', item);
    }

    async removeFromQueue(id) {
        return this.delete('sync_queue', id);
    }

    async clearQueue() {
        return this.clear('sync_queue');
    }

    // ============================================================
    // Dashboard Cache
    // ============================================================

    async cacheDashboard(data) {
        return this.save('dashboard_cache', { key: 'dashboard', data: data, cached_at: Date.now() });
    }

    async getDashboardCache() {
        return this.get('dashboard_cache', 'dashboard');
    }
}

// Singleton
window.offlineDB = new OfflineDB();
