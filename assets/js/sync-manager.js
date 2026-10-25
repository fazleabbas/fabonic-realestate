/**
 * FIS Real Estate — Sync Manager (Part 15.4)
 * Handles background sync of offline operations and data caching.
 */

class SyncManager {
    constructor() {
        this.isOnline = navigator.onLine;
        this.isSyncing = false;
        this.apiBase = (typeof BASE_URL !== 'undefined' ? BASE_URL : './') + 'api/';

        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());
    }

    /**
     * Initialize sync manager
     */
    async init() {
        console.log('[SyncManager] Initializing...');
        await offlineDB.init();
        this.updateUI();

        // Listen for service worker messages
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.addEventListener('message', (event) => {
                if (event.data && event.data.type === 'TRIGGER_SYNC') {
                    this.syncAll();
                }
            });
        }

        // If online, sync any pending items
        if (this.isOnline) {
            const count = await offlineDB.getQueueCount();
            if (count > 0) {
                console.log('[SyncManager] Found ' + count + ' pending items, syncing...');
                this.syncAll();
            }
        }

        console.log('[SyncManager] Ready — ' + (this.isOnline ? 'Online' : 'Offline'));
    }

    // ============================================================
    // Online/Offline handlers
    // ============================================================

    handleOnline() {
        this.isOnline = true;
        console.log('[SyncManager] Back online');
        this.updateUI();
        this.showToast('online');
        this.syncAll();
    }

    handleOffline() {
        this.isOnline = false;
        console.log('[SyncManager] Gone offline');
        this.updateUI();
        this.showToast('offline');
    }

    // ============================================================
    // Queue operations for offline sync
    // ============================================================

    /**
     * Queue an API action for later sync
     * @param {string} module - e.g. 'payments', 'maintenance'
     * @param {string} action - e.g. 'create_payment'
     * @param {string} apiEndpoint - e.g. 'payments.php'
     * @param {object} data - Form data to submit
     */
    async queueAction(module, action, apiEndpoint, data) {
        const queueItem = {
            module: module,
            action: action,
            api_endpoint: apiEndpoint,
            data: data,
            created_at: new Date().toISOString()
        };

        await offlineDB.addToQueue(queueItem);
        this.updateUI();

        // Try to register background sync
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            try {
                const reg = await navigator.serviceWorker.ready;
                await reg.sync.register('sync-queue');
            } catch (e) {
                console.log('[SyncManager] Background sync registration failed:', e);
            }
        }

        return queueItem;
    }

    // ============================================================
    // Sync all pending operations
    // ============================================================

    async syncAll() {
        if (!this.isOnline || this.isSyncing) return;
        this.isSyncing = true;
        this.updateUI();

        try {
            const queue = await offlineDB.getPendingQueue();
            if (queue.length === 0) {
                this.isSyncing = false;
                this.updateUI();
                return;
            }

            console.log('[SyncManager] Syncing ' + queue.length + ' items...');
            let synced = 0;
            let failed = 0;

            for (const item of queue) {
                try {
                    await offlineDB.updateQueueItem(item.id, { status: 'syncing' });
                    const result = await this._sendToServer(item);

                    if (result.success) {
                        await offlineDB.removeFromQueue(item.id);
                        synced++;
                    } else {
                        await offlineDB.updateQueueItem(item.id, {
                            status: 'failed',
                            error: result.message || 'Server rejected',
                            last_attempt: Date.now()
                        });
                        failed++;
                    }
                } catch (error) {
                    await offlineDB.updateQueueItem(item.id, {
                        status: 'pending',
                        error: error.message,
                        last_attempt: Date.now()
                    });
                    failed++;
                    // If we're offline now, stop trying
                    if (!navigator.onLine) break;
                }
            }

            if (synced > 0) {
                this.showToast('synced', synced);
            }
            if (failed > 0) {
                console.warn('[SyncManager] ' + failed + ' items failed to sync');
            }
        } catch (error) {
            console.error('[SyncManager] Sync error:', error);
        }

        this.isSyncing = false;
        this.updateUI();
    }

    /**
     * Send a queued item to the server
     */
    async _sendToServer(item) {
        const url = this.apiBase + item.api_endpoint;
        const formData = new FormData();

        // Add action and CSRF token
        formData.append('action', item.action);

        // Add all data fields
        if (item.data && typeof item.data === 'object') {
            for (const [key, value] of Object.entries(item.data)) {
                if (value !== null && value !== undefined) {
                    formData.append(key, value);
                }
            }
        }

        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        return await response.json();
    }

    // ============================================================
    // Cache data from API responses (auto-cache on view)
    // ============================================================

    /**
     * Cache data when a page loads (called by individual modules)
     */
    async cacheViewedData(store, data) {
        if (!data) return;
        try {
            if (Array.isArray(data)) {
                await offlineDB.cacheRecords(store, data);
            } else {
                await offlineDB.cacheRecord(store, data);
            }
            // Keep stores manageable
            await offlineDB.evictOld(store, 200);
        } catch (e) {
            console.warn('[SyncManager] Cache failed:', e);
        }
    }

    /**
     * Get cached data for offline display
     */
    async getCachedData(store, id) {
        try {
            if (id) {
                return await offlineDB.get(store, parseInt(id));
            }
            return await offlineDB.getAll(store);
        } catch (e) {
            console.warn('[SyncManager] Get cache failed:', e);
            return id ? null : [];
        }
    }

    // ============================================================
    // UI Updates
    // ============================================================

    async updateUI() {
        // Update online/offline indicator
        const indicator = document.getElementById('offlineIndicator');
        const statusDot = document.getElementById('offlineStatusDot');
        const statusText = document.getElementById('offlineStatusText');

        if (indicator) {
            if (!this.isOnline) {
                indicator.classList.add('offline');
                indicator.classList.remove('d-none');
            } else {
                indicator.classList.remove('offline');
                // Keep visible briefly after coming online, then hide
                setTimeout(() => {
                    if (this.isOnline) indicator.classList.add('d-none');
                }, 3000);
            }
        }

        if (statusDot) {
            statusDot.className = 'status-dot ' + (this.isOnline ? 'online' : 'offline');
        }

        if (statusText) {
            if (this.isSyncing) {
                statusText.textContent = typeof __ === 'function' ? __('sync_syncing') : 'Syncing...';
            } else if (!this.isOnline) {
                statusText.textContent = typeof __ === 'function' ? __('offline_mode') : 'Offline';
            } else {
                statusText.textContent = typeof __ === 'function' ? __('online') : 'Online';
            }
        }

        // Update pending badge
        const badge = document.getElementById('syncPendingBadge');
        if (badge) {
            try {
                const count = await offlineDB.getQueueCount();
                if (count > 0) {
                    badge.textContent = count;
                    badge.classList.remove('d-none');
                } else {
                    badge.classList.add('d-none');
                }
            } catch (e) {
                badge.classList.add('d-none');
            }
        }

        // Update sync indicator
        const syncIcon = document.getElementById('syncIndicator');
        if (syncIcon) {
            if (this.isSyncing) {
                syncIcon.classList.add('syncing');
                syncIcon.classList.remove('d-none');
            } else {
                syncIcon.classList.remove('syncing');
                syncIcon.classList.add('d-none');
            }
        }
    }

    /**
     * Show toast notification for online/offline/sync events
     */
    showToast(type, count) {
        let message = '';
        let bgClass = '';

        switch (type) {
            case 'online':
                message = typeof __ === 'function' ? __('pwa_back_online') : 'You are back online';
                bgClass = 'bg-success';
                break;
            case 'offline':
                message = typeof __ === 'function' ? __('pwa_gone_offline') : 'You are offline. Changes will be saved locally.';
                bgClass = 'bg-warning text-dark';
                break;
            case 'synced':
                message = (typeof __ === 'function' ? __('sync_completed') : 'Synced') + ' (' + count + ')';
                bgClass = 'bg-success';
                break;
        }

        // Use Bootstrap toast if available, else simple DIV
        let container = document.getElementById('pwaToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'pwaToastContainer';
            container.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:9999;';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = 'toast show align-items-center border-0 text-white ' + bgClass;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = '<div class="d-flex"><div class="toast-body">' +
            '<i class="bi bi-' + (type === 'offline' ? 'wifi-off' : (type === 'synced' ? 'check-circle' : 'wifi')) + ' me-2"></i>' +
            message + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';

        container.appendChild(toast);

        // Auto-remove after 4 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
}

// Singleton
window.syncManager = new SyncManager();
