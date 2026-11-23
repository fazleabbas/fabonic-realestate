/**
 * FIS Real Estate — Main JavaScript
 */
document.addEventListener('DOMContentLoaded', function() {

    // ============================================================
    // Sidebar Toggle (mobile)
    // ============================================================
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('show');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    }

    // ============================================================
    // Sidebar Submenu Toggle
    // ============================================================
    document.querySelectorAll('[data-submenu]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.getElementById(btn.dataset.submenu);
            const arrow = btn.querySelector('.re-nav-arrow');
            if (target) {
                target.classList.toggle('show');
                if (arrow) arrow.classList.toggle('open');
            }
        });
    });

    // ============================================================
    // Auto-dismiss alerts
    // ============================================================
    setTimeout(function() {
        document.querySelectorAll('.alert-dismissible').forEach(function(el) {
            if (!el.closest('.re-sidebar') && !el.classList.contains('alert-permanent')) {
                el.classList.remove('show');
                setTimeout(function() { el.remove(); }, 150);
            }
        });
    }, 5000);

    // ============================================================
    // Initialize Select2 on .select2 elements
    // ============================================================
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({
            theme: 'default',
            width: '100%'
        });
    }

    // ============================================================
    // Confirm delete
    // ============================================================
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(el.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    // ============================================================
    // Format number utility
    // ============================================================
    window.formatNumber = function(num, decimals) {
        decimals = decimals !== undefined ? decimals : 0;
        return parseFloat(num).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    };

});
