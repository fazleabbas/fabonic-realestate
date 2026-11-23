<?php
/**
 * FIS Real Estate — opening data import engine (part 40).
 *
 * One flow for every type: read the file, validate every row against the
 * database (and against the other rows), show the preview, then commit all of
 * it in ONE transaction or none of it. Each commit is a batch that remembers
 * what it created, so it can be undone while nothing depends on it.
 *
 * Opening entries are dated the day before go-live and post against Opening
 * Balance Equity, never income: that income was earned in the old books.
 * Each record gets the same kind of entry the app itself would write
 * (source_type charge / deposit / payment), so reversing or refunding one later
 * works exactly as it does for anything else.
 *
 * Spec: https://claude.ai/artifact/DmM1cWan5mUakDuHy3dGKx
 */

require_once __DIR__ . '/reader.php';
require_once __DIR__ . '/../charges.php';

// ============================================================
// Setup state
// ============================================================

/** Imports are an admin job: they write money into every report at once. */
function importCanUse() {
    return getUserData('role_name') === 'Admin';
}

function importGoLiveDate() {
    $d = trim((string) getSetting('go_live_date', ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
}

/** Every opening figure is dated here: the last day of the old books. */
function importOpeningDate() {
    $g = importGoLiveDate();
    return $g ? date('Y-m-d', strtotime($g . ' -1 day')) : null;
}

function importCommittedCount($type = null) {
    global $conn;
    $sql = "SELECT COUNT(*) FROM import_batches WHERE status = 'committed'";
    $params = [];
    if ($type) { $sql .= ' AND type = ?'; $params[] = $type; }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** 'setting_up' | 'finished' | 'reopened' */
function importSetupState() {
    if (trim((string) getSetting('setup_finished_at', '')) === '') return 'setting_up';
    $opening = importOpeningDate();
    return ($opening && ledgerDateLocked($opening)) ? 'finished' : 'reopened';
}

/** Types whose commit posts to the ledger can't run once the opening date is closed. */
function importBlockedByLock() {
    $opening = importOpeningDate();
    return $opening && ledgerDateLocked($opening);
}

// ============================================================
// Value parsing
// ============================================================

function importNorm($s) {
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)));
}

