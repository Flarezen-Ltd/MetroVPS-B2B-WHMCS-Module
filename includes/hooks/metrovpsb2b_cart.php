<?php

/**
 * MetroVPS B2B cart configuration hook.
 *
 * - Hides built-in Root Password, NS1 and NS2 fields.
 * - Makes Hostname mandatory.
 * - Dynamically populates an "Operating System" custom field dropdown.
 * - Validates hostname and OS before checkout.
 */

use WHMCS\Database\Capsule as DB;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once ROOTDIR . '/modules/servers/MetroVPSB2B/lib/ApiClient.php';
require_once ROOTDIR . '/modules/servers/MetroVPSB2B/lib/Module.php';
require_once ROOTDIR . '/modules/servers/MetroVPSB2B/lib/Database.php';

/**
 * Determine whether the current request is the product configuration page
 * for a MetroVPSB2B product in the shopping cart.
 *
 * @return array|false ['product_id' => int, 'cart_index' => int] or false
 */
function metrovpsb2bDetectConfProduct()
{
    $action = $_REQUEST['a'] ?? '';
    $cartIndex = isset($_REQUEST['i']) ? (int) $_REQUEST['i'] : null;

    if ($action !== 'confproduct' || $cartIndex === null) {
        return false;
    }

    $cartItem = $_SESSION['cart']['products'][$cartIndex] ?? null;

    if (empty($cartItem['pid'])) {
        return false;
    }

    $productId = (int) $cartItem['pid'];

    $product = DB::table('tblproducts')
        ->where('id', $productId)
        ->where('servertype', 'MetroVPSB2B')
        ->first();

    if (!$product) {
        return false;
    }

    return [
        'product_id' => $productId,
        'cart_index' => $cartIndex,
    ];
}

