<?php
/**
 * FIS Real Estate — "where did the money go" picker (Part 38)
 *
 * Shared by the payment and deposit forms, because the rule is the same in both
 * and duplicating it would let them drift.
 *
 * The list follows the payment method: a cash receipt can only go into
 * something that holds cash — a personal float or a shared cash box — and a
 * transfer can only go into a bank account. Posting a cash receipt straight to
 * the bank would assert the money reached the bank when nobody has banked it.
 *
 * Leaving it on "Decide automatically" reproduces the pre-part-38 behaviour
 * exactly: the collector's own float for cash, the default bank otherwise. That
 * is the default selection, so an existing user who ignores this field sees no
 * change at all.
 */

require_once __DIR__ . '/charges.php';

/**
 * @param int|null $selected     currently chosen accounts.id
 * @param string   $methodId     DOM id of the payment-method select
 * @param string   $currencyId   DOM id of the currency select (optional)
 */
function receiptAccountPicker($selected = null, $methodId = 'paymentMethod', $currencyId = 'currencySelect') {
    $cash = cashHoldingAccounts();                              // floats + shared boxes, all currencies
    $bank = ledgerAccounts(['type' => 'asset', 'subtype' => 'bank']);
    if (!$cash && !$bank) return;                               // nothing to choose from yet

    $options = [];
    foreach ($cash as $a) {
        $label = $a['system_key'] === 'float' && !empty($a['owner_name'])
            ? $a['name'] . ' — ' . $a['owner_name']
            : $a['name'];
        $options[] = ['id' => (int) $a['id'], 'kind' => 'cash', 'currency' => $a['currency'], 'label' => $label];
    }
    foreach ($bank as $a) {
        $options[] = ['id' => (int) $a['id'], 'kind' => 'bank', 'currency' => $a['currency'], 'label' => $a['name']];
    }
    ?>
    <div class="col-12 col-md-4">
        <label class="form-label fw-semibold" for="ledgerAccountId">
            <?php _e('rcp_account', 'Received into'); ?>
        </label>
        <select name="ledger_account_id" id="ledgerAccountId" class="form-select">
            <option value=""><?php _e('rcp_auto', 'Decide automatically'); ?></option>
            <?php foreach ($options as $o): ?>
            <option value="<?php echo $o['id']; ?>"
                    data-kind="<?php echo $o['kind']; ?>"
                    data-currency="<?php echo htmlspecialchars($o['currency']); ?>"
                    <?php echo (int) $selected === $o['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($o['label']); ?> (<?php echo htmlspecialchars($o['currency']); ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted" id="ledgerAccountHelp"></small>
    </div>
    <script>
    (function () {
        var sel      = document.getElementById('ledgerAccountId');
        var method   = document.getElementById(<?php echo json_encode($methodId); ?>);
        var currency = document.getElementById(<?php echo json_encode($currencyId); ?>);
        var help     = document.getElementById('ledgerAccountHelp');
        if (!sel || !method) return;

        // Which methods put cash in someone's hands. Read from the tenant
        // setting so the form and the posting code cannot disagree.
        var cashMethods = <?php echo json_encode(array_values(floatPaymentMethods())); ?>;
        var text = {
            cash: <?php echo json_encode(__('rcp_help_cash', 'Cash boxes and floats only — this money has not reached the bank yet.')); ?>,
            bank: <?php echo json_encode(__('rcp_help_bank', 'Bank accounts only.')); ?>,
            none: <?php echo json_encode(__('rcp_help_none', 'No matching account in this currency. It will be decided automatically.')); ?>
        };

        function refresh() {
            var wantCash = cashMethods.indexOf(method.value) !== -1;
            var cur      = currency ? currency.value : null;
            var shown    = 0;

            Array.prototype.forEach.call(sel.options, function (opt) {
                if (!opt.value) return;                       // keep "automatic"
                var ok = opt.dataset.kind === (wantCash ? 'cash' : 'bank')
                      && (!cur || opt.dataset.currency === cur);
                opt.hidden   = !ok;
                opt.disabled = !ok;
                if (ok) shown++;
            });

            // A hidden option must never stay selected, or the form would post
            // an account the posting code will refuse.
            if (sel.selectedIndex > -1 && sel.options[sel.selectedIndex].disabled) sel.value = '';
            help.textContent = shown === 0 ? text.none : (wantCash ? text.cash : text.bank);
        }

        method.addEventListener('change', refresh);
        if (currency) currency.addEventListener('change', refresh);
        refresh();
    })();
    </script>
    <?php
}