/** Header cell -> key: "Monthly Rent *" -> "monthly_rent". */
function importHeaderKey($h) {
    $h = mb_strtolower(trim((string) $h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim($h, '_');
}

/**
 * Returns 'Y-m-d', null for blank, or false when it can't be read.
 * Day-first, as used in Tanzania; an Excel day count is accepted too.
 */
function importParseDate($v, $date1904 = false) {
    $v = trim((string) $v);
    if ($v === '') return null;

    if (preg_match('/^\d+(\.\d+)?$/', $v)) {
        $n = (int) floor((float) $v);
        if ($n < 1000 || $n > 100000) return false;
        $base = $date1904 ? '1904-01-01' : '1899-12-30';
        return date('Y-m-d', strtotime($base . ' +' . $n . ' days'));
    }
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : false;
    }
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', $v, $m)) {
        $y = (int) $m[3];
        if ($y < 100) $y += 2000;
        return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : false;
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : false;
}

/** Returns a float, null for blank, or false when it isn't a number. */
function importParseAmount($v) {
    $v = trim((string) $v);
    if ($v === '' || $v === '-') return null;
    $v = preg_replace('/[^\d.,\-]/', '', $v);
    // "1500,50" from a semicolon-separated regional CSV is a decimal comma.
    if (preg_match('/^-?\d+,\d{1,2}$/', $v)) $v = str_replace(',', '.', $v);
    $v = str_replace(',', '', $v);
    return is_numeric($v) ? round((float) $v, 2) : false;
}

function importFormatDate($d) {
    return $d ? formatDate($d) : '';
}

function importMoney($amount, $currency) {
    return $currency . ' ' . number_format((float) $amount, 2);
}

// ============================================================
// Type definitions
// ============================================================

/**
 * Every import type, in the order the setup page walks through them.
 * 'posts' = writes ledger entries, so it is closed once setup is finished.
 */
function importTypes() {
    return [
        'rent_roll' => [
            'label' => __('imp_t_rent_roll', 'Rent roll'),
            'icon'  => 'bi-building',
            'posts' => true,
            'needs' => null,
            'what'  => __('imp_t_rent_roll_what', 'Creates the property, unit and tenant on each row when they do not exist yet, then an active lease, and the deposit held if you give one.'),
            'where' => __('imp_t_rent_roll_where', 'Properties, units (marked occupied), tenants, leases and deposits. Automatic rent billing for these leases starts on the go-live date.'),
            'books' => __('imp_t_rent_roll_books', 'Leases post nothing. A deposit held adds to Tenant Deposits Held, balanced against Opening Balance Equity, dated the day before go-live.'),
            'not'   => __('imp_t_rent_roll_not', 'It does not record what tenants owe — use Opening arrears for that — and it sends no messages to tenants.'),
            'columns' => [
                ['key' => 'property_name', 'required' => true,  'aliases' => ['property', 'building'], 'help' => __('imp_c_property_name', 'Matched to an existing property by name, or created')],
                ['key' => 'property_type', 'required' => false, 'aliases' => [], 'help' => __('imp_c_property_type', 'residential, commercial, land or mixed. Default residential')],
                ['key' => 'unit_number',   'required' => true,  'aliases' => ['unit', 'unit_no', 'shop_no', 'shop', 'house_no'], 'help' => __('imp_c_unit_number', 'Matched within the property, or created')],
                ['key' => 'unit_type',     'required' => false, 'aliases' => [], 'help' => __('imp_c_unit_type', 'apartment, room, shop, office, warehouse, plot, house, flat, hostel or godown. Default apartment')],
                ['key' => 'tenant_name',   'required' => false, 'aliases' => ['tenant', 'name', 'full_name'], 'help' => __('imp_c_tenant_name', 'Full name, split at the first space. Or use first_name and last_name')],
                ['key' => 'first_name',    'required' => false, 'template' => false, 'aliases' => [], 'help' => __('imp_c_first_name', 'Optional instead of tenant_name')],
                ['key' => 'last_name',     'required' => false, 'template' => false, 'aliases' => ['surname'], 'help' => __('imp_c_last_name', 'Optional instead of tenant_name')],
                ['key' => 'phone',         'required' => false, 'aliases' => ['phone_number', 'mobile'], 'help' => ''],
                ['key' => 'email',         'required' => false, 'aliases' => [], 'help' => ''],
                ['key' => 'tin',           'required' => false, 'aliases' => [], 'help' => ''],
                ['key' => 'id_number',     'required' => false, 'aliases' => ['national_id', 'nida'], 'help' => ''],
                ['key' => 'lease_start',   'required' => true,  'aliases' => ['start_date', 'start'], 'help' => __('imp_c_date', 'dd/mm/yyyy, yyyy-mm-dd or an Excel date')],
                ['key' => 'lease_end',     'required' => true,  'aliases' => ['end_date', 'end'], 'help' => __('imp_c_date', 'dd/mm/yyyy, yyyy-mm-dd or an Excel date')],
                ['key' => 'monthly_rent',  'required' => true,  'aliases' => ['rent', 'monthly_rent_amount'], 'help' => __('imp_c_monthly_rent', 'Rent for ONE month. The system multiplies it by the months in each payment period')],
                ['key' => 'payment_frequency', 'required' => false, 'aliases' => ['frequency'], 'help' => __('imp_c_frequency', 'monthly, quarterly, half_yearly or yearly. Default monthly')],
                ['key' => 'due_day',       'required' => false, 'aliases' => ['payment_due_day'], 'help' => __('imp_c_due_day', '1 to 28. Default 1')],
                ['key' => 'currency',      'required' => false, 'aliases' => [], 'help' => __('imp_c_currency', 'Default: your default currency')],
                ['key' => 'deposit_held',  'required' => false, 'aliases' => ['deposit'], 'help' => __('imp_c_deposit_held', 'Deposit you hold for this lease today, if any')],
                ['key' => 'deposit_paid_date', 'required' => false, 'aliases' => [], 'help' => __('imp_c_deposit_paid_date', 'Default: the day before go-live')],
            ],
        ],
        'arrears' => [
            'label' => __('imp_t_arrears', 'Opening arrears'),
            'icon'  => 'bi-exclamation-diamond',
            'posts' => true,
            'needs' => 'lease',
            'what'  => __('imp_t_arrears_what', 'Creates one unpaid charge for each row, for the lease and period you give.'),
            'where' => __('imp_t_arrears_where', 'Tenant statements, arrears on the dashboard, aged debtors, reminders and late fees.'),
            'books' => __('imp_t_arrears_books', 'Each row adds to Tenant Receivables, balanced against Opening Balance Equity, dated the day before go-live.'),
            'not'   => __('imp_t_arrears_not', 'It does not record a payment and does not send messages to tenants. Rent from go-live onward is billed automatically, so do not include it.'),
            'columns' => [
                ['key' => 'property_name', 'required' => true,  'aliases' => ['property', 'building'], 'help' => __('imp_c_lease_match', 'With unit_number, must match an active lease')],
                ['key' => 'unit_number',   'required' => true,  'aliases' => ['unit', 'unit_no', 'shop_no', 'shop', 'house_no'], 'help' => ''],
                ['key' => 'tenant_name',   'required' => false, 'aliases' => ['tenant', 'name'], 'help' => __('imp_c_tenant_check', 'Optional check: the row is rejected if it is not the lease\'s tenant')],
                ['key' => 'charge_type',   'required' => false, 'aliases' => ['type'], 'help' => __('imp_c_charge_type', 'rent, utility or other. Default rent')],
                ['key' => 'period_from',   'required' => true,  'aliases' => ['from'], 'help' => __('imp_c_period_from', 'Must be before go-live')],
                ['key' => 'period_to',     'required' => true,  'aliases' => ['to'], 'help' => ''],
                ['key' => 'due_date',      'required' => false, 'aliases' => [], 'help' => __('imp_c_due_date', 'Default: period_from')],
                ['key' => 'amount_owed',   'required' => true,  'aliases' => ['amount', 'outstanding', 'balance'], 'help' => __('imp_c_amount_owed', 'What is STILL UNPAID for that period, after any part payment')],
                ['key' => 'currency',      'required' => false, 'aliases' => [], 'help' => __('imp_c_currency_lease', 'Must be the lease currency')],
                ['key' => 'note',          'required' => false, 'aliases' => ['description', 'notes'], 'help' => ''],
            ],
        ],
        'deposits' => [
            'label' => __('imp_t_deposits', 'Deposits held'),
            'icon'  => 'bi-safe',
            'posts' => true,
            'needs' => 'lease',
            'what'  => __('imp_t_deposits_what', 'Records the deposit you hold today for each lease that already exists.'),
            'where' => __('imp_t_deposits_where', 'Deposits list, lease and tenant pages, and move-out refunds.'),
            'books' => __('imp_t_deposits_books', 'Adds to Tenant Deposits Held, balanced against Opening Balance Equity, dated the day before go-live. The cash itself belongs in your opening bank balance.'),
            'not'   => __('imp_t_deposits_not', 'It does not move any money into a bank or cash account, and it sends no receipts.'),
            'columns' => [
                ['key' => 'property_name', 'required' => true,  'aliases' => ['property', 'building'], 'help' => __('imp_c_lease_match', 'With unit_number, must match an active lease')],
                ['key' => 'unit_number',   'required' => true,  'aliases' => ['unit', 'unit_no', 'shop_no', 'shop', 'house_no'], 'help' => ''],
                ['key' => 'amount',        'required' => true,  'aliases' => ['deposit', 'deposit_held'], 'help' => ''],
                ['key' => 'currency',      'required' => false, 'aliases' => [], 'help' => __('imp_c_currency_dep', 'Default: the lease deposit currency')],
                ['key' => 'paid_date',     'required' => false, 'aliases' => [], 'help' => __('imp_c_deposit_paid_date', 'Default: the day before go-live')],
                ['key' => 'reference',     'required' => false, 'aliases' => ['reference_number'], 'help' => ''],
            ],
        ],
        'advances' => [
            'label' => __('imp_t_advances', 'Tenant advances'),
            'icon'  => 'bi-piggy-bank',
            'posts' => true,
            'needs' => 'lease',
            'what'  => __('imp_t_advances_what', 'Records rent a tenant has already paid ahead, as a credit on their lease.'),
            'where' => __('imp_t_advances_where', 'The credit settles the tenant\'s next charges automatically as they are raised. It appears as a payment dated the day before go-live, reference OPENING-ADVANCE.'),
            'books' => __('imp_t_advances_books', 'Reduces Tenant Receivables, balanced against Opening Balance Equity, dated the day before go-live.'),
            'not'   => __('imp_t_advances_not', 'It is not new money received and creates no receipt. The cash itself belongs in your opening bank balance.'),
            'columns' => [
                ['key' => 'property_name', 'required' => true,  'aliases' => ['property', 'building'], 'help' => __('imp_c_lease_match', 'With unit_number, must match an active lease')],
                ['key' => 'unit_number',   'required' => true,  'aliases' => ['unit', 'unit_no', 'shop_no', 'shop', 'house_no'], 'help' => ''],
                ['key' => 'amount',        'required' => true,  'aliases' => ['advance', 'credit'], 'help' => ''],
                ['key' => 'currency',      'required' => false, 'aliases' => [], 'help' => __('imp_c_currency_lease', 'Must be the lease currency')],
                ['key' => 'note',          'required' => false, 'aliases' => ['notes', 'description'], 'help' => ''],
            ],
        ],
        'trial_balance' => [
            'label' => __('imp_t_trial_balance', 'Opening trial balance'),
            'icon'  => 'bi-journal-check',
            'posts' => true,
            'needs' => 'accounts',
            'what'  => __('imp_t_tb_what', 'Posts the balance of each account in your old books as at the day before go-live, in one opening entry per currency.'),
            'where' => __('imp_t_tb_where', 'Trial balance, balance sheet and every account statement.'),
            'books' => __('imp_t_tb_books', 'Debits and credits the accounts you list. The difference goes to Opening Balance Equity. The entry waits for a second approver before it counts.'),
            'not'   => __('imp_t_tb_not', 'Tenant Receivables, Deposits Held and Accounts Payable are refused here: their balances come from the arrears, deposit and advance imports, so they are never counted twice.'),
            'columns' => [
                ['key' => 'account_code', 'required' => true,  'aliases' => ['code', 'account'], 'help' => __('imp_c_account_code', 'Must exist in your Chart of Accounts')],
                ['key' => 'account_name', 'required' => false, 'aliases' => ['name'], 'help' => __('imp_c_account_name', 'Optional check against the code')],
                ['key' => 'currency',     'required' => false, 'aliases' => [], 'help' => __('imp_c_currency_acct', 'Default: the account currency; must match it')],
                ['key' => 'debit',        'required' => false, 'aliases' => ['dr'], 'help' => __('imp_c_debit', 'Fill debit OR credit, not both')],
                ['key' => 'credit',       'required' => false, 'aliases' => ['cr'], 'help' => ''],
            ],
        ],
    ];
}

function importType($type) {
    $types = importTypes();
    return $types[$type] ?? null;
}

/** Map file headers onto a type's column keys. Returns [key => column index]. */
function importMapHeaders($type, $headers) {
    $def = importType($type);
    $map = [];
    foreach ($headers as $i => $h) {
        $k = importHeaderKey($h);
        foreach ($def['columns'] as $col) {
            if (isset($map[$col['key']])) continue;
            if ($k === $col['key'] || in_array($k, $col['aliases'], true)) { $map[$col['key']] = $i; break; }
        }
    }
    return $map;
}

// ============================================================
// Lookups shared by the validators
// ============================================================

function importActiveLeaseIndex() {
    global $conn;
    $rows = $conn->query("
        SELECT l.id, l.tenant_id, l.unit_id, l.currency, l.deposit_currency, l.billed_through,
               u.unit_number, u.property_id, p.name AS property_name,
               TRIM(CONCAT(t.first_name, ' ', COALESCE(t.last_name, ''))) AS tenant_name
        FROM leases l
        INNER JOIN units u ON u.id = l.unit_id
        INNER JOIN properties p ON p.id = u.property_id
        INNER JOIN tenants t ON t.id = l.tenant_id
        WHERE l.status = 'active'
    ")->fetchAll();
    $idx = [];
    foreach ($rows as $r) $idx[importNorm($r['property_name']) . '|' . importNorm($r['unit_number'])] = $r;
    return $idx;
}

/** Shared front half of the arrears / deposits / advances rows. */
function importFindLease($cells, $leases, &$msgs) {
    $key = importNorm($cells['property_name']) . '|' . importNorm($cells['unit_number']);
    if ($cells['property_name'] === '' || $cells['unit_number'] === '') return null;
    if (!isset($leases[$key])) {
        $msgs[] = ['error', sprintf(__('imp_e_no_lease', 'No active lease for unit %s at %s'), $cells['unit_number'], $cells['property_name'])];
        return null;
    }
    return $leases[$key];
}

function importSplitName($full) {
    $full = trim(preg_replace('/\s+/u', ' ', (string) $full));
    $pos = mb_strpos($full, ' ');
    if ($pos === false) return [$full, ''];
    return [mb_substr($full, 0, $pos), mb_substr($full, $pos + 1)];
}

function importFrequency($v) {
    $k = importHeaderKey($v);
    $map = [
        '' => 'monthly', 'monthly' => 'monthly', 'month' => 'monthly',
        'quarterly' => 'quarterly', 'quarter' => 'quarterly',
        'half_yearly' => 'half_yearly', 'half_year' => 'half_yearly', 'semi_annual' => 'half_yearly', 'semi_annually' => 'half_yearly', 'biannual' => 'half_yearly',
        'yearly' => 'yearly', 'annual' => 'yearly', 'annually' => 'yearly', 'year' => 'yearly',
    ];
    return $map[$k] ?? false;
}

/** The billing period that starts before go-live but ends on/after it, if any. */
function importStraddlingPeriod($start, $end, $frequency, $goLive) {
    $months = paymentFrequencyMonths($frequency);
    $p = new DateTime($start);
    $leaseEnd = new DateTime($end);
    $g = new DateTime($goLive);
    $guard = 0;
    while ($p < $g && $p <= $leaseEnd && $guard++ < 500) {
        $pEnd = (clone $p)->modify('+' . $months . ' months')->modify('-1 day');
        if ($pEnd > $leaseEnd) $pEnd = clone $leaseEnd;
        if ($pEnd >= $g) return [$p->format('Y-m-d'), $pEnd->format('Y-m-d')];
        $p->modify('+' . $months . ' months');
    }
    return null;
}

// ============================================================
// Validation
// ============================================================

/**
 * Validate a parsed file. Never writes.
 *
 * @return array ['ok'=>bool, 'error'=>?string, 'rows'=>[...], 'counts'=>[...], 'summary'=>[...],
 *                'missing'=>string[], 'unknown'=>string[]]
 *   each row: ['line', 'status' new|exists|update|error, 'msgs' [[level, text]], 'data' [...], 'show' [...]]
 */
function importValidate($type, $parsed, $options = []) {
    $def = importType($type);
    $goLive = importGoLiveDate();
    $result = ['ok' => true, 'error' => null, 'rows' => [], 'counts' => [], 'summary' => [], 'missing' => [], 'unknown' => []];

    if (!$def) return ['ok' => false, 'error' => 'Unknown import type'] + $result;
    if (!$goLive) return ['ok' => false, 'error' => __('imp_e_no_golive', 'Set the go-live date on the Data Import page first.')] + $result;

    $map = importMapHeaders($type, $parsed['headers']);
    foreach ($parsed['headers'] as $i => $h) {
        if (trim($h) !== '' && !in_array($i, $map, true)) $result['unknown'][] = $h;
    }
    foreach ($def['columns'] as $col) {
        if ($col['required'] && !isset($map[$col['key']])) $result['missing'][] = $col['key'];
    }
    if ($type === 'rent_roll' && !isset($map['tenant_name']) && !isset($map['first_name'])) $result['missing'][] = 'tenant_name';
    if ($result['missing']) {
        $result['ok'] = false;
        $result['error'] = sprintf(__('imp_e_missing_cols', 'Required column(s) missing: %s. Download the template to see the expected headers.'), implode(', ', $result['missing']));
        return $result;
    }
    if (!$parsed['rows']) {
        return ['ok' => false, 'error' => __('imp_e_no_rows', 'The file has a header row but no data rows.')] + $result;
    }

    $rows = [];
    foreach ($parsed['rows'] as $r) {
        $cells = [];
        foreach ($def['columns'] as $col) {
            $cells[$col['key']] = isset($map[$col['key']]) ? trim((string) ($r['cells'][$map[$col['key']]] ?? '')) : '';
        }
        $rows[] = ['line' => $r['line'], 'cells' => $cells];
    }

    $fn = 'importValidate_' . $type;
    $out = $fn($rows, $parsed['date1904'] ?? false, $goLive, $options);

    // Required cells, once for every type.
    foreach ($out as &$row) {
        foreach ($def['columns'] as $col) {
            if ($col['required'] && $row['cells'][$col['key']] === '') {
                array_unshift($row['msgs'], ['error', sprintf(__('imp_e_required', '%s is required'), $col['key'])]);
            }
        }
        $levels = array_column($row['msgs'], 0);
        if (in_array('error', $levels, true)) $row['status'] = 'error';
        $result['counts'][$row['status']] = ($result['counts'][$row['status']] ?? 0) + 1;
    }
    unset($row);

    $result['rows'] = $out;
    $result['summary'] = importSummary($type, $out);
    return $result;
}

function importValidate_rent_roll($rows, $date1904, $goLive, $options) {
    global $conn;
    $updateExisting = !empty($options['update_existing']);

    $props = [];
    foreach ($conn->query('SELECT id, name FROM properties')->fetchAll() as $p) $props[importNorm($p['name'])] = (int) $p['id'];
    $units = [];
    foreach ($conn->query('SELECT id, property_id, unit_number FROM units')->fetchAll() as $u) {
        $units[$u['property_id'] . '|' . importNorm($u['unit_number'])] = (int) $u['id'];
    }
    $busy = [];
    foreach ($conn->query("SELECT unit_id FROM leases WHERE status = 'active'")->fetchAll() as $l) $busy[(int) $l['unit_id']] = true;
    $tenants = [];
    foreach ($conn->query("SELECT id, first_name, last_name FROM tenants")->fetchAll() as $t) {
        $tenants[importNorm(trim($t['first_name'] . ' ' . $t['last_name']))] = (int) $t['id'];
    }

    $propTypes = ['residential', 'commercial', 'land', 'mixed'];
    $unitTypes = ['apartment', 'room', 'shop', 'office', 'warehouse', 'plot', 'house', 'flat', 'hostel', 'godown'];

    $seenUnits = [];
    $out = [];
    foreach ($rows as $r) {
        $c = $r['cells'];
        $msgs = [];
        $d = [];

        $d['property_name'] = preg_replace('/\s+/u', ' ', $c['property_name']);
        $d['property_id'] = $props[importNorm($c['property_name'])] ?? null;
        $d['property_type'] = $c['property_type'] === '' ? 'residential' : importHeaderKey($c['property_type']);
        if (!in_array($d['property_type'], $propTypes, true)) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_value', '%s "%s" is not allowed'), 'property_type', $c['property_type'])];
        }

        $d['unit_number'] = trim($c['unit_number']);
        $d['unit_type'] = $c['unit_type'] === '' ? 'apartment' : importHeaderKey($c['unit_type']);
        if (!in_array($d['unit_type'], $unitTypes, true)) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_value', '%s "%s" is not allowed'), 'unit_type', $c['unit_type'])];
        }
        $d['unit_id'] = $d['property_id'] ? ($units[$d['property_id'] . '|' . importNorm($d['unit_number'])] ?? null) : null;
        if ($d['unit_id'] && !empty($busy[$d['unit_id']])) {
            $msgs[] = ['error', sprintf(__('imp_e_unit_let', 'Unit %s already has an active lease'), $d['unit_number'])];
        }
        $unitKey = importNorm($d['property_name']) . '|' . importNorm($d['unit_number']);
        if ($d['unit_number'] !== '') {
            if (isset($seenUnits[$unitKey])) {
                $msgs[] = ['error', sprintf(__('imp_e_dup_unit', 'Unit %s appears more than once in this file (line %d)'), $d['unit_number'], $seenUnits[$unitKey])];
            } else {
                $seenUnits[$unitKey] = $r['line'];
            }
        }

        if ($c['first_name'] !== '' || $c['last_name'] !== '') {
            $d['first_name'] = $c['first_name'];
            $d['last_name'] = $c['last_name'];
        } else {
            [$d['first_name'], $d['last_name']] = importSplitName($c['tenant_name']);
        }
        if ($d['first_name'] === '') $msgs[] = ['error', sprintf(__('imp_e_required', '%s is required'), 'tenant_name')];
        $d['tenant_full'] = trim($d['first_name'] . ' ' . $d['last_name']);
        $d['tenant_id'] = $tenants[importNorm($d['tenant_full'])] ?? null;
        $d['update_existing'] = $updateExisting;
        foreach (['phone', 'email', 'tin', 'id_number'] as $k) $d[$k] = $c[$k];
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_value', '%s "%s" is not allowed'), 'email', $d['email'])];
        }

        $d['start'] = importParseDate($c['lease_start'], $date1904);
        $d['end']   = importParseDate($c['lease_end'], $date1904);
        if ($d['start'] === false) $msgs[] = ['error', sprintf(__('imp_e_bad_date', '%s "%s" is not a date'), 'lease_start', $c['lease_start'])];
        if ($d['end'] === false)   $msgs[] = ['error', sprintf(__('imp_e_bad_date', '%s "%s" is not a date'), 'lease_end', $c['lease_end'])];
        if ($d['start'] && $d['end'] && $d['end'] <= $d['start']) {
            $msgs[] = ['error', __('end_date_after_start', 'End date must be after start date')];
        }

        $d['monthly_rent'] = importParseAmount($c['monthly_rent']);
        if ($c['monthly_rent'] !== '' && ($d['monthly_rent'] === false || $d['monthly_rent'] <= 0)) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_amount', '%s "%s" must be a number above zero'), 'monthly_rent', $c['monthly_rent'])];
        }
        $d['frequency'] = importFrequency($c['payment_frequency']);
        if ($d['frequency'] === false) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_value', '%s "%s" is not allowed'), 'payment_frequency', $c['payment_frequency'])];
        }
        $d['due_day'] = $c['due_day'] === '' ? 1 : (int) $c['due_day'];
        if ($d['due_day'] < 1 || $d['due_day'] > 28) {
            $msgs[] = ['error', __('invalid_due_day', 'Payment due day must be between 1 and 28')];
        }
        $d['currency'] = $c['currency'] === '' ? DEFAULT_CURRENCY : strtoupper($c['currency']);
        if (!preg_match('/^[A-Z]{3}$/', $d['currency'])) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_value', '%s "%s" is not allowed'), 'currency', $c['currency'])];
        }

        $d['deposit'] = importParseAmount($c['deposit_held']);
        if ($d['deposit'] === false || ($d['deposit'] !== null && $d['deposit'] < 0)) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_amount', '%s "%s" must be a number above zero'), 'deposit_held', $c['deposit_held'])];
        }
        $d['deposit_date'] = importParseDate($c['deposit_paid_date'], $date1904);
        if ($d['deposit_date'] === false) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_date', '%s "%s" is not a date'), 'deposit_paid_date', $c['deposit_paid_date'])];
        }

        // Notes that are not errors.
        if ($d['end'] && $d['end'] < $goLive) {
            $msgs[] = ['warn', sprintf(__('imp_w_ended', 'Lease ended on %s, before go-live. It will be imported as active.'), importFormatDate($d['end']))];
        }
        if ($d['start'] && $d['end'] && $d['frequency'] && $d['end'] > $d['start']) {
            $straddle = importStraddlingPeriod($d['start'], $d['end'], $d['frequency'], $goLive);
            $d['straddle'] = (bool) $straddle;
            if ($straddle) {
                $msgs[] = ['info', sprintf(__('imp_i_straddle', 'Period %s to %s began before go-live and will not be billed automatically. Include it in opening arrears if it is unpaid.'),
                    importFormatDate($straddle[0]), importFormatDate($straddle[1]))];
            }
        }
        if ($d['property_id'] === null) $msgs[] = ['info', sprintf(__('imp_i_new_property', 'New property "%s"'), $d['property_name'])];
        if ($d['tenant_id'] && $updateExisting) {
            $msgs[] = ['info', __('imp_i_tenant_update', 'Tenant already on file: blank details will be filled and phone/email replaced')];
        } elseif ($d['tenant_id']) {
            $msgs[] = ['info', __('imp_i_tenant_exists', 'Tenant already on file: kept as is')];
        }

        $status = 'new';
        $out[] = [
            'line' => $r['line'], 'cells' => $c, 'status' => $status, 'msgs' => $msgs, 'data' => $d,
            'show' => [
                $d['property_name'], $d['unit_number'], $d['tenant_full'],
                importFormatDate($d['start'] ?: null) . ' – ' . importFormatDate($d['end'] ?: null),
                $d['monthly_rent'] ? importMoney($d['monthly_rent'], $d['currency']) : $c['monthly_rent'],
                $d['frequency'] ? paymentFrequencyLabel($d['frequency']) : $c['payment_frequency'],
                $d['deposit'] ? importMoney($d['deposit'], $d['currency']) : '',
            ],
        ];
    }
    return $out;
}

