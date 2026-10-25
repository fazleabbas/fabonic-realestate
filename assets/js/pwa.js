/**
 * FIS Real Estate — PWA Controller (Part 15.5)
 * Handles: Service Worker registration, install prompt, offline UI indicators.
 */

(function() {
    'use strict';

    // ============================================================
    // Service Worker Registration
    // ============================================================
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register(BASE_URL + 'sw.js', { scope: BASE_URL })
                .then(function(registration) {
                    console.log('[PWA] SW registered, scope:', registration.scope);

                    // Check for updates periodically (every 30 min)
                    setInterval(function() {
                        registration.update();
                    }, 30 * 60 * 1000);

                    // Handle SW updates
                    registration.addEventListener('updatefound', function() {
                        var newWorker = registration.installing;
                        newWorker.addEventListener('statechange', function() {
                            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                // New version available
                                showUpdateBanner();
                            }
                        });
                    });
                })
                .catch(function(error) {
                    console.warn('[PWA] SW registration failed:', error);
                });
        });
    }

    // ============================================================
    // Install Prompt (beforeinstallprompt)
    // ============================================================
    var deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        showInstallBanner();
    });

    window.addEventListener('appinstalled', function() {
        deferredPrompt = null;
        hideInstallBanner();
        console.log('[PWA] App installed');
    });

    function showInstallBanner() {
        // Don't show if dismissed recently
        var dismissed = localStorage.getItem('pwa_install_dismissed');
        if (dismissed && (Date.now() - parseInt(dismissed)) < 7 * 24 * 60 * 60 * 1000) return;

        var banner = document.getElementById('pwaInstallBanner');
        if (banner) {
            banner.classList.remove('d-none');
        }
    }

    function hideInstallBanner() {
        var banner = document.getElementById('pwaInstallBanner');
        if (banner) banner.classList.add('d-none');
    }

    // Global: trigger install from banner button
    window.pwaInstall = function() {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function(choice) {
            if (choice.outcome === 'accepted') {
                console.log('[PWA] Install accepted');
            }
            deferredPrompt = null;
            hideInstallBanner();
        });
    };

    // Global: dismiss install banner
    window.pwaDismissInstall = function() {
        hideInstallBanner();
        localStorage.setItem('pwa_install_dismissed', Date.now().toString());
    };

    // ============================================================
    // Update available banner
    // ============================================================
    function showUpdateBanner() {
        var banner = document.getElementById('pwaUpdateBanner');
        if (banner) {
            banner.classList.remove('d-none');
        }
    }

    // Global: apply update
    window.pwaUpdate = function() {
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'SKIP_WAITING' });
            window.location.reload();
        }
    };

    // ============================================================
    // Initialize SyncManager on page load
    // ============================================================
    window.addEventListener('load', function() {
        // Initialize sync manager
        if (typeof SyncManager !== 'undefined' && window.syncManager) {
            window.syncManager.init().catch(function(e) {
                console.warn('[PWA] SyncManager init failed:', e);
            });
        }

        // Auto-cache current page data for offline access
        autoCachePageData();
    });

    // ============================================================
    // Auto-cache page data based on current URL
    // ============================================================
    function autoCachePageData() {
        if (!navigator.onLine) return;
        if (!window.offlineDB || !window.syncManager) return;

        var path = window.location.pathname;

        // Cache dashboard stats
        if (path.endsWith('/realestate/') || path.endsWith('/realestate/index.php')) {
            cacheDashboardData();
        }

        // Intercept AJAX responses to auto-cache data
        interceptAjaxForCaching();
    }

    /**
     * Cache dashboard stats for offline
     */
    function cacheDashboardData() {
        // Read stats from the DOM
        var stats = document.querySelectorAll('.stat-card-value');
        if (stats.length > 0) {
            var dashData = {
                timestamp: Date.now(),
                values: []
            };
            stats.forEach(function(el) {
                var label = el.parentElement.querySelector('.stat-card-label');
                dashData.values.push({
                    value: el.textContent.trim(),
                    label: label ? label.textContent.trim() : ''
                });
            });
            offlineDB.init().then(function() {
                offlineDB.cacheDashboard(dashData);
            });
        }
    }

    /**
     * Intercept jQuery AJAX to auto-cache API responses
     */
    function interceptAjaxForCaching() {
        if (typeof $ === 'undefined') return;

        $(document).ajaxSuccess(function(event, xhr, settings) {
            try {
                var response = xhr.responseJSON;
                if (!response || !response.success || !response.data) return;

                var url = settings.url || '';

                // Cache property data
                if (url.includes('api/properties.php')) {
                    var action = getUrlParam(url, 'action') || getFormDataAction(settings.data);
                    if (action === 'list' && Array.isArray(response.data)) {
                        window.syncManager.cacheViewedData('properties', response.data);
                        // Also cache for dropdowns
                        var dropdownData = response.data.map(function(p) {
                            return { id: p.id, name: p.name, property_type: p.property_type, status: p.status };
                        });
                        window.syncManager.cacheViewedData('dropdown_properties', dropdownData);
                    } else if (action === 'get' && response.data.id) {
                        window.syncManager.cacheViewedData('properties', response.data);
                    }
                }

                // Cache unit data
                if (url.includes('api/units.php')) {
                    var action = getUrlParam(url, 'action') || getFormDataAction(settings.data);
                    if (action === 'list' && Array.isArray(response.data)) {
                        window.syncManager.cacheViewedData('units', response.data);
                    } else if (action === 'get' && response.data.id) {
                        window.syncManager.cacheViewedData('units', response.data);
                    }
                }

                // Cache tenant data
                if (url.includes('api/tenants.php')) {
                    var action = getUrlParam(url, 'action') || getFormDataAction(settings.data);
                    if (action === 'list' && Array.isArray(response.data)) {
                        window.syncManager.cacheViewedData('tenants', response.data);
                    } else if (action === 'get' && response.data.id) {
                        window.syncManager.cacheViewedData('tenants', response.data);
                    }
                }

                // Cache lease data
                if (url.includes('api/leases.php')) {
                    var action = getUrlParam(url, 'action') || getFormDataAction(settings.data);
                    if (action === 'list' && Array.isArray(response.data)) {
                        window.syncManager.cacheViewedData('leases', response.data);
                    } else if (action === 'get' && response.data.id) {
                        window.syncManager.cacheViewedData('leases', response.data);
                    }
                }
            } catch (e) {
                // Silent fail — caching is best-effort
            }
        });
    }

    // Helper to get param from URL string
    function getUrlParam(url, name) {
        var match = url.match(new RegExp('[?&]' + name + '=([^&]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    // Helper to get 'action' from form data string
    function getFormDataAction(data) {
        if (!data) return null;
        if (typeof data === 'string') {
            var match = data.match(/action=([^&]*)/);
            return match ? decodeURIComponent(match[1]) : null;
        }
        return null;
    }

})();
