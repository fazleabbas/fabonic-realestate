<?php
/**
 * FIS Real Estate — Ledger sub-navigation
 *
 * The ledger has eleven screens. They were reachable only as a row of buttons
 * crammed into the Chart of Accounts header, which meant Statements and
 * Payables were effectively hidden from anyone who had navigated anywhere else.
 *
 * This strip appears on every ledger page, so wherever you are you can see the
 * whole module and which part of it you are looking at.
 */

/**
 * @param string $current  key of the active page
 */
function ledgerNav($current = '') {
    $items = [
        'accounts' => [
            'url' => 'modules/ledger/', 'icon' => 'bi-journal-bookmark',
            'label' => __('led_accounts', 'Accounts'), 'perm' => 'ledger:view',
        ],
        'charges' => [
            'url' => 'modules/ledger/charges.php', 'icon' => 'bi-receipt-cutoff',
            'label' => __('chg_charges', 'Charges'), 'perm' => 'ledger:view',
        ],
        'floats' => [
            'url' => 'modules/ledger/floats.php', 'icon' => 'bi-wallet2',
            'label' => __('flt_floats', 'Floats'), 'perm' => 'ledger:view',
        ],
        'payables' => [
            'url' => 'modules/ledger/payables.php', 'icon' => 'bi-cash-coin',
            'label' => __('pay_payables', 'Payables'), 'perm' => 'ledger:view',
            'badge' => true,
        ],
        'journal' => [
            'url' => 'modules/ledger/journal.php', 'icon' => 'bi-list-columns',
            'label' => __('led_journal', 'Journal'), 'perm' => 'ledger:view',
        ],
        'statements' => [
            'url' => 'modules/ledger/statements.php', 'icon' => 'bi-file-earmark-bar-graph',
            'label' => __('rep_statements', 'Statements'), 'perm' => 'ledger:view',
        ],
        'trial' => [
            'url' => 'modules/ledger/trial-balance.php', 'icon' => 'bi-clipboard-data',
            'label' => __('led_trial_balance', 'Trial Balance'), 'perm' => 'ledger:view',
        ],
        'mappings' => [
            'url' => 'modules/ledger/mappings.php', 'icon' => 'bi-diagram-3',
            'label' => __('led_mappings', 'Account Mapping'), 'perm' => 'ledger:edit',
        ],
    ];

    // How many things are waiting for this person, for the Payables badge.
    $pending = 0;
    if (function_exists('depositPayoutsPending')) {
        $pending = count(depositPayoutsPending());
    }
    ?>
    <div class="ledger-nav mb-3">
        <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="<?php _e('led_ledger', 'Ledger'); ?>">
            <?php foreach ($items as $key => $item): ?>
            <?php
                [$mod, $act] = array_pad(explode(':', $item['perm'], 2), 2, 'view');
                if (!hasPermission($mod, $act)) continue;
                $active = ($key === $current);
            ?>
            <a href="<?php echo BASE_URL . $item['url']; ?>"
               class="btn <?php echo $active ? 'btn-primary' : 'btn-outline-secondary'; ?>"
               <?php echo $active ? 'aria-current="page"' : ''; ?>>
                <i class="bi <?php echo $item['icon']; ?> me-1"></i><?php echo htmlspecialchars($item['label']); ?>
                <?php if (!empty($item['badge']) && $pending > 0): ?>
                <span class="badge bg-warning text-dark ms-1"><?php echo $pending; ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <style>
    /* Wrap rather than overflow on a phone, and keep rounded ends when wrapped. */
    .ledger-nav .btn-group > .btn { border-radius: 4px !important; margin: 0 .25rem .25rem 0; }
    @media print { .ledger-nav { display: none !important; } }
    </style>
    <?php
}