function importValidate_arrears($rows, $date1904, $goLive, $options) {
    global $conn;
    $leases = importActiveLeaseIndex();
    $existing = $conn->prepare("SELECT source_type FROM charges WHERE lease_id = ? AND type = ? AND period_from = ?
                                AND source_type IN ('opening_balance', 'rent_generator') AND status <> 'cancelled' LIMIT 1");
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $c = $r['cells'];
        $msgs = [];
        $d = [];
        $lease = importFindLease($c, $leases, $msgs);
        $d['lease'] = $lease;

        if ($lease && $c['tenant_name'] !== '' && importNorm($c['tenant_name']) !== importNorm($lease['tenant_name'])) {
            $msgs[] = ['error', sprintf(__('imp_e_tenant_mismatch', 'The lease on this unit belongs to %s, not %s'), $lease['tenant_name'], $c['tenant_name'])];
        }
        $d['type'] = $c['charge_type'] === '' ? 'rent' : importHeaderKey($c['charge_type']);
        if (!in_array($d['type'], ['rent', 'utility', 'other'], true)) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_value', '%s "%s" is not allowed'), 'charge_type', $c['charge_type'])];
        }
        $d['from'] = importParseDate($c['period_from'], $date1904);
        $d['to']   = importParseDate($c['period_to'], $date1904);
        $d['due']  = importParseDate($c['due_date'], $date1904);
        foreach (['from' => 'period_from', 'to' => 'period_to', 'due' => 'due_date'] as $k => $label) {
            if ($d[$k] === false) $msgs[] = ['error', sprintf(__('imp_e_bad_date', '%s "%s" is not a date'), $label, $c[$label])];
        }
        if ($d['from'] && $d['to'] && $d['to'] < $d['from']) {
            $msgs[] = ['error', __('imp_e_period_order', 'period_to is before period_from')];
        }
        if ($d['from'] && $d['from'] >= $goLive) {
            $msgs[] = ['error', sprintf(__('imp_e_after_golive', 'This period starts on or after go-live (%s). The system bills it itself.'), importFormatDate($goLive))];
        }
        if (!$d['due'] && $d['from']) $d['due'] = $d['from'];

        $d['amount'] = importParseAmount($c['amount_owed']);
        if ($c['amount_owed'] !== '' && ($d['amount'] === false || $d['amount'] <= 0)) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_amount', '%s "%s" must be a number above zero'), 'amount_owed', $c['amount_owed'])];
        }
        $d['currency'] = $lease ? $lease['currency'] : DEFAULT_CURRENCY;
        if ($lease && $c['currency'] !== '' && strtoupper($c['currency']) !== $lease['currency']) {
            $msgs[] = ['error', sprintf(__('imp_e_currency', 'Currency %s does not match the lease currency %s'), strtoupper($c['currency']), $lease['currency'])];
        }
        $d['note'] = $c['note'];

        if ($lease && $d['from'] && in_array($d['type'], ['rent', 'utility', 'other'], true)) {
            $existing->execute([$lease['id'], $d['type'], $d['from']]);
            $src = $existing->fetchColumn();
            if ($src === 'opening_balance') {
                $msgs[] = ['error', __('imp_e_dup_arrears', 'Opening arrears for this lease and period are already in the system')];
            } elseif ($src === 'rent_generator') {
                $msgs[] = ['error', __('imp_e_already_billed', 'The system has already billed this lease for this period')];
            }
            $k = $lease['id'] . '|' . $d['type'] . '|' . $d['from'];
            if (isset($seen[$k])) {
                $msgs[] = ['error', sprintf(__('imp_e_dup_row', 'Same lease and period as line %d'), $seen[$k])];
            } else {
                $seen[$k] = $r['line'];
            }
        }

        $out[] = [
            'line' => $r['line'], 'cells' => $c, 'status' => 'new', 'msgs' => $msgs, 'data' => $d,
            'show' => [
                $c['property_name'], $c['unit_number'], $lease['tenant_name'] ?? $c['tenant_name'], $d['type'],
                importFormatDate($d['from'] ?: null) . ' – ' . importFormatDate($d['to'] ?: null),
                $d['amount'] ? importMoney($d['amount'], $d['currency']) : $c['amount_owed'],
            ],
        ];
    }
    return $out;
}

