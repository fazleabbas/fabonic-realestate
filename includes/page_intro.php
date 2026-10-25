<?php
/**
 * FIS Real Estate — Page introduction blocks
 *
 * A short, dismissible panel at the top of each page answering three questions:
 * what this page is for, how to use it, and where to go next. The "next" links
 * are what turn a set of screens into a workflow — a new user can follow them
 * from an empty system to a working one without being told what order to use
 * things in.
 *
 * Dismissal is per user per page, kept in the browser, so the panel stops
 * nagging someone who already knows the screen but is one click away when they
 * come back to something they use rarely.
 *
 * Content lives in includes/page_intro_content.php so the two can be edited
 * independently — the wording changes far more often than this file.
 */

require_once __DIR__ . '/page_intro_content.php';

/**
 * Render the introduction for a page.
 *
 * @param string $key  registry key, e.g. 'leases.index'
 */
function pageIntro($key) {
    $intros = pageIntroContent();
    if (!isset($intros[$key])) return;

    $intro = $intros[$key];
    $what  = __('intro_' . str_replace('.', '_', $key) . '_what', $intro['what'] ?? '');
    $how   = __('intro_' . str_replace('.', '_', $key) . '_how',  $intro['how']  ?? '');
    if ($what === '' && $how === '') return;

    // Only offer a next step the user can actually open.
    $next = [];
    foreach (($intro['next'] ?? []) as $step) {
        if (!empty($step['permission'])) {
            [$mod, $act] = array_pad(explode(':', $step['permission'], 2), 2, 'view');
            if (!hasPermission($mod, $act)) continue;
        }
        $next[] = $step;
    }

    $domId = 'intro-' . preg_replace('/[^a-z0-9]+/i', '-', $key);
    ?>
    <div class="page-intro card border-0 mb-3" id="<?php echo $domId; ?>" data-intro-key="<?php echo htmlspecialchars($key); ?>" hidden>
        <div class="card-body py-3">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div class="flex-grow-1">
                    <?php if ($what !== ''): ?>
                    <p class="mb-2"><i class="bi bi-info-circle me-2 text-primary"></i><?php echo htmlspecialchars($what); ?></p>
                    <?php endif; ?>
                    <?php if ($how !== ''): ?>
                    <p class="mb-<?php echo $next ? '2' : '0'; ?> text-muted small"><?php echo htmlspecialchars($how); ?></p>
                    <?php endif; ?>
                    <?php if ($next): ?>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="small text-muted"><?php _e('intro_next', 'Next:'); ?></span>
                        <?php foreach ($next as $step): ?>
                        <a href="<?php echo htmlspecialchars(BASE_URL . ltrim($step['url'], '/')); ?>" class="btn btn-sm btn-outline-primary">
                            <?php echo htmlspecialchars(__($step['label_key'] ?? '', $step['label'])); ?>
                            <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn-close flex-shrink-0" aria-label="<?php _e('intro_hide', 'Hide this'); ?>"
                        onclick="pageIntroDismiss('<?php echo htmlspecialchars($key); ?>')"></button>
            </div>
        </div>
    </div>
    <?php
    pageIntroAssets();
}

/**
 * The CSS and JS, emitted once per request no matter how many intros render.
 */
function pageIntroAssets() {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <style>
    .page-intro {
        background: #f1f6fb;
        border-left: 3px solid #0d6efd !important;
        border-radius: 4px;
    }
    .page-intro p { max-width: 78ch; }
    .page-intro-toggle { cursor: pointer; }
    @media print { .page-intro, .page-intro-toggle { display: none !important; } }
    </style>
    <script>
    (function () {
        // Dismissal is per user, so two people sharing a browser profile do not
        // inherit each other's hidden panels.
        var who = document.body.getAttribute('data-user-id') || 'anon';

        window.pageIntroKey = function (key) { return 'introHidden:' + who + ':' + key; };

        window.pageIntroDismiss = function (key) {
            try { localStorage.setItem(window.pageIntroKey(key), '1'); } catch (e) {}
            var el = document.querySelector('[data-intro-key="' + key + '"]');
            if (el) el.hidden = true;
            var t = document.querySelector('[data-intro-toggle="' + key + '"]');
            if (t) t.hidden = false;
        };

        window.pageIntroShow = function (key) {
            try { localStorage.removeItem(window.pageIntroKey(key)); } catch (e) {}
            var el = document.querySelector('[data-intro-key="' + key + '"]');
            if (el) el.hidden = false;
            var t = document.querySelector('[data-intro-toggle="' + key + '"]');
            if (t) t.hidden = true;
        };

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-intro-key]').forEach(function (el) {
                var key = el.getAttribute('data-intro-key');
                var hidden = false;
                // A browser with storage blocked should still show the panel
                // rather than fail closed and hide the guidance entirely.
                try { hidden = localStorage.getItem(window.pageIntroKey(key)) === '1'; } catch (e) {}
                el.hidden = hidden;
                var t = document.querySelector('[data-intro-toggle="' + key + '"]');
                if (t) t.hidden = !hidden;
            });
        });
    })();
    </script>
    <?php
}

/**
 * A small "show the guide again" link, for placing in a page header.
 * Hidden while the panel itself is visible.
 */
function pageIntroToggle($key) {
    if (!isset(pageIntroContent()[$key])) return;
    ?>
    <a href="javascript:void(0)" class="page-intro-toggle small text-muted text-decoration-none"
       data-intro-toggle="<?php echo htmlspecialchars($key); ?>"
       onclick="pageIntroShow('<?php echo htmlspecialchars($key); ?>')" hidden>
        <i class="bi bi-question-circle me-1"></i><?php _e('intro_show', 'What is this page?'); ?>
    </a>
    <?php
}