add_hook('ClientAreaPageCart', 1, function ($vars) {
    $context = metrovpsb2bDetectConfProduct();

    if (!$context) {
        return $vars;
    }

    $productId = $context['product_id'];
    $cartIndex = $context['cart_index'];
    $cartItem = $_SESSION['cart']['products'][$cartIndex] ?? null;

    $fieldName = 'Operating System';
    $field = DB::table('tblcustomfields')
        ->where('type', 'product')
        ->where('relid', $productId)
        ->where('fieldname', $fieldName)
        ->first();

    if (!$field) {
        $fieldId = DB::table('tblcustomfields')->insertGetId([
            'type' => 'product',
            'relid' => $productId,
            'fieldname' => $fieldName,
            'fieldtype' => 'text',
            'description' => 'Select the operating system for your VPS',
            'fieldoptions' => '',
            'required' => 'on',
            'showorder' => 'on',
            'showinvoice' => '',
            'sortorder' => 0,
            'adminonly' => '',
        ]);

        $field = DB::table('tblcustomfields')->where('id', $fieldId)->first();
    }

    if (!$field) {
        return $vars;
    }

    // Ensure the field is stored as text so dynamically generated OS template IDs
    // are accepted even though the rendered input is a dropdown.
    if ($field->fieldtype !== 'text') {
        DB::table('tblcustomfields')
            ->where('id', $field->id)
            ->update(['fieldtype' => 'text', 'fieldoptions' => '']);

        $field->fieldtype = 'text';
    }

    $fieldId = (int) $field->id;
    $templates = [];

    try {
        $module = new WHMCS\Module\Server\MetroVPSB2B\Module();
        $templates = $module->getOsTemplatesForProduct($productId);
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', 'CartOsTemplates', ['product_id' => $productId], $e->getMessage(), $e->getTraceAsString());
        return $vars;
    }

    if (empty($templates)) {
        return $vars;
    }

    $selectedValue = '';

    if (isset($_POST['customfield'][$fieldId])) {
        $selectedValue = $_POST['customfield'][$fieldId];
    } elseif (isset($cartItem['customfields'][$fieldId])) {
        $selectedValue = $cartItem['customfields'][$fieldId];
    }

    $optionsHtml = '<option value="">Please select...</option>';

    foreach ($templates as $id => $name) {
        $selected = ((string) $selectedValue === (string) $id) ? ' selected="selected"' : '';
        $optionsHtml .= sprintf(
            '<option value="%s"%s>%s</option>',
            htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
            $selected,
            htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
        );
    }

    $inputHtml = sprintf(
        '<select name="customfield[%d]" id="customfield%d" class="form-control" required>%s</select>',
        $fieldId,
        $fieldId,
        $optionsHtml
    );

    $customFieldEntry = [
        'id' => $fieldId,
        'name' => $field->fieldname,
        'description' => $field->description,
        'type' => $field->fieldtype,
        'required' => $field->required === 'on' ? ' *' : '',
        'input' => $inputHtml,
    ];

    $customFields = $vars['customfields'] ?? [];
    $updated = false;

    foreach ($customFields as $key => $customField) {
        if ((int) ($customField['id'] ?? 0) === $fieldId) {
            $customFields[$key] = $customFieldEntry;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $customFields[] = $customFieldEntry;
    }

    $vars['customfields'] = $customFields;

    return $vars;
});

/**
 * Hide the built-in Root Password, NS1 Prefix and NS2 Prefix fields on the
 * MetroVPS B2B product configuration page. Hostname remains visible.
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    if (!metrovpsb2bDetectConfProduct()) {
        return '';
    }

    return <<<'JS'
<script>
(function () {
    var hiddenFields = ['inputRootpw', 'inputNs1prefix', 'inputNs2prefix'];

    function randomHex(length) {
        var chars = 'abcdef0123456789';
        var result = '';
        for (var i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }

    function hideField(id) {
        var el = document.getElementById(id);
        if (!el) return;

        var container = el.closest('.form-group, .col-sm-6, .col-md-6, [class*="col-"]');
        if (container) {
            container.style.display = 'none';
        } else {
            el.style.display = 'none';
            var label = document.querySelector('label[for="' + id + '"]');
            if (label) label.style.display = 'none';
        }
    }

    function prefillHiddenFields() {
        var pw = document.getElementById('inputRootpw');
        var ns1 = document.getElementById('inputNs1prefix');
        var ns2 = document.getElementById('inputNs2prefix');

        if (pw && !pw.value) pw.value = 'MetroVPS_Auto_' + randomHex(16);
        if (ns1 && !ns1.value) ns1.value = 'ns1';
        if (ns2 && !ns2.value) ns2.value = 'ns2';
    }

    function makeHostnameRequired() {
        var hostname = document.getElementById('inputHostname');
        if (!hostname) return;

        hostname.setAttribute('required', 'required');
        hostname.setAttribute('placeholder', 'servername.example.com (required)');

        var form = hostname.closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                prefillHiddenFields();

                var value = hostname.value.trim();
                if (!value) {
                    e.preventDefault();
                    alert('Please enter a hostname for your VPS.');
                    hostname.focus();
                    return false;
                }

                var os = document.querySelector('select[name^="customfield["]');
                if (os && !os.value) {
                    e.preventDefault();
                    alert('Please select an operating system.');
                    os.focus();
                    return false;
                }
            });
        }
    }

    function run() {
        prefillHiddenFields();
        hiddenFields.forEach(hideField);
        makeHostnameRequired();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
</script>
JS;
});

/**
 * Server-side validation: ensure every MetroVPSB2B product in the cart has
 * a hostname and a selected operating system before checkout.
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    $errors = [];
    $cart = $_SESSION['cart'] ?? [];
    $products = $cart['products'] ?? [];

    if (empty($products)) {
        return $errors;
    }

    $productIds = array_column($products, 'pid');

    $metroProducts = DB::table('tblproducts')
        ->whereIn('id', $productIds)
        ->where('servertype', 'MetroVPSB2B')
        ->pluck('id')
        ->toArray();

    if (empty($metroProducts)) {
        return $errors;
    }

    $osFieldIds = DB::table('tblcustomfields')
        ->where('type', 'product')
        ->whereIn('relid', $metroProducts)
        ->where('fieldname', 'Operating System')
        ->pluck('id', 'relid')
        ->toArray();

    foreach ($products as $index => $product) {
        if (!in_array((string) $product['pid'], $metroProducts, true)) {
            continue;
        }

        $hostname = trim($product['hostname'] ?? '');

        if ($hostname === '') {
            $errors[] = 'Please enter a hostname for your VPS.';
        }

        $fieldId = $osFieldIds[$product['pid']] ?? null;
        $osValue = '';

        if ($fieldId && isset($product['customfields'][$fieldId])) {
            $osValue = trim($product['customfields'][$fieldId]);
        }

        if ($osValue === '') {
            $errors[] = 'Please select an operating system for your VPS.';
        }
    }

    return $errors;
});

/**
 * On the client area product details page, for MetroVPS B2B services:
 *  - hide the nameserver row, which is meaningless for a VPS, and
 *  - hide the "Additional Information" tab (the Operating System custom
 *    field is already visible on the VPS dashboard).
 */
add_hook('ClientAreaPageProductDetails', 1, function ($vars) {
    $serviceId = $_REQUEST['id'] ?? null;

    if (!$serviceId) {
        return $vars;
    }

    $hosting = DB::table('tblhosting')
        ->where('id', (int) $serviceId)
        ->first();

    if (!$hosting || !$hosting->packageid) {
        return $vars;
    }

    $product = DB::table('tblproducts')
        ->where('id', (int) $hosting->packageid)
        ->where('servertype', 'MetroVPSB2B')
        ->first();

    if (!$product) {
        return $vars;
    }

    // Nameservers mean nothing for a VPS. The cart hook fills the NS1/NS2
    // prefix inputs with placeholder values to satisfy WHMCS, and those are
    // what get echoed back here — blank them so the row does not render.
    // Blanking rather than unsetting keeps this correct whether WHMCS merges
    // or replaces the array a hook returns.
    $vars['ns1'] = '';
    $vars['ns2'] = '';

    if (isset($vars['serverdata']) && is_array($vars['serverdata'])) {
        foreach (['nameserver1', 'nameserver2', 'nameserver3', 'nameserver4', 'nameserver5'] as $key) {
            if (array_key_exists($key, $vars['serverdata'])) {
                $vars['serverdata'][$key] = '';
            }
        }
    }

    // Hide the "Additional Information" tab — it only mirrors the Operating
    // System custom field, which clients already see on the VPS dashboard.
    // An empty customfields array makes the template skip the tab entirely.
    $vars['customfields'] = [];

    return $vars;
});

/* ---------------------------------------------------------------------------
 * Overview widget
 *
 * Injects a compact VPS dashboard into the Overview tab of the client area
 * product details page via the template's $hookOutput loop. The full
 * dashboard lives in the Manage tab (module client area output); this widget
 * repeats status + quick actions where clients land first.
 * ------------------------------------------------------------------------- */

function metrovpsb2bWidgetEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function metrovpsb2bWidgetBadge(?string $status): string
{
    // Lifecycle statuses only — the transient power/remote states live on
    // the neighbouring power-state badge.
    switch ($status) {
        case 'active':
            return '<span class="metrovps-badge metrovps-badge--active" data-metrovps-lifecycle-badge data-state="active"><i class="fas fa-check-circle"></i> Active</span>';
        case 'processing':
            return '<span class="metrovps-badge metrovps-badge--processing" data-metrovps-lifecycle-badge data-state="processing"><i class="fas fa-spinner fa-pulse"></i> Provisioning</span>';
        case 'suspended':
            return '<span class="metrovps-badge metrovps-badge--suspended" data-metrovps-lifecycle-badge data-state="suspended"><i class="fas fa-pause-circle"></i> Suspended</span>';
        case 'inactive':
            return '<span class="metrovps-badge metrovps-badge--unknown" data-metrovps-lifecycle-badge data-state="inactive"><i class="fas fa-minus-circle"></i> Inactive</span>';
        case 'terminated':
            return '<span class="metrovps-badge metrovps-badge--unknown" data-metrovps-lifecycle-badge data-state="terminated"><i class="fas fa-ban"></i> Terminated</span>';
        case 'failed':
            return '<span class="metrovps-badge metrovps-badge--failed" data-metrovps-lifecycle-badge data-state="failed"><i class="fas fa-times-circle"></i> Failed</span>';
        default:
            return '<span class="metrovps-badge metrovps-badge--unknown" data-metrovps-lifecycle-badge data-state="' . ($status !== null && $status !== '' ? metrovpsb2bWidgetEsc($status) : '') . '">'
                . ($status !== null && $status !== '' ? metrovpsb2bWidgetEsc($status) : 'Unknown')
                . '</span>';
    }
}

function metrovpsb2bWidgetPowerBadge(array $vpsData): string
{
    // Strictly remote_state — this badge owns the transient states.
    $state = $vpsData['remote_state'] ?? null;
    $stateAttr = $state !== null && $state !== '' ? ' data-state="' . metrovpsb2bWidgetEsc($state) . '"' : '';

    $transitions = [
        'starting'   => ['label' => 'Starting',   'icon' => 'fa-spinner fa-pulse'],
        'restarting' => ['label' => 'Restarting', 'icon' => 'fa-sync-alt fa-spin'],
        'stopping'   => ['label' => 'Stopping',   'icon' => 'fa-spinner fa-pulse'],
        'installing' => ['label' => 'Installing', 'icon' => 'fa-sync-alt fa-spin'],
    ];

    if (isset($transitions[$state])) {
        $transition = $transitions[$state];

        return '<span class="metrovps-badge metrovps-badge--installing" data-metrovps-state-badge' . $stateAttr . '>'
            . '<i class="fas ' . $transition['icon'] . '"></i> ' . $transition['label'] . '</span>';
    }

    if ($state === 'running') {
        return '<span class="metrovps-badge metrovps-badge--power" data-metrovps-state-badge data-state="running"><i class="fas fa-play"></i> Running</span>';
    }

    if ($state === 'stopped') {
        return '<span class="metrovps-badge metrovps-badge--stopped" data-metrovps-state-badge data-state="stopped"><i class="fas fa-stop"></i> Stopped</span>';
    }

    return '<span class="metrovps-badge metrovps-badge--unknown" data-metrovps-state-badge' . $stateAttr . '><i class="fas fa-question-circle"></i> Unknown</span>';
}

function metrovpsb2bWidgetOsIcon(?string $osName, ?string $osIcon): string
{
    if ($osIcon !== null && $osIcon !== '' && preg_match('#^https?://#i', $osIcon)) {
        $src = metrovpsb2bWidgetEsc($osIcon);
    } else {
        $name = strtolower($osName ?? '');
        $slug = 'generic';

        foreach (['ubuntu' => 'ubuntu', 'debian' => 'debian', 'centos' => 'centos', 'rocky' => 'rockylinux', 'alma' => 'almalinux', 'fedora' => 'fedora', 'windows' => 'windows'] as $keyword => $candidate) {
            if (strpos($name, $keyword) !== false) {
                $slug = $candidate;
                break;
            }
        }

        $src = '/modules/servers/MetroVPSB2B/assets/img/os/' . $slug . '.svg';
    }

    $alt = $osName !== null && $osName !== '' ? $osName : 'Operating System';

    return '<img class="metrovps-os-icon metrovps-os-icon--overview" src="' . $src . '" alt="' . metrovpsb2bWidgetEsc($alt) . '">';
}

function metrovpsb2bWidgetActions(int $serviceId, array $vpsData, string $modalId, int $lockSeconds = 0, int $rebuildLockSeconds = 0): string
{
    $isRunning = ($vpsData['remote_state'] ?? '') === 'running';

    $power = $isRunning
        ? '<button type="button" class="metrovps-btn metrovps-btn--danger" data-metrovps-action="poweroff" data-serviceid="' . $serviceId . '"><i class="fas fa-power-off"></i> Power Off</button>'
        : '<button type="button" class="metrovps-btn metrovps-btn--success" data-metrovps-action="poweron" data-serviceid="' . $serviceId . '"><i class="fas fa-power-off"></i> Power On</button>';

    if ($rebuildLockSeconds > 0) {
        $lockAttr = ' data-metrovps-rebuild-lock="' . $rebuildLockSeconds . '"';
    } elseif ($lockSeconds > 0) {
        $lockAttr = ' data-metrovps-lock-seconds="' . $lockSeconds . '"';
    } else {
        $lockAttr = '';
    }

    return '<div class="metrovps-actionbar"' . $lockAttr . '>'
        . $power
        . '<button type="button" class="metrovps-btn metrovps-btn--primary" data-metrovps-action="restart" data-serviceid="' . $serviceId . '"><i class="fas fa-sync-alt"></i> Restart</button>'
        . '<button type="button" class="metrovps-btn metrovps-btn--primary" data-metrovps-action="reinstall-modal" data-modal="#' . $modalId . '" data-serviceid="' . $serviceId . '"><i class="fas fa-redo-alt"></i> Reinstall</button>'
        . '<button type="button" class="metrovps-btn metrovps-btn--secondary" data-metrovps-action="resetpassword" data-serviceid="' . $serviceId . '"><i class="fas fa-key"></i> Reset Password</button>'
        . '</div>';
}

function metrovpsb2bWidgetModal(int $serviceId, string $modalId): string
{
    return '<div class="metrovps-modal" id="' . $modalId . '" role="dialog" aria-modal="true">'
        . '<div class="metrovps-modal-box">'
        . '<div class="metrovps-modal-head">'
        . '<h4><i class="fas fa-redo-alt"></i> Reinstall VPS</h4>'
        . '<button type="button" class="metrovps-modal-close" aria-label="Close">&times;</button>'
        . '</div>'
        . '<div class="metrovps-modal-body">'
        . '<p>Choose the operating system to reinstall. <strong>All data on the VPS will be lost.</strong></p>'
        . '<select class="metrovps-select" data-metrovps-os-select><option value="">Loading operating systems…</option></select>'
        . '</div>'
        . '<div class="metrovps-modal-foot">'
        . '<button type="button" class="metrovps-btn metrovps-btn--secondary metrovps-modal-cancel">Cancel</button>'
        . '<button type="button" class="metrovps-btn metrovps-btn--danger" data-metrovps-action="reinstall" data-serviceid="' . $serviceId . '">'
        . '<i class="fas fa-redo-alt"></i> Reinstall</button>'
        . '</div>'
        . '</div>'
        . '</div>';
}

function metrovpsb2bOverviewWidget(int $serviceId): string
{
    $record = WHMCS\Module\Server\MetroVPSB2B\Database::getByServiceId($serviceId);

    $vpsData = [];
    if ($record && !empty($record->provision_response)) {
        $decoded = json_decode($record->provision_response, true);
        if (is_array($decoded)) {
            $vpsData = $decoded;
        }
    }

    $hosting = DB::table('tblhosting')
        ->where('id', $serviceId)
        ->first(['domain', 'dedicatedip', 'packageid']);

    if (!$hosting) {
        return '';
    }

    $productName = (string) (DB::table('tblproducts')->where('id', (int) $hosting->packageid)->value('name') ?? '');

    $hostname = (string) ($hosting->domain ?? '');
    $ip       = (string) ($hosting->dedicatedip ?? '');
    $osName   = $vpsData['os_name'] ?? '';
    $kernel   = $vpsData['os_kernel'] ?? '';
    $pkgTitle = is_array($vpsData['package'] ?? null) ? ($vpsData['package']['title'] ?? '') : '';
    $modalId      = 'metrovps-modal-overview-' . $serviceId;
    $isSuspended  = ($record->status ?? '') === 'suspended';

    $facts = '';

    if ($ip !== '') {
        $facts .= '<div class="metrovps-fact"><span class="metrovps-fact-label">IPv4</span>'
            . '<span class="metrovps-chip"><span class="metrovps-chip-value">' . metrovpsb2bWidgetEsc($ip) . '</span>'
            . '<button type="button" class="metrovps-copy" data-metrovps-copy data-copy-value="' . metrovpsb2bWidgetEsc($ip) . '" title="Copy"><i class="far fa-copy"></i></button>'
            . '</span></div>';
    }

    if ($osName !== '' && $osName !== null) {
        $facts .= '<div class="metrovps-fact"><span class="metrovps-fact-label">Operating System</span>'
            . '<span class="metrovps-fact-value">' . metrovpsb2bWidgetOsIcon($osName, $vpsData['os_icon'] ?? null)
            . '<span class="metrovps-os-name">' . metrovpsb2bWidgetEsc($osName) . '</span>'
            . ($kernel !== '' && $kernel !== null ? ' <span class="metrovps-kernel">' . metrovpsb2bWidgetEsc($kernel) . '</span>' : '')
            . '</span></div>';
    }

    if ($pkgTitle !== '') {
        $facts .= '<div class="metrovps-fact"><span class="metrovps-fact-label">Package</span>'
            . '<span class="metrovps-fact-value">' . metrovpsb2bWidgetEsc($pkgTitle) . '</span></div>';
    }

    $html  = '<link rel="stylesheet" href="/modules/servers/MetroVPSB2B/assets/css/metrovps-detail.css?v=11">';
    $html .= '<script src="/modules/servers/MetroVPSB2B/assets/js/metrovps-clientarea.js?v=10"></script>';
    $html .= '<div class="metrovps-overview">';
    $html .= '<div class="metrovps-overview-head">'
        . metrovpsb2bWidgetOsIcon($osName, $vpsData['os_icon'] ?? null)
        . '<div class="metrovps-overview-title">'
        . metrovpsb2bWidgetEsc($hostname !== '' ? $hostname : $productName)
        . ($hostname !== '' && $productName !== '' ? ' <span class="metrovps-overview-sub">' . metrovpsb2bWidgetEsc($productName) . '</span>' : '')
        . '</div>'
        . '<div class="metrovps-overview-badges">'
        . metrovpsb2bWidgetBadge($record->status ?? null)
        . ($isSuspended ? '' : ' ' . metrovpsb2bWidgetPowerBadge($vpsData))
        . '</div>'
        . '</div>';

    if ($facts !== '') {
        $html .= '<div class="metrovps-overview-facts">' . $facts . '</div>';
    }

    if ($isSuspended) {
        $html .= '<div class="metrovps-actionbar">'
            . '<span class="metrovps-suspended-note"><i class="fas fa-pause-circle"></i> Service suspended — actions are unavailable until this service is unsuspended.</span>'
            . '</div>';
        $html .= '</div>';

        return $html;
    }

    $lockSeconds        = (new WHMCS\Module\Server\MetroVPSB2B\Module())->powerLockRemaining($record->power_action_at ?? null);
    $rebuildLockSeconds = (new WHMCS\Module\Server\MetroVPSB2B\Module())->rebuildLockRemaining($record->locked_until ?? null);

    $html .= metrovpsb2bWidgetActions($serviceId, $vpsData, $modalId, $lockSeconds, $rebuildLockSeconds);
    $html .= '</div>';
    $html .= metrovpsb2bWidgetModal($serviceId, $modalId);

    return $html;
}

add_hook('ClientAreaPageProductDetails', 1, function ($vars) {
    $serviceId = (int) ($_REQUEST['id'] ?? 0);

    if ($serviceId <= 0) {
        return $vars;
    }

    $hosting = DB::table('tblhosting')->where('id', $serviceId)->first(['id', 'packageid']);

    if (!$hosting || !$hosting->packageid) {
        return $vars;
    }

    $isMetro = DB::table('tblproducts')
        ->where('id', (int) $hosting->packageid)
        ->where('servertype', 'MetroVPSB2B')
        ->exists();

    if (!$isMetro) {
        return $vars;
    }

    // Refresh VPS data when the stored copy is older than the TTL. Runs
    // before the widget and the module client area render, so both surfaces
    // pick up fresh data on the same page load.
    (new WHMCS\Module\Server\MetroVPSB2B\Module())->clientSyncIfStale($serviceId);

    $widget = metrovpsb2bOverviewWidget($serviceId);

    if ($widget !== '') {
        if (!isset($vars['hookOutput']) || !is_array($vars['hookOutput'])) {
            $vars['hookOutput'] = [];
        }

        $vars['hookOutput'][] = $widget;
    }

    return $vars;
});

/* Sidebar custom action buttons were removed — all VPS actions live on the
 * dashboard/Overview action bars (client.php AJAX endpoint). */