function importValidate_deposits($rows, $date1904, $goLive, $options) {
    global $conn;
    $leases = importActiveLeaseIndex();
    $has = $conn->prepare("SELECT COUNT(*) FROM deposits WHERE lease_id = ? AND status IN ('held', 'partially_refunded')");
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $c = $r['cells'];
        $msgs = [];
        $d = [];
        $lease = importFindLease($c, $leases, $msgs);
        $d['lease'] = $lease;
        $d['amount'] = importParseAmount($c['amount']);
        if ($c['amount'] !== '' && ($d['amount'] === false || $d['amount'] <= 0)) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_amount', '%s "%s" must be a number above zero'), 'amount', $c['amount'])];
        }
        $d['currency'] = $c['currency'] !== '' ? strtoupper($c['currency']) : ($lease['deposit_currency'] ?? DEFAULT_CURRENCY);
        if (!preg_match('/^[A-Z]{3}$/', $d['currency'])) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_value', '%s "%s" is not allowed'), 'currency', $c['currency'])];
        }
        $d['paid_date'] = importParseDate($c['paid_date'], $date1904);
        if ($d['paid_date'] === false) $msgs[] = ['error', sprintf(__('imp_e_bad_date', '%s "%s" is not a date'), 'paid_date', $c['paid_date'])];
        $d['reference'] = $c['reference'];
        if ($lease) {
            $has->execute([$lease['id']]);
            if ((int) $has->fetchColumn()) $msgs[] = ['error', __('imp_e_dup_deposit', 'This lease already has a deposit recorded')];
            if (isset($seen[$lease['id']])) {
                $msgs[] = ['error', sprintf(__('imp_e_dup_lease', 'This lease is already on line %d of the file'), $seen[$lease['id']])];
            } else {
                $seen[$lease['id']] = $r['line'];
            }
        }
        $out[] = [
            'line' => $r['line'], 'cells' => $c, 'status' => 'new', 'msgs' => $msgs, 'data' => $d,
            'show' => [$c['property_name'], $c['unit_number'], $lease['tenant_name'] ?? '',
                       $d['amount'] ? importMoney($d['amount'], $d['currency']) : $c['amount']],
        ];
    }
    return $out;
}

function importValidate_advances($rows, $date1904, $goLive, $options) {
    global $conn;
    $leases = importActiveLeaseIndex();
    $has = $conn->prepare("SELECT COUNT(*) FROM payments WHERE lease_id = ? AND reference_number = 'OPENING-ADVANCE' AND status = 'recorded'");
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $c = $r['cells'];
        $msgs = [];
        $d = [];
        $lease = importFindLease($c, $leases, $msgs);
        $d['lease'] = $lease;
        $d['amount'] = importParseAmount($c['amount']);
        if ($c['amount'] !== '' && ($d['amount'] === false || $d['amount'] <= 0)) {
            $msgs[] = ['error', sprintf(__('imp_e_bad_amount', '%s "%s" must be a number above zero'), 'amount', $c['amount'])];
        }
        $d['currency'] = $lease ? $lease['currency'] : DEFAULT_CURRENCY;
        if ($lease && $c['currency'] !== '' && strtoupper($c['currency']) !== $lease['currency']) {
            $msgs[] = ['error', sprintf(__('imp_e_currency', 'Currency %s does not match the lease currency %s'), strtoupper($c['currency']), $lease['currency'])];
        }
        $d['note'] = $c['note'];
        if ($lease) {
            $has->execute([$lease['id']]);
            if ((int) $has->fetchColumn()) $msgs[] = ['error', __('imp_e_dup_advance', 'This lease already has an opening advance')];
            if (isset($seen[$lease['id']])) {
                $msgs[] = ['error', sprintf(__('imp_e_dup_lease', 'This lease is already on line %d of the file'), $seen[$lease['id']])];
            } else {
                $seen[$lease['id']] = $r['line'];
            }
        }
        $out[] = [
            'line' => $r['line'], 'cells' => $c, 'status' => 'new', 'msgs' => $msgs, 'data' => $d,
            'show' => [$c['property_name'], $c['unit_number'], $lease['tenant_name'] ?? '',
                       $d['amount'] ? importMoney($d['amount'], $d['currency']) : $c['amount']],
        ];
    }
    return $out;
}

