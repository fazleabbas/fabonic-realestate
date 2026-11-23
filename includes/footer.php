</main> <!-- Close re-main -->

<!-- Footer -->
<footer class="re-footer">
    <small><?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?> &copy; <?php echo date('Y'); ?> &mdash; <?php _e('app_tagline'); ?></small>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- App JS -->
<script src="<?php echo BASE_URL; ?>assets/js/main.js?v=1.0"></script>
<!-- PWA: Offline DB + Sync Manager + PWA Controller -->
<script src="<?php echo BASE_URL; ?>assets/js/offline-db.js?v=1.0"></script>
<script src="<?php echo BASE_URL; ?>assets/js/sync-manager.js?v=1.0"></script>
<script src="<?php echo BASE_URL; ?>assets/js/pwa.js?v=1.0"></script>

<?php if (isset($customJS)): ?>
<script src="<?php echo BASE_URL . $customJS; ?>"></script>
<?php endif; ?>

<?php
// --- Session Timeout Client-Side Warning ---
$sessionTimeoutMin = (int)getSetting('session_timeout_minutes', 30);
if ($sessionTimeoutMin > 0 && isLoggedIn()):
    $warningBeforeSec = min(60, $sessionTimeoutMin * 60); // warn 60s before, or less for short timeouts
?>
<!-- Session Timeout Warning Modal -->
<div class="modal fade" id="sessionTimeoutModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <i class="bi bi-clock-history text-warning" style="font-size:2.5rem;"></i>
                <h6 class="mt-3 fw-bold"><?php _e('set_timeout_title', 'Session Expiring'); ?></h6>
                <p class="text-muted mb-1"><?php _e('set_timeout_msg', 'You will be logged out due to inactivity in'); ?></p>
                <h3 class="fw-bold text-danger" id="timeoutCountdown">60</h3>
                <small class="text-muted"><?php _e('seconds', 'seconds'); ?></small>
                <div class="mt-3">
                    <button type="button" class="btn btn-primary btn-sm" id="extendSessionBtn">
                        <i class="bi bi-arrow-repeat me-1"></i><?php _e('set_timeout_extend', 'Stay Logged In'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    const TIMEOUT_SEC = <?php echo $sessionTimeoutMin * 60; ?>;
    const WARN_BEFORE = <?php echo $warningBeforeSec; ?>;
    const LOGOUT_URL  = BASE_URL + 'modules/auth/logout.php';

    let idleTimer, warnTimer, countdownInterval;
    let modal = null;

    function resetTimers() {
        clearTimeout(idleTimer);
        clearTimeout(warnTimer);
        clearInterval(countdownInterval);
        if (modal) { try { modal.hide(); } catch(e){} }

        // Set warning timer
        warnTimer = setTimeout(showWarning, (TIMEOUT_SEC - WARN_BEFORE) * 1000);
        // Set hard logout timer
        idleTimer = setTimeout(function(){ window.location.href = LOGOUT_URL + '?timeout=1'; }, TIMEOUT_SEC * 1000);
    }

    function showWarning() {
        if (!modal) {
            const el = document.getElementById('sessionTimeoutModal');
            if (el) modal = new bootstrap.Modal(el);
        }
        if (!modal) return;

        let remaining = WARN_BEFORE;
        document.getElementById('timeoutCountdown').textContent = remaining;
        modal.show();

        countdownInterval = setInterval(function(){
            remaining--;
            const el = document.getElementById('timeoutCountdown');
            if (el) el.textContent = remaining;
            if (remaining <= 0) {
                clearInterval(countdownInterval);
                window.location.href = LOGOUT_URL + '?timeout=1';
            }
        }, 1000);
    }

    // Extend session: ping server + reset timers
    document.getElementById('extendSessionBtn')?.addEventListener('click', function(){
        fetch(BASE_URL + 'api/ping.php', {method:'GET', credentials:'same-origin'}).catch(function(){});
        resetTimers();
    });

    // Reset on user activity
    ['mousemove','keydown','click','scroll','touchstart'].forEach(function(evt){
        document.addEventListener(evt, function(){
            resetTimers();
        }, {passive:true});
    });

    // Initial start
    resetTimers();
})();
</script>
<?php endif; ?>

</body>
</html>