function importValidate_trial_balance($rows, $date1904, $goLive, $options) {
    global $conn;
    $accounts = [];
    foreach ($conn->query("SELECT * FROM accounts WHERE status = 'active'")->fetchAll() as $a) {
        if ($a['code'] !== null && $a['code'] !== '') $accounts[importNorm($a['code'])] = $a;
    }
    // Their balances come from their own imports; listing them here would count them twice.
    $blocked = ['ar', 'deposits_held', 'ap', 'opening_equity'];
    $already = importCommittedCount('trial_balance') > 0;

    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $c = $r['cells'];
        $msgs = [];
        $d = [];
        $a = $accounts[importNorm($c['account_code'])] ?? null;
        $d['account'] = $a;
        if ($already) {
            $msgs[] = ['error', __('imp_e_tb_exists', 'An opening trial balance has already been imported. Undo it first to import another.')];
        }
        if ($c['account_code'] !== '' && !$a) {
            $msgs[] = ['error', sprintf(__('imp_e_no_account', 'No active account with code %s. Add it under Chart of Accounts first.'), $c['account_code'])];
        }
        if ($a) {
            if ($c['account_name'] !== '' && importNorm($c['account_name']) !== importNorm($a['name'])) {
                $msgs[] = ['error', sprintf(__('imp_e_account_name', 'Account %s is called "%s" in your chart, not "%s"'), $a['code'], $a['name'], $c['account_name'])];
            }
            if (in_array($a['system_key'], $blocked, true)) {
                $msgs[] = ['error', sprintf(__('imp_e_blocked_account', '%s is filled by its own import (arrears, deposits, advances, supplier bills), so it cannot be in the trial balance'), $a['name'])];
            }
            if ((int) $a['user_id'] > 0) {
                $msgs[] = ['error', sprintf(__('imp_e_float_account', '%s is a staff float. Float balances cannot be imported'), $a['name'])];
            }
            if ($c['currency'] !== '' && strtoupper($c['currency']) !== $a['currency']) {
                $msgs[] = ['error', sprintf(__('imp_e_currency_acct', 'Account %s is in %s, not %s'), $a['code'], $a['currency'], strtoupper($c['currency']))];
            }
            if (isset($seen[$a['id']])) {
                $msgs[] = ['error', sprintf(__('imp_e_dup_account', 'Account listed twice (line %d)'), $seen[$a['id']])];
            } else {
                $seen[$a['id']] = $r['line'];
            }
        }
        $d['debit'] = importParseAmount($c['debit']);
        $d['credit'] = importParseAmount($c['credit']);
        if ($d['debit'] === false || $d['credit'] === false || ($d['debit'] ?? 0) < 0 || ($d['credit'] ?? 0) < 0) {
            $msgs[] = ['error', __('imp_e_dr_cr_number', 'Debit and credit must be numbers')];
        } elseif (($d['debit'] ?? 0) > 0 && ($d['credit'] ?? 0) > 0) {
            $msgs[] = ['error', __('imp_e_dr_and_cr', 'Fill debit OR credit, not both')];
        } elseif (($d['debit'] ?? 0) <= 0 && ($d['credit'] ?? 0) <= 0) {
            $msgs[] = ['error', __('imp_e_dr_or_cr', 'Fill a debit or a credit amount')];
        }
        $d['currency'] = $a['currency'] ?? strtoupper($c['currency'] ?: DEFAULT_CURRENCY);
        $out[] = [
            'line' => $r['line'], 'cells' => $c, 'status' => 'new', 'msgs' => $msgs, 'data' => $d,
            'show' => [$c['account_code'], $a['name'] ?? $c['account_name'], $d['currency'],
                       $d['debit'] ? number_format($d['debit'], 2) : '', $d['credit'] ? number_format($d['credit'], 2) : ''],
        ];
    }
    return $out;
}

/** Column headings for each type's preview table (matches 'show'). */
function importPreviewColumns($type) {
    $map = [
        'rent_roll' => [__('property', 'Property'), __('unit', 'Unit'), __('tenant', 'Tenant'), __('imp_h_term', 'Lease term'),
                        __('imp_h_monthly', 'Monthly rent'), __('frequency', 'Frequency'), __('imp_h_deposit', 'Deposit held')],
        'arrears'   => [__('property', 'Property'), __('unit', 'Unit'), __('tenant', 'Tenant'), __('type', 'Type'),
                        __('imp_h_period', 'Period'), __('imp_h_owed', 'Still owed')],
        'deposits'  => [__('property', 'Property'), __('unit', 'Unit'), __('tenant', 'Tenant'), __('amount', 'Amount')],
        'advances'  => [__('property', 'Property'), __('unit', 'Unit'), __('tenant', 'Tenant'), __('amount', 'Amount')],
        'trial_balance' => [__('imp_h_code', 'Code'), __('imp_h_account', 'Account'), __('currency', 'Currency'),
                            __('led_debit', 'Debit'), __('led_credit', 'Credit')],
    ];
    return $map[$type] ?? [];
}

/**
 * Plain-language figures for the confirmation box: what the commit will create.
 * Returns a list of sentences plus totals by currency.
 */
function importSummary($type, $rows) {
    $ok = array_filter($rows, function ($r) { return $r['status'] !== 'error'; });
    $lines = [];
    $byCur = function ($field) use ($ok) {
        $t = [];
        foreach ($ok as $r) {
            $v = (float) ($r['data'][$field] ?? 0);
            if ($v > 0) $t[$r['data']['currency']] = ($t[$r['data']['currency']] ?? 0) + $v;
        }
        return $t;
    };
    $moneyList = function ($t) {
        return implode(' + ', array_map(function ($c, $v) { return importMoney($v, $c); }, array_keys($t), $t));
    };

    if ($type === 'rent_roll') {
        $props = []; $tenants = []; $newUnits = 0; $straddle = 0; $deps = 0;
        foreach ($ok as $r) {
            if (!$r['data']['property_id']) $props[importNorm($r['data']['property_name'])] = true;
            if (!$r['data']['tenant_id']) $tenants[importNorm($r['data']['tenant_full'])] = true;
            if (!$r['data']['unit_id']) $newUnits++;
            if ($r['data']['deposit'] > 0) $deps++;
            if (!empty($r['data']['straddle'])) $straddle++;
        }
        $lines[] = sprintf(__('imp_s_rent_roll', '%d lease(s), %d new propert(ies), %d new unit(s) and %d new tenant(s)'),
            count($ok), count($props), $newUnits, count($tenants));
        $depTotal = $byCur('deposit');
        if ($deps) $lines[] = sprintf(__('imp_s_deposits', '%d deposit(s) held totalling %s'), $deps, $moneyList($depTotal));
        if ($straddle) $lines[] = sprintf(__('imp_s_straddle', '%d lease(s) have a rent period that began before go-live and will not be billed automatically — put it in opening arrears if it is unpaid'), $straddle);
        return ['lines' => $lines, 'entries' => $deps, 'totals' => $depTotal];
    }
    if ($type === 'arrears') {
        $t = $byCur('amount');
        $lines[] = sprintf(__('imp_s_arrears', '%d unpaid charge(s) totalling %s'), count($ok), $moneyList($t) ?: '0');
        return ['lines' => $lines, 'entries' => count($ok), 'totals' => $t];
    }
    if ($type === 'deposits') {
        $t = $byCur('amount');
        $lines[] = sprintf(__('imp_s_deposits', '%d deposit(s) held totalling %s'), count($ok), $moneyList($t) ?: '0');
        return ['lines' => $lines, 'entries' => count($ok), 'totals' => $t];
    }
    if ($type === 'advances') {
        $t = $byCur('amount');
        $lines[] = sprintf(__('imp_s_advances', '%d tenant advance(s) totalling %s'), count($ok), $moneyList($t) ?: '0');
        return ['lines' => $lines, 'entries' => count($ok), 'totals' => $t];
    }
    // trial balance: per currency debits, credits and the balancing figure
    $per = [];
    foreach ($ok as $r) {
        $cur = $r['data']['currency'];
        $per[$cur]['dr'] = ($per[$cur]['dr'] ?? 0) + (float) ($r['data']['debit'] ?? 0);
        $per[$cur]['cr'] = ($per[$cur]['cr'] ?? 0) + (float) ($r['data']['credit'] ?? 0);
    }
    foreach ($per as $cur => $p) {
        $diff = round($p['dr'] - $p['cr'], 2);
        $lines[] = sprintf(__('imp_s_tb', '%s: debits %s, credits %s; %s to Opening Balance Equity'),
            $cur, number_format($p['dr'], 2), number_format($p['cr'], 2),
            $diff == 0 ? __('imp_s_tb_none', 'nothing') : ($diff > 0
                ? sprintf(__('imp_s_tb_cr', 'credit of %s'), number_format($diff, 2))
                : sprintf(__('imp_s_tb_dr', 'debit of %s'), number_format(-$diff, 2))));
    }
    return ['lines' => $lines, 'entries' => count($per), 'totals' => []];
}

// ============================================================
// Commit
// ============================================================

/**
 * Write a validated file. Caller has already re-validated: any error row means
 * nothing is written. All or nothing, in one transaction.
 *
 * @return array ['success'=>bool, 'batch_id'=>?int, 'errors'=>string[]]
 */
function importCommit($type, $validated, $fileName) {
    global $conn;

    foreach ($validated['rows'] as $r) {
        if ($r['status'] === 'error') return ['success' => false, 'batch_id' => null, 'errors' => [__('imp_e_has_errors', 'The file still has errors. Nothing was imported.')]];
    }
    $def = importType($type);
    if ($def['posts'] && importBlockedByLock()) {
        return ['success' => false, 'batch_id' => null, 'errors' => [__('imp_e_locked', 'Setup is finished and the opening date is closed. Reopen it in Ledger → Account Mapping to import opening figures.')]];
    }
    $goLive = importGoLiveDate();

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare('INSERT INTO import_batches (type, file_name, go_live_date, row_count, summary, created_by)
                                VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$type, mb_substr((string) $fileName, 0, 255), $goLive, count($validated['rows']),
                        json_encode($validated['summary']), getUserId()]);
        $batchId = (int) $conn->lastInsertId();

        $fn = 'importCommit_' . $type;
        $errors = $fn($batchId, $validated['rows']);
        if ($errors) {
            $conn->rollBack();
            return ['success' => false, 'batch_id' => null, 'errors' => $errors];
        }

        $conn->commit();
        logActivity('imported', 'import_batch', $batchId, ['type' => $type, 'rows' => count($validated['rows'])]);
        return ['success' => true, 'batch_id' => $batchId, 'errors' => []];
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('importCommit ' . $type . ': ' . $e->getMessage());
        return ['success' => false, 'batch_id' => null, 'errors' => ['Database error: ' . $e->getMessage()]];
    }
}

function importTrack($batchId, $table, $id, $before = null) {
    global $conn;
    $conn->prepare('INSERT INTO import_batch_items (batch_id, table_name, record_id, action, before_json) VALUES (?, ?, ?, ?, ?)')
         ->execute([$batchId, $table, (int) $id, $before === null ? 'created' : 'updated', $before === null ? null : json_encode($before)]);
}

/**
 * One opening entry, Dr/Cr against Opening Balance Equity. Returns entry id,
 * null when auto-posting is off (the tenant does not keep the ledger), or
 * throws so the whole batch rolls back.
 */
function importPostOpening($currency, $systemKey, $side, $amount, $tags, $memo, $sourceType, $sourceId) {
    if (!ledgerAutoPost()) return null;
    $acct = ledgerSystemAccountFor($systemKey, $currency);
    $equity = ledgerSystemAccountFor('opening_equity', $currency);
    if (!$acct || !$equity) {
        throw new RuntimeException(sprintf(__('imp_e_no_accounts', 'No %s or Opening Balance Equity account in %s. Check Ledger → Account Mapping.'), $systemKey, $currency));
    }
    $lines = $side === 'debit'
        ? [['account_id' => $acct['id'], 'debit' => $amount, 'credit' => 0] + $tags,
           ['account_id' => $equity['id'], 'debit' => 0, 'credit' => $amount] + $tags]
        : [['account_id' => $equity['id'], 'debit' => $amount, 'credit' => 0] + $tags,
           ['account_id' => $acct['id'], 'debit' => 0, 'credit' => $amount] + $tags];

    $entry = ledgerCreateEntry([
        'entry_date'    => importOpeningDate(),
        'currency'      => $currency,
        'memo'          => $memo,
        'source_type'   => $sourceType,
        'source_id'     => $sourceId,
        'status'        => 'posted',
        'system_posted' => true,
    ], $lines);
    if (!$entry['success']) throw new RuntimeException(implode(' | ', $entry['errors']));
    return (int) $entry['id'];
}

function importCreateDeposit($batchId, $lease, $amount, $currency, $paidDate, $reference) {
    global $conn;
    $conn->prepare("INSERT INTO deposits (lease_id, tenant_id, unit_id, amount, currency, paid_date, payment_method,
                        reference_number, status, notes, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'bank_transfer', ?, 'held', ?, ?)")
         ->execute([$lease['id'], $lease['tenant_id'], $lease['unit_id'], $amount, $currency,
                    $paidDate ?: importOpeningDate(), $reference ?: null,
                    __('imp_note_opening', 'Opening balance — imported'), getUserId()]);
    $depositId = (int) $conn->lastInsertId();
    importTrack($batchId, 'deposits', $depositId);

    $entryId = importPostOpening($currency, 'deposits_held', 'credit', $amount,
        ['property_id' => $lease['property_id'], 'unit_id' => $lease['unit_id'], 'lease_id' => $lease['id'], 'tenant_id' => $lease['tenant_id']],
        sprintf(__('imp_memo_deposit', 'Opening deposit held — unit %s'), $lease['unit_number']), 'deposit', $depositId);
    if ($entryId) importTrack($batchId, 'journal_entries', $entryId);
}

function importCommit_rent_roll($batchId, $rows) {
    global $conn;
    $propIds = [];
    $tenantIds = [];
    $newPropUnits = [];
    $billedThrough = importOpeningDate();

    foreach ($rows as $r) {
        $d = $r['data'];

        $pk = importNorm($d['property_name']);
        $propertyId = $d['property_id'] ?: ($propIds[$pk] ?? null);
        if (!$propertyId) {
            $conn->prepare("INSERT INTO properties (name, property_type, status) VALUES (?, ?, 'active')")
                 ->execute([$d['property_name'], $d['property_type']]);
            $propertyId = (int) $conn->lastInsertId();
            $propIds[$pk] = $propertyId;
            importTrack($batchId, 'properties', $propertyId);
        }

        $unitId = $d['unit_id'];
        if ($unitId) {
            $before = $conn->prepare('SELECT status FROM units WHERE id = ?');
            $before->execute([$unitId]);
            importTrack($batchId, 'units', $unitId, ['status' => $before->fetchColumn()]);
            $conn->prepare("UPDATE units SET status = 'occupied' WHERE id = ?")->execute([$unitId]);
        } else {
            $conn->prepare("INSERT INTO units (property_id, unit_number, unit_type, rent_amount, currency, status)
                            VALUES (?, ?, ?, ?, ?, 'occupied')")
                 ->execute([$propertyId, $d['unit_number'], $d['unit_type'], $d['monthly_rent'], $d['currency']]);
            $unitId = (int) $conn->lastInsertId();
            importTrack($batchId, 'units', $unitId);
            if (isset($propIds[$pk])) $newPropUnits[$propertyId] = ($newPropUnits[$propertyId] ?? 0) + 1;
        }

        $tk = importNorm($d['tenant_full']);
        $tenantId = $d['tenant_id'] ?: ($tenantIds[$tk] ?? null);
        if (!$tenantId) {
            $conn->prepare("INSERT INTO tenants (first_name, last_name, phone, email, tin, id_number, status, move_in_date)
                            VALUES (?, ?, ?, ?, ?, ?, 'active', ?)")
                 ->execute([$d['first_name'], $d['last_name'], $d['phone'] ?: null, $d['email'] ?: null,
                            normalizeTin($d['tin'])['value'], $d['id_number'] ?: null, $d['start']]);
            $tenantId = (int) $conn->lastInsertId();
            $tenantIds[$tk] = $tenantId;
            importTrack($batchId, 'tenants', $tenantId);
        } elseif ($d['tenant_id'] && !empty($d['update_existing']) && !isset($tenantIds[$tk])) {
            $cur = $conn->prepare('SELECT phone, email, tin, id_number FROM tenants WHERE id = ?');
            $cur->execute([$tenantId]);
            $before = $cur->fetch();
            importTrack($batchId, 'tenants', $tenantId, $before);
            $conn->prepare('UPDATE tenants SET phone = COALESCE(?, phone), email = COALESCE(?, email),
                                tin = COALESCE(tin, ?), id_number = COALESCE(id_number, ?) WHERE id = ?')
                 ->execute([$d['phone'] ?: null, $d['email'] ?: null, normalizeTin($d['tin'])['value'], $d['id_number'] ?: null, $tenantId]);
            $tenantIds[$tk] = $tenantId;
        }

        $months = paymentFrequencyMonths($d['frequency']);
        $conn->prepare("INSERT INTO leases (unit_id, tenant_id, start_date, end_date, rent_amount, currency,
                            deposit_amount, deposit_currency, payment_frequency, payment_due_day, billed_through, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')")
             ->execute([$unitId, $tenantId, $d['start'], $d['end'], round($d['monthly_rent'] * $months, 2), $d['currency'],
                        (float) ($d['deposit'] ?? 0), $d['currency'], $d['frequency'], $d['due_day'], $billedThrough]);
        $leaseId = (int) $conn->lastInsertId();
        importTrack($batchId, 'leases', $leaseId);

        if ($d['deposit'] > 0) {
            importCreateDeposit($batchId, ['id' => $leaseId, 'tenant_id' => $tenantId, 'unit_id' => $unitId,
                'property_id' => $propertyId, 'unit_number' => $d['unit_number']],
                $d['deposit'], $d['currency'], $d['deposit_date'], null);
        }
    }

    foreach ($newPropUnits as $pid => $n) {
        $conn->prepare('UPDATE properties SET total_units = ? WHERE id = ?')->execute([$n, $pid]);
    }
    return [];
}

function importCommit_arrears($batchId, $rows) {
    global $conn;
    $leasesTouched = [];
    foreach ($rows as $r) {
        $d = $r['data'];
        $lease = $d['lease'];
        $desc = sprintf(__('imp_desc_arrears', 'Opening balance %s — %s to %s'), $lease['unit_number'],
            date('d/m/Y', strtotime($d['from'])), date('d/m/Y', strtotime($d['to'])));
        if ($d['note'] !== '') $desc .= ' — ' . $d['note'];

        $conn->prepare("INSERT INTO charges (type, lease_id, tenant_id, unit_id, property_id, period_from, period_to,
                            due_date, amount, currency, description, source_type, source_id, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'opening_balance', ?, ?)")
             ->execute([$d['type'], $lease['id'], $lease['tenant_id'], $lease['unit_id'], $lease['property_id'],
                        $d['from'], $d['to'], $d['due'], $d['amount'], $d['currency'], mb_substr($desc, 0, 255), $batchId, getUserId()]);
        $chargeId = (int) $conn->lastInsertId();
        importTrack($batchId, 'charges', $chargeId);

        $entryId = importPostOpening($d['currency'], 'ar', 'debit', $d['amount'],
            ['property_id' => $lease['property_id'], 'unit_id' => $lease['unit_id'], 'lease_id' => $lease['id'], 'tenant_id' => $lease['tenant_id']],
            $desc, 'charge', $chargeId);
        if ($entryId) {
            $conn->prepare('UPDATE charges SET entry_id = ? WHERE id = ?')->execute([$entryId, $chargeId]);
            importTrack($batchId, 'journal_entries', $entryId);
        }

        $leasesTouched[$lease['id']] = max($leasesTouched[$lease['id']] ?? '', $d['to']);
    }

    // Billing must resume after the last imported period, never inside it.
    foreach ($leasesTouched as $leaseId => $lastTo) {
        $cur = $conn->prepare('SELECT billed_through, tenant_id, currency FROM leases WHERE id = ?');
        $cur->execute([$leaseId]);
        $l = $cur->fetch();
        $target = max($l['billed_through'] ?: '', importOpeningDate(), $lastTo);
        if ($target !== $l['billed_through']) {
            importTrack($batchId, 'leases', $leaseId, ['billed_through' => $l['billed_through']]);
            $conn->prepare('UPDATE leases SET billed_through = ? WHERE id = ?')->execute([$target, $leaseId]);
        }
        // An advance imported earlier settles these at once.
        applyOpenCredit($l['tenant_id'], $leaseId, $l['currency']);
    }
    return [];
}

function importCommit_deposits($batchId, $rows) {
    foreach ($rows as $r) {
        $d = $r['data'];
        importCreateDeposit($batchId, $d['lease'], $d['amount'], $d['currency'], $d['paid_date'], $d['reference']);
    }
    return [];
}

function importCommit_advances($batchId, $rows) {
    global $conn;
    foreach ($rows as $r) {
        $d = $r['data'];
        $lease = $d['lease'];
        $conn->prepare("INSERT INTO payments (lease_id, tenant_id, unit_id, amount, currency, payment_date, payment_method,
                            reference_number, notes, recorded_by, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'bank_transfer', 'OPENING-ADVANCE', ?, ?, 'recorded')")
             ->execute([$lease['id'], $lease['tenant_id'], $lease['unit_id'], $d['amount'], $d['currency'], importOpeningDate(),
                        trim(__('imp_note_advance', 'Rent paid in advance before go-live — imported') . ($d['note'] !== '' ? ' — ' . $d['note'] : '')),
                        getUserId()]);
        $paymentId = (int) $conn->lastInsertId();
        importTrack($batchId, 'payments', $paymentId);

        $entryId = importPostOpening($d['currency'], 'ar', 'credit', $d['amount'],
            ['property_id' => $lease['property_id'], 'unit_id' => $lease['unit_id'], 'lease_id' => $lease['id'], 'tenant_id' => $lease['tenant_id']],
            sprintf(__('imp_memo_advance', 'Opening advance — unit %s'), $lease['unit_number']), 'payment', $paymentId);
        if ($entryId) {
            importTrack($batchId, 'journal_entries', $entryId);
            allocatePayment($paymentId, $d['amount'], $lease['tenant_id'], $lease['id'], $d['currency']);
        }
    }
    return [];
}

function importCommit_trial_balance($batchId, $rows) {
    global $conn;
    $per = [];
    foreach ($rows as $r) $per[$r['data']['currency']][] = $r['data'];

    foreach ($per as $currency => $items) {
        $equity = ledgerSystemAccountFor('opening_equity', $currency);
        if (!$equity) return [sprintf(__('imp_e_no_accounts', 'No %s or Opening Balance Equity account in %s. Check Ledger → Account Mapping.'), 'opening_equity', $currency)];
        $lines = [];
        $dr = 0; $cr = 0;
        foreach ($items as $d) {
            $lines[] = ['account_id' => $d['account']['id'], 'debit' => (float) ($d['debit'] ?? 0), 'credit' => (float) ($d['credit'] ?? 0)];
            $dr += (float) ($d['debit'] ?? 0);
            $cr += (float) ($d['credit'] ?? 0);
        }
        $diff = round($dr - $cr, 2);
        if ($diff > 0)      $lines[] = ['account_id' => $equity['id'], 'debit' => 0, 'credit' => $diff];
        elseif ($diff < 0)  $lines[] = ['account_id' => $equity['id'], 'debit' => -$diff, 'credit' => 0];

        // Pending on purpose: a manual opening journal needs a second person,
        // exactly like the Opening Balances form.
        $entry = ledgerCreateEntry([
            'entry_date'  => importOpeningDate(),
            'currency'    => $currency,
            'memo'        => sprintf(__('imp_memo_tb', 'Opening trial balance — import #%d'), $batchId),
            'source_type' => 'opening_balance',
            'source_id'   => $batchId,
            'status'      => 'pending',
        ], $lines);
        if (!$entry['success']) return $entry['errors'];
        importTrack($batchId, 'journal_entries', $entry['id']);
    }
    return [];
}

// ============================================================
// Undo
// ============================================================

function importBatch($id) {
    global $conn;
    $stmt = $conn->prepare('SELECT b.*, u.full_name AS created_by_name FROM import_batches b
                            LEFT JOIN users u ON u.id = b.created_by WHERE b.id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch() ?: null;
}

function importBatchItems($batchId) {
    global $conn;
    $stmt = $conn->prepare('SELECT * FROM import_batch_items WHERE batch_id = ? ORDER BY id');
    $stmt->execute([(int) $batchId]);
    $out = [];
    foreach ($stmt->fetchAll() as $i) $out[$i['table_name']][] = $i;
    return $out;
}

/** Why this batch cannot be undone — an empty list means it can. */
function importUndoBlockers($batchId) {
    global $conn;
    $batch = importBatch($batchId);
    if (!$batch || $batch['status'] !== 'committed') return [__('imp_u_not_committed', 'This import has already been undone.')];

    $items = importBatchItems($batchId);
    $ids = function ($table, $action = 'created') use ($items) {
        $out = [];
        foreach ($items[$table] ?? [] as $i) if ($i['action'] === $action) $out[] = (int) $i['record_id'];
        return $out;
    };
    $in = function ($list) { return implode(',', array_map('intval', $list)) ?: '0'; };
    $count = function ($sql) use ($conn) { return (int) $conn->query($sql)->fetchColumn(); };
    $since = $conn->quote($batch['created_at']);
    $blockers = [];

    $opening = date('Y-m-d', strtotime($batch['go_live_date'] . ' -1 day'));
    if (!empty($items['journal_entries']) && ledgerDateLocked($opening)) {
        $blockers[] = sprintf(__('imp_u_locked', 'The books are closed up to %s. Reopen them in Ledger → Account Mapping first.'), formatDate(ledgerLockDate()));
    }

    // Money that has been applied to what this batch created.
    $charges = $ids('charges');
    if ($charges && ($n = $count("SELECT COUNT(DISTINCT charge_id) FROM payment_allocations WHERE charge_id IN ({$in($charges)})"))) {
        $blockers[] = sprintf(__('imp_u_charges_paid', '%d of these charges already have payments or advances applied.'), $n);
    }
    $payments = $ids('payments');
    if ($payments && ($n = $count("SELECT COUNT(*) FROM payment_allocations a INNER JOIN charges c ON c.id = a.charge_id
                                    WHERE a.payment_id IN ({$in($payments)}) AND c.source_type <> 'opening_balance'"))) {
        $blockers[] = sprintf(__('imp_u_advance_used', '%d of these advances have already settled rent billed by the system.'), $n);
    }
    $deposits = $ids('deposits');
    if ($deposits && ($n = $count("SELECT COUNT(*) FROM deposits WHERE id IN ({$in($deposits)})
                                    AND (status <> 'held' OR payout_status <> 'none' OR refund_amount > 0)"))) {
        $blockers[] = sprintf(__('imp_u_deposit_used', '%d of these deposits have been refunded, forfeited or have a refund pending.'), $n);
    }

    // Records this batch created that other work now hangs off.
    $leases = $ids('leases');
    if ($leases) {
        $l = $in($leases);
        if ($n = $count("SELECT COUNT(DISTINCT lease_id) FROM charges WHERE lease_id IN ({$l}) AND source_type <> 'opening_balance'")) {
            $blockers[] = sprintf(__('imp_u_leases_billed', 'Rent has already been billed on %d of these leases.'), $n);
        }
        if ($n = $count("SELECT COUNT(*) FROM payments WHERE lease_id IN ({$l}) AND COALESCE(reference_number, '') <> 'OPENING-ADVANCE'")) {
            $blockers[] = sprintf(__('imp_u_leases_paid', '%d payment(s) have been recorded on these leases.'), $n);
        }
        $other = $conn->query("SELECT DISTINCT b.id FROM import_batch_items i INNER JOIN import_batches b ON b.id = i.batch_id
                               WHERE b.status = 'committed' AND b.id <> " . (int) $batchId . " AND (
                                   (i.table_name = 'charges'  AND i.record_id IN (SELECT id FROM charges  WHERE lease_id IN ({$l})))
                                OR (i.table_name = 'payments' AND i.record_id IN (SELECT id FROM payments WHERE lease_id IN ({$l})))
                                OR (i.table_name = 'deposits' AND i.record_id IN (SELECT id FROM deposits WHERE lease_id IN ({$l}))))")
                     ->fetchAll(PDO::FETCH_COLUMN);
        if ($other) {
            $blockers[] = sprintf(__('imp_u_later_batch', 'Later imports use these leases. Undo import #%s first.'), implode(', #', $other));
        }
        $ownDeposits = $in($deposits);
        if ($n = $count("SELECT COUNT(*) FROM deposits WHERE lease_id IN ({$l}) AND id NOT IN ({$ownDeposits})
                         AND id NOT IN (SELECT record_id FROM import_batch_items WHERE table_name = 'deposits')")) {
            $blockers[] = sprintf(__('imp_u_leases_deposit', '%d deposit(s) have been recorded by hand on these leases.'), $n);
        }
        if ($n = $count("SELECT COUNT(*) FROM leases WHERE id IN ({$l}) AND updated_at > DATE_ADD({$since}, INTERVAL 1 MINUTE)")) {
            $blockers[] = sprintf(__('imp_u_edited', '%d record(s) were edited after the import.'), $n);
        }
    }
    $units = $ids('units');
    if ($units && ($n = $count("SELECT COUNT(*) FROM leases WHERE unit_id IN ({$in($units)}) AND id NOT IN ({$in($leases)})"))) {
        $blockers[] = sprintf(__('imp_u_units_used', '%d other lease(s) now use units this import created.'), $n);
    }
    $tenants = $ids('tenants');
    if ($tenants && ($n = $count("SELECT COUNT(*) FROM leases WHERE tenant_id IN ({$in($tenants)}) AND id NOT IN ({$in($leases)})"))) {
        $blockers[] = sprintf(__('imp_u_tenants_used', '%d other lease(s) now belong to tenants this import created.'), $n);
    }
    $props = $ids('properties');
    if ($props && ($n = $count("SELECT COUNT(*) FROM units WHERE property_id IN ({$in($props)}) AND id NOT IN ({$in($units)})"))) {
        $blockers[] = sprintf(__('imp_u_props_used', '%d unit(s) have been added by hand to properties this import created.'), $n);
    }
    foreach (['properties' => $props, 'units' => $units, 'tenants' => $tenants, 'deposits' => $deposits] as $table => $list) {
        if ($list && ($n = $count("SELECT COUNT(*) FROM {$table} WHERE id IN ({$in($list)}) AND updated_at > DATE_ADD({$since}, INTERVAL 1 MINUTE)"))) {
            $blockers[] = sprintf(__('imp_u_edited', '%d record(s) were edited after the import.'), $n);
        }
    }
    return array_values(array_unique($blockers));
}

/** Mirror an opening entry back out, posted at once on the same date. */
function importReverseEntry($entryId, $batchId) {
    global $conn;
    $entry = ledgerEntry($entryId);
    if (!$entry) return;
    if ($entry['status'] === 'pending') {
        $conn->prepare("UPDATE journal_entries SET status = 'rejected', rejection_reason = ? WHERE id = ?")
             ->execute([sprintf(__('imp_memo_undone', 'Import #%d undone'), $batchId), $entryId]);
        return;
    }
    if ($entry['status'] !== 'posted' || !empty($entry['reversed_by_entry_id'])) return;

    $mirror = [];
    foreach ($entry['lines'] as $l) {
        $mirror[] = ['account_id' => $l['account_id'], 'debit' => (float) $l['credit'], 'credit' => (float) $l['debit'],
                     'property_id' => $l['property_id'], 'unit_id' => $l['unit_id'], 'lease_id' => $l['lease_id'], 'tenant_id' => $l['tenant_id']];
    }
    $result = ledgerCreateEntry([
        'entry_date'        => $entry['entry_date'],
        'currency'          => $entry['currency'],
        'memo'              => sprintf(__('imp_memo_undone', 'Import #%d undone'), $batchId) . ' — ' . $entry['entry_no'],
        'source_type'       => 'reversal',
        'source_id'         => (int) $entryId,
        'status'            => 'posted',
        'system_posted'     => true,
        'reverses_entry_id' => (int) $entryId,
    ], $mirror);
    if (!$result['success']) throw new RuntimeException(implode(' | ', $result['errors']));
    $conn->prepare('UPDATE journal_entries SET reversed_by_entry_id = ? WHERE id = ?')->execute([$result['id'], $entryId]);
}

function importUndo($batchId) {
    global $conn;
    $blockers = importUndoBlockers($batchId);
    if ($blockers) return ['success' => false, 'errors' => $blockers];

    $items = importBatchItems($batchId);
    $conn->beginTransaction();
    try {
        foreach ($items['journal_entries'] ?? [] as $i) importReverseEntry((int) $i['record_id'], $batchId);

        // An advance may have settled imported arrears: give those charges back their balance.
        foreach ($items['payments'] ?? [] as $i) {
            $alloc = $conn->prepare('SELECT charge_id, amount FROM payment_allocations WHERE payment_id = ?');
            $alloc->execute([$i['record_id']]);
            foreach ($alloc->fetchAll() as $a) {
                $conn->prepare("UPDATE charges SET amount_settled = GREATEST(0, amount_settled - :amt),
                                    status = CASE WHEN amount_settled - :amt2 <= 0 THEN 'open' ELSE 'part' END
                                WHERE id = :id")
                     ->execute(['amt' => $a['amount'], 'amt2' => $a['amount'], 'id' => $a['charge_id']]);
            }
            $conn->prepare('DELETE FROM payment_allocations WHERE payment_id = ?')->execute([$i['record_id']]);
        }

        // Children before parents.
        $order = ['payments', 'charges', 'deposits', 'leases', 'tenants', 'units', 'properties'];
        foreach ($order as $table) {
            foreach (array_reverse($items[$table] ?? []) as $i) {
                if ($i['action'] === 'updated') {
                    $before = json_decode($i['before_json'], true) ?: [];
                    if ($before) {
                        $set = implode(', ', array_map(function ($k) { return "`{$k}` = ?"; }, array_keys($before)));
                        $conn->prepare("UPDATE `{$table}` SET {$set} WHERE id = ?")
                             ->execute(array_merge(array_values($before), [(int) $i['record_id']]));
                    }
                } else {
                    $conn->prepare("DELETE FROM `{$table}` WHERE id = ?")->execute([(int) $i['record_id']]);
                }
            }
        }

        $conn->prepare("UPDATE import_batches SET status = 'undone', undone_by = ?, undone_at = NOW() WHERE id = ?")
             ->execute([getUserId(), (int) $batchId]);
        $conn->commit();
        logActivity('undone', 'import_batch', (int) $batchId);
        return ['success' => true, 'errors' => []];
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        return ['success' => false, 'errors' => [$e->getMessage()]];
    }
}

// ============================================================
// Finish setup
// ============================================================

/**
 * Checks shown before Finish setup. 'block' entries stop it; 'warn' entries
 * are shown in red but the admin may still go ahead.
 */
function importFinishChecks() {
    global $conn;
    $checks = [];
    $opening = importOpeningDate();
    if (!$opening) return [['block', __('imp_f_no_golive', 'Set the go-live date first.')]];

    if (ledgerAutoPost()) {
        foreach (ledgerCurrencies() as $cur) {
            $tb = ledgerTrialBalance($cur);
            if (!$tb['balanced']) {
                $checks[] = ['block', sprintf(__('imp_f_unbalanced', 'The %s ledger does not balance (debits %s, credits %s).'),
                    $cur, number_format($tb['total_debit'], 2), number_format($tb['total_credit'], 2))];
            }
            // Receivables in the ledger should equal open charges less unused credit.
            $ar = ledgerSystemAccountFor('ar', $cur);
            if ($ar) {
                $bal = ledgerAccountBalance($ar['id']);
                $stmt = $conn->prepare("SELECT COALESCE(SUM(amount - amount_settled), 0) FROM charges
                                        WHERE status IN ('open', 'part') AND currency = ?");
                $stmt->execute([$cur]);
                $open = (float) $stmt->fetchColumn();
                $credit = importUnallocatedTotal($cur);
                if (abs(($open - $credit) - $bal) > 0.01) {
                    $checks[] = ['warn', sprintf(__('imp_f_ar_mismatch', 'Tenant Receivables in the ledger is %s, but open charges less unused credit come to %s (%s).'),
                        number_format($bal, 2), number_format($open - $credit, 2), $cur)];
                }
            }
            $dh = ledgerSystemAccountFor('deposits_held', $cur);
            if ($dh) {
                $bal = ledgerAccountBalance($dh['id']);
                $stmt = $conn->prepare("SELECT COALESCE(SUM(amount - COALESCE(refund_amount, 0)), 0) FROM deposits
                                        WHERE status IN ('held', 'partially_refunded') AND currency = ?");
                $stmt->execute([$cur]);
                $held = (float) $stmt->fetchColumn();
                if (abs($held - $bal) > 0.01) {
                    $checks[] = ['warn', sprintf(__('imp_f_dep_mismatch', 'Deposits Held in the ledger is %s, but deposits on record come to %s (%s).'),
                        number_format($bal, 2), number_format($held, 2), $cur)];
                }
            }
        }
    }

    $pending = $conn->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'opening_balance' AND status = 'pending'")->fetchColumn();
    if ((int) $pending) {
        $checks[] = ['block', sprintf(__('imp_f_tb_pending', '%d opening entry(ies) are waiting for approval in the Journal.'), $pending)];
    }
    $noDate = (int) $conn->query("SELECT COUNT(*) FROM leases WHERE status = 'active' AND billed_through IS NULL")->fetchColumn();
    if ($noDate) {
        $checks[] = ['warn', sprintf(__('imp_f_no_billed', '%d active lease(s) have no automatic-billing start date and will be billed from their start date.'), $noDate)];
    }
    return $checks;
}

function importUnallocatedTotal($currency) {
    global $conn;
    $stmt = $conn->prepare("SELECT COALESCE(SUM(p.amount - COALESCE(a.allocated, 0)), 0)
                            FROM payments p
                            LEFT JOIN (SELECT payment_id, SUM(amount) AS allocated FROM payment_allocations GROUP BY payment_id) a
                                   ON a.payment_id = p.id
                            WHERE p.status = 'recorded' AND p.currency = ?
                              AND EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_type = 'payment'
                                          AND je.source_id = p.id AND je.status = 'posted')");
    $stmt->execute([$currency]);
    return (float) $stmt->fetchColumn();
}

function importFinishSetup() {
    foreach (importFinishChecks() as $c) {
        if ($c[0] === 'block') return ['success' => false, 'errors' => [$c[1]]];
    }
    $opening = importOpeningDate();
    $lock = ledgerLockDate();
    if (!$lock || $lock < $opening) updateSetting('ledger_lock_date', $opening);
    updateSetting('setup_finished_at', date('Y-m-d H:i:s'));
    updateSetting('setup_finished_by', (string) getUserId());
    logActivity('finished', 'setup', null, ['locked_to' => $opening]);
    return ['success' => true, 'errors' => []];
}
