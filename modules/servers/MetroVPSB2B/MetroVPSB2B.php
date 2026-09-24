<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/Module.php';
require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/Database.php';

use WHMCS\Module\Server\MetroVPSB2B\ApiClient;
use WHMCS\Module\Server\MetroVPSB2B\Module;
use WHMCS\Module\Server\MetroVPSB2B\Database;
use WHMCS\Database\Capsule as DB;

// Ensure the provision-results table exists. Idempotent — safe to call on every page load.
Database::schema();

/**
 * Suppress the automatic welcome email for MetroVPS B2B services.
 *
 * WHMCS sends the "Hosting Account Welcome Email" immediately after
 * CreateAccount returns 'success', but the VPS isn't ready yet — it has
 * no IP, no root password. We block it here and the callback.php webhook
 * sends it once the vps.activated event confirms the VPS is live.
 */
add_hook('EmailPreSend', 1, function ($vars) {
    $messageName = $vars['messagename'] ?? '';
    $relid = (int) ($vars['relid'] ?? 0);

    if ($messageName !== 'Hosting Account Welcome Email' || $relid <= 0) {
        return $vars;
    }

    $isMetro = DB::table('tblhosting')
        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->where('tblhosting.id', $relid)
        ->where('tblproducts.servertype', 'MetroVPSB2B')
        ->exists();

    if ($isMetro) {
        return ['abortsend' => true];
    }

    return $vars;
});

function MetroVPSB2B_MetaData()
{
    return [
        'DisplayName' => 'MetroVPS B2B',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'ServiceSingleSignOnLabel' => false,
        'AdminSingleSignOnLabel' => false,
    ];
}

function MetroVPSB2B_ConfigOptions()
{
    $options = ['' => 'Select Package'];

    try {
        $module = new Module();
        $products = $module->getProducts();

        foreach ($products as $product) {
            $id = $product['id'] ?? '';

            if ($id === '') {
                continue;
            }

            $options[$id] = $module->formatProductLabel($product);
        }
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, [], $e->getMessage(), $e->getTraceAsString());
    }

    // Build product metadata for client-side detail cards
    $metadataScript = MetroVPSB2B_ProductMetadataScript();
    $detailsScript  = MetroVPSB2B_DetailsScript();

    return [
        'packageId' => [
            'FriendlyName' => 'Package',
            'Type' => 'dropdown',
            'Options' => $options,
            'Loader' => 'MetroVPSB2B_PackageLoader',
            'SimpleMode' => true,
            'Description' => $metadataScript . $detailsScript . 'Select a MetroVPS B2B package.',
            'Default' => '',
        ],
    ];
}

/**
 * Embed product metadata as JSON for client-side detail cards.
 */
function MetroVPSB2B_ProductMetadataScript(): string
{
    try {
        $module = new Module();
        $products = $module->getProducts();
        $metadata = [];

        foreach ($products as $product) {
            if (!empty($product['id'])) {
                $metadata[$product['id']] = array_map(
                    function ($v) { return (string) $v; },
                    $product
                );
            }
        }

        if (!empty($metadata)) {
            return '<script id="metrovps-product-metadata" type="application/json">' . htmlspecialchars(json_encode($metadata), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</script>\n";
        }
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, [], $e->getMessage(), '');
    }

    return '';
}

/**
 * Load the MetroVPS Package Detail card JavaScript.
 */
function MetroVPSB2B_DetailsScript(): string
{
    return "<script src=\"/modules/servers/MetroVPSB2B/assets/js/metrovps-package-detail.js\" defer></script>\n";
}

/**
 * WHMCS Loader function for the Package dropdown.
 *
 * @param array $params
 * @return array
 * @throws Exception
 */
function MetroVPSB2B_PackageLoader(array $params)
{
    try {
        $module = new Module($params);
        $products = $module->getProducts();
        $options = ['' => 'Select Package'];

        foreach ($products as $product) {
            $id = $product['id'] ?? '';

            if ($id === '') {
                continue;
            }

            $options[$id] = $module->formatProductLabel($product);
        }

        logModuleCall('MetroVPSB2B', __FUNCTION__, $params, 'Loaded ' . count($options) . ' packages.', '');

        return $options;
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        throw $e;
    }
}

/**
 * Client area output for a MetroVPS B2B service.
 *
 * WHMCS assigns the returned string to $moduleclientarea, which the active
 * template renders inside the "Server Information" tab.
 *
 * Shows three sections: service status, server access details (IPv4, hostname,
 * root password, OS, power state), and package specs. All data comes from the
 * local database — no outbound API call on page load.
 *
 * @param array $params
 * @return string HTML
 */
function MetroVPSB2B_ClientArea(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);

    // Refresh VPS data when the stored copy is older than the TTL. The
    // product-details hook usually runs first, so this is normally a cheap
    // no-op; it covers renders that skip the hook.
    if ($serviceId > 0) {
        try {
            (new Module($params))->clientSyncIfStale($serviceId);
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', __FUNCTION__ . ':sync', ['service_id' => $serviceId], $e->getMessage(), '');
        }
    }

    // --- Package specs (existing snapshot logic) ---
    $packageId   = (string) ($params['configoption1'] ?? '');
    $productName = '';
    $product     = null;

    $whmcsProductId = (int) ($params['pid'] ?? ($params['packageid'] ?? 0));

    if ($whmcsProductId > 0) {
        try {
            $whmcsProduct = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $whmcsProductId)
                ->first(['name', 'configoption1']);

            if ($whmcsProduct) {
                $productName = (string) $whmcsProduct->name;

                if ($packageId === '') {
                    $packageId = (string) $whmcsProduct->configoption1;
                }
            }
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', __FUNCTION__, ['product_id' => $whmcsProductId], $e->getMessage(), '');
        }
    }

    if ($packageId !== '') {
        try {
            $module  = new Module($params);
            $product = $module->getSavedProduct($packageId);

            if ($product === null) {
                foreach ($module->getProducts() as $candidate) {
                    if ((string) ($candidate['id'] ?? '') === $packageId) {
                        $product = $candidate;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', __FUNCTION__, ['package_id' => $packageId], $e->getMessage(), $e->getTraceAsString());
        }
    }

    // --- Provision record ---
    $provisionRecord = null;
    $vpsData = [];

    if ($serviceId > 0) {
        try {
            $provisionRecord = Database::getByServiceId($serviceId);

            if ($provisionRecord && !empty($provisionRecord->provision_response)) {
                $decoded = json_decode($provisionRecord->provision_response, true);
                if (is_array($decoded)) {
                    $vpsData = $decoded;
                }
            }
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', __FUNCTION__, ['service_id' => $serviceId], $e->getMessage(), '');
        }
    }

    // --- Hosting data (IP, password, username, hostname) ---
    $hostingData = [];
    if ($serviceId > 0) {
        try {
            $hosting = \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->first(['dedicatedip', 'username', 'password', 'domain']);

            if ($hosting) {
                $hostingData = [
                    'ip'       => $hosting->dedicatedip ?? '',
                    'username' => $hosting->username ?? '',
                    'password' => $hosting->password ? MetroVPSB2B_safeDecrypt($hosting->password) : '',
                    'hostname' => $hosting->domain ?? '',
                ];
            }
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', __FUNCTION__, ['service_id' => $serviceId], $e->getMessage(), '');
        }
    }

    // --- Nothing to show ---
    if (empty($product) && empty($hostingData) && empty($vpsData) && !$provisionRecord) {
        return '';
    }

    return MetroVPSB2B_ClientAreaHtml($serviceId, $product, $provisionRecord, $vpsData, $hostingData, $productName, 'metrovps-modal-manage');
}

/**
 * Safely decrypt a WHMCS-encrypted value.
 *
 * @param string $value
 * @return string
 */
function MetroVPSB2B_safeDecrypt(string $value): string
{
    $decrypted = @decrypt($value);

    if (!empty($decrypted) && preg_match('/^[\x20-\x7E]+$/', $decrypted) === 1) {
        return $decrypted;
    }

    return $value;
}

/**
 * Build the full client area HTML card with status, server access, and package specs.
 *
 * @param array|null  $product         MetroVPS package snapshot
 * @param object|null $provisionRecord mod_metrovpsb2b row
 * @param array       $vpsData         Decoded provision_response JSON
 * @param array       $hostingData     tblhosting fields
 * @param string      $heading         Card heading
 * @return string
 */
/**
 * Build the full client area dashboard: header, quick actions, data panels,
 * actions panel and reinstall modal.
 *
 * @param int         $serviceId       WHMCS service id
 * @param array|null  $product         MetroVPS package snapshot
 * @param object|null $provisionRecord mod_metrovpsb2b row
 * @param array       $vpsData         Decoded provision_response JSON
 * @param array       $hostingData     tblhosting fields (ip, username, password, hostname)
 * @param string      $heading         Product name
 * @param string      $modalPrefix     Unique prefix for the reinstall modal id
 * @return string
 */
function MetroVPSB2B_ClientAreaHtml(int $serviceId, ?array $product, ?object $provisionRecord, array $vpsData, array $hostingData, string $heading = '', string $modalPrefix = 'metrovps-modal'): string
{
    $title = $heading !== ''
        ? $heading
        : MetroVPSB2B_FirstValue($product ?? [], ['product_title', 'title']);

    $modalId  = $modalPrefix . '-' . $serviceId;
    $hostname = $hostingData['hostname'] ?? '';
    $isSuspended = ($provisionRecord->status ?? '') === 'suspended';
    $isTerminated = ($provisionRecord->status ?? '') === 'terminated';

    // Package figures: prefer the synced vps-details package block, fall
    // back to the (older) product snapshot.
    $pkg    = is_array($vpsData['package'] ?? null) ? $vpsData['package'] : [];
    $cpu    = MetroVPSB2B_FirstValue($pkg, ['cpu_core']) ?: MetroVPSB2B_FirstValue($product ?? [], ['cpu_core', 'vcpu_cores']);
    $ramMb  = MetroVPSB2B_FirstValue($pkg, ['memory_mb']) ?: '';
    $diskGb = MetroVPSB2B_FirstValue($pkg, ['storage_gb']) ?: MetroVPSB2B_FirstValue($product ?? [], ['storage_gb', 'disk_gb']);
    $trafGb = MetroVPSB2B_FirstValue($pkg, ['traffic_gb']) ?: MetroVPSB2B_FirstValue($product ?? [], ['traffic_gb', 'bandwidth_gb', 'network_bandwidth_gb']);
    $ipv4N  = MetroVPSB2B_FirstValue($pkg, ['ipv4_count']) ?: MetroVPSB2B_FirstValue($product ?? [], ['ipv4_count', 'ipv4', 'ip_count']);

    $html  = '<link rel="stylesheet" href="/modules/servers/MetroVPSB2B/assets/css/metrovps-detail.css?v=11">';
    $html .= '<script src="/modules/servers/MetroVPSB2B/assets/js/metrovps-clientarea.js?v=10"></script>';
    $html .= '<div class="metrovps-dashboard">';

    // --- Header ---------------------------------------------------------
    $html .= '<div class="metrovps-head">';
    $html .= '<div class="metrovps-head-main">'
        . MetroVPSB2B_OsIconHtml($vpsData['os_name'] ?? null, $vpsData['os_icon'] ?? null, 'metrovps-os-icon--head')
        . '<div class="metrovps-head-text">'
        . '<div class="metrovps-head-title">' . MetroVPSB2B_Escape($hostname !== '' ? $hostname : $title) . '</div>';

    if ($title !== '' && $hostname !== '') {
        $html .= '<div class="metrovps-head-sub">' . MetroVPSB2B_Escape($title) . '</div>';
    }

    $html .= '</div></div>';

    $html .= '<div class="metrovps-head-badges">'
        . MetroVPSB2B_StatusBadge($provisionRecord->status ?? null)
        . (($isSuspended || $isTerminated) ? '' : ' ' . MetroVPSB2B_PowerBadge($vpsData))
        . '</div></div>';

    // --- Quick actions ---------------------------------------------------
    if ($isSuspended) {
        $html .= '<div class="metrovps-actionbar">'
            . '<span class="metrovps-suspended-note"><i class="fas fa-pause-circle"></i> Service suspended — actions are unavailable until this service is unsuspended.</span>'
            . '</div>';
    } elseif ($isTerminated) {
        $html .= '<div class="metrovps-actionbar">'
            . '<span class="metrovps-suspended-note"><i class="fas fa-ban"></i> Service terminated — no further actions are available.</span>'
            . '</div>';
    } else {
        $lockSeconds        = (new Module())->powerLockRemaining($provisionRecord->power_action_at ?? null);
        $rebuildLockSeconds = (new Module())->rebuildLockRemaining($provisionRecord->locked_until ?? null);
        $html .= MetroVPSB2B_ActionBar($serviceId, $vpsData, $modalId, $lockSeconds, $rebuildLockSeconds);
    }

    // --- Panels ----------------------------------------------------------
    $html .= '<div class="metrovps-panels">';

    // Operating System — first, full row
    $osRows = '';
    $osName = $vpsData['os_name'] ?? '';
    if ($osName !== '' && $osName !== null) {
        $osRows .= MetroVPSB2B_Row('Operating System',
            MetroVPSB2B_OsIconHtml($osName, $vpsData['os_icon'] ?? null, 'metrovps-os-icon--row')
            . '<span class="metrovps-os-name">' . MetroVPSB2B_Escape($osName) . '</span>');
    }

    $kernel = $vpsData['os_kernel'] ?? '';
    if ($kernel !== '' && $kernel !== null) {
        $osRows .= MetroVPSB2B_Row('Kernel', '<code class="metrovps-code">' . MetroVPSB2B_Escape($kernel) . '</code>');
    }

    if ($osRows !== '') {
        $html .= MetroVPSB2B_Panel('Operating System', '<i class="fab fa-linux metrovps-panel-icon"></i>', $osRows, 'metrovps-panel--full');
    }

    // Server Information
    $rows = '';
    if (!empty($hostingData['ip'])) {
        $rows .= MetroVPSB2B_Row('IPv4 Address', MetroVPSB2B_CopyChip($hostingData['ip'], 'metrovps-ip'));
    }

    $addIps = $vpsData['additional_ipv4'] ?? [];
    if (is_array($addIps) && !empty(array_filter($addIps))) {
        $rows .= MetroVPSB2B_Row('Additional IPv4', MetroVPSB2B_Escape(implode(', ', array_filter(array_map('strval', $addIps)))));
    }

    $natIp = $vpsData['nat_ip'] ?? '';
    if ($natIp !== '' && $natIp !== null) {
        $rows .= MetroVPSB2B_Row('NAT IP', MetroVPSB2B_CopyChip($natIp));
    }

    if (!empty($hostingData['username'])) {
        $rows .= MetroVPSB2B_Row('Username', '<span class="metrovps-value">' . MetroVPSB2B_Escape($hostingData['username']) . '</span>');
    }

    if (!empty($hostingData['password'])) {
        $rows .= MetroVPSB2B_Row('Root Password', MetroVPSB2B_PasswordControl($hostingData['password']));
    }

    $sshTarget = !empty($hostingData['ip']) ? $hostingData['ip'] : $hostname;
    if ($sshTarget !== '') {
        $rows .= MetroVPSB2B_Row('SSH', '<code class="metrovps-code">ssh root@' . MetroVPSB2B_Escape($sshTarget) . '</code>');
    }

    if (!$isTerminated && $rows !== '') {
        $html .= MetroVPSB2B_Panel('Server Information', '<i class="fas fa-server metrovps-panel-icon"></i>', $rows);
    }

    // Package
    $pkgRows = '';
    if ($cpu !== '') {
        $pkgRows .= MetroVPSB2B_Row('vCPU Cores', '<span class="metrovps-value">' . MetroVPSB2B_Escape($cpu) . '</span>');
    }
    if ($ramMb !== '') {
        $pkgRows .= MetroVPSB2B_Row('Memory', '<span class="metrovps-value">' . MetroVPSB2B_Escape(number_format((float) $ramMb / 1024, 1)) . ' GB</span>');
    }
    if ($diskGb !== '') {
        $pkgRows .= MetroVPSB2B_Row('Storage', '<span class="metrovps-value">' . MetroVPSB2B_Escape($diskGb) . ' GB</span>');
    }
    if ($trafGb !== '') {
        $pkgRows .= MetroVPSB2B_Row('Bandwidth', '<span class="metrovps-value">' . MetroVPSB2B_Escape($trafGb) . ' GB</span>');
    }
    if ($ipv4N !== '') {
        $pkgRows .= MetroVPSB2B_Row('IPv4 Count', '<span class="metrovps-value">' . MetroVPSB2B_Escape($ipv4N) . '</span>');
    }

    $location = MetroVPSB2B_FirstValue($product ?? [], ['product_location', 'location']);
    if ($location !== '') {
        $pkgRows .= MetroVPSB2B_Row('Location', '<span class="metrovps-value">' . MetroVPSB2B_Escape($location) . '</span>');
    }

    if ($pkgRows === '') {
        $pkgRows = '<div class="metrovps-empty">Package information will appear after the first sync.</div>';
    }

    $html .= MetroVPSB2B_Panel('Package', '<i class="fas fa-cubes metrovps-panel-icon"></i>', $pkgRows);

    $html .= '</div>'; // .metrovps-panels

    $html .= '</div>'; // .metrovps-dashboard

    // --- Reinstall modal ---------------------------------------------------
    if (!$isSuspended && !$isTerminated) {
        $html .= MetroVPSB2B_ReinstallModal($serviceId, $modalId);
    }

    return $html;
}

/**
 * Render the quick-actions row: power toggle, restart, reinstall, reset password.
 *
 * @param int    $serviceId
 * @param array  $vpsData
 * @param string $modalId
 * @param int    $lockSeconds Seconds left on the power-action cooldown, 0 when unlocked
 * @return string
 */
function MetroVPSB2B_ActionBar(int $serviceId, array $vpsData, string $modalId, int $lockSeconds = 0, int $rebuildLockSeconds = 0): string
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

/**
 * Render the reinstall modal with an OS template dropdown (loaded via AJAX).
 *
 * @param int    $serviceId
 * @param string $modalId
 * @return string
 */
function MetroVPSB2B_ReinstallModal(int $serviceId, string $modalId): string
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

/**
 * Render one data panel with an icon heading.
 *
 * @param string      $title
 * @param string      $iconHtml
 * @param string      $bodyHtml
 * @param string|null $extraClass  e.g. "metrovps-panel--full"
 * @return string
 */
function MetroVPSB2B_Panel(string $title, string $iconHtml, string $bodyHtml, ?string $extraClass = null): string
{
    $class = 'metrovps-panel' . ($extraClass !== null && $extraClass !== '' ? ' ' . $extraClass : '');

    return '<div class="' . $class . '">'
        . '<div class="metrovps-panel-head"><h4>' . $iconHtml . ' ' . MetroVPSB2B_Escape($title) . '</h4></div>'
        . '<div class="metrovps-panel-body">' . $bodyHtml . '</div>'
        . '</div>';
}

/**
 * Render a label/value row inside a panel.
 *
 * @param string $label
 * @param string $valueHtml Ready-made HTML
 * @return string
 */
function MetroVPSB2B_Row(string $label, string $valueHtml): string
{
    return '<div class="metrovps-row">'
        . '<div class="metrovps-row-label">' . MetroVPSB2B_Escape($label) . '</div>'
        . '<div class="metrovps-row-value">' . $valueHtml . '</div>'
        . '</div>';
}

/**
 * Render a value chip with a copy button.
 *
 * @param string      $value
 * @param string|null $extraClass
 * @return string
 */
function MetroVPSB2B_CopyChip(string $value, ?string $extraClass = null): string
{
    return '<span class="metrovps-chip' . ($extraClass ? ' ' . $extraClass : '') . '">'
        . '<span class="metrovps-chip-value">' . MetroVPSB2B_Escape($value) . '</span>'
        . '<button type="button" class="metrovps-copy" data-metrovps-copy data-copy-value="' . MetroVPSB2B_Escape($value) . '" title="Copy">'
        . '<i class="far fa-copy"></i></button>'
        . '</span>';
}

/**
 * Render the masked root password control with reveal + copy.
 *
 * @param string $password Plain password
 * @return string
 */
function MetroVPSB2B_PasswordControl(string $password): string
{
    $spanId     = 'metrovps-pw-' . uniqid();
    $passwordJs = json_encode($password, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

    // The JSON string carries literal double quotes as delimiters. HTML-escape
    // the whole value so the attribute does not terminate mid-string; the
    // browser decodes the entities back before the JS reads getAttribute(),
    // handing JSON.parse() a valid payload.
    $passwordAttr = MetroVPSB2B_Escape($passwordJs);

    return '<span class="metrovps-password">'
        . '<span id="' . $spanId . '" class="metrovps-password-value" data-password="' . $passwordAttr . '">' . str_repeat('&bull;', 8) . '</span>'
        . '<button type="button" class="metrovps-copy metrovps-copy--password" data-metrovps-toggle-password data-target="' . $spanId . '" title="Reveal / hide">'
        . '<i class="far fa-eye metrovps-password-eye"></i></button>'
        . '<button type="button" class="metrovps-copy" data-metrovps-copy data-copy-value="' . MetroVPSB2B_Escape($password) . '" title="Copy password">'
        . '<i class="far fa-copy"></i></button>'
        . '</span>';
}

/**
 * Choose the OS logo: remote os_icon URL when safe, otherwise a local
 * fallback SVG matched on the OS name.
 *
 * @param string|null $osName
 * @param string|null $osIcon
 * @param string      $class
 * @return string
 */
function MetroVPSB2B_OsIconHtml(?string $osName, ?string $osIcon, string $class = 'metrovps-os-icon'): string
{
    $src = '';
    $alt = $osName !== null && $osName !== '' ? $osName : 'Operating System';

    if ($osIcon !== null && $osIcon !== '' && preg_match('#^https?://#i', $osIcon)) {
        $src = MetroVPSB2B_Escape($osIcon);
    } else {
        $local = MetroVPSB2B_OsFallbackIcon($osName ?? '');
        $src   = '/modules/servers/MetroVPSB2B/assets/img/os/' . $local . '.svg';
    }

    return '<img class="' . MetroVPSB2B_Escape($class) . '" src="' . $src . '" alt="' . MetroVPSB2B_Escape($alt) . '" title="' . MetroVPSB2B_Escape($alt) . '">';
}

/**
 * Map an OS name to a local fallback icon slug.
 *
 * @param string $osName
 * @return string
 */
function MetroVPSB2B_OsFallbackIcon(string $osName): string
{
    $name = strtolower($osName);

    $map = [
        'ubuntu'       => 'ubuntu',
        'debian'       => 'debian',
        'centos'       => 'centos',
        'rocky'        => 'rockylinux',
        'alma'         => 'almalinux',
        'fedora'       => 'fedora',
        'windows'      => 'windows',
    ];

    foreach ($map as $keyword => $slug) {
        if (strpos($name, $keyword) !== false) {
            return $slug;
        }
    }

    return 'generic';
}

/**
 * Render a service status badge.
 *
 * @param string|null $status
 * @return string
 */
function MetroVPSB2B_StatusBadge(?string $status): string
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
            return '<span class="metrovps-badge metrovps-badge--unknown" data-metrovps-lifecycle-badge data-state="' . ($status !== null && $status !== '' ? MetroVPSB2B_Escape($status) : '') . '">'
                . ($status !== null && $status !== '' ? MetroVPSB2B_Escape($status) : 'Unknown')
                . '</span>';
    }
}

/**
 * Render a power state badge strictly from remote_state — this badge owns
 * the transient states (starting, restarting, stopping, installing).
 *
 * @param array $vpsData
 * @return string
 */
function MetroVPSB2B_PowerBadge(array $vpsData): string
{
    $state = $vpsData['remote_state'] ?? null;
    $stateAttr = $state !== null && $state !== '' ? ' data-state="' . MetroVPSB2B_Escape($state) . '"' : '';

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

/**
 * Return the first populated value for any of the given keys.
 *
 * The MetroVPS API has returned these fields under more than one name, so
 * each spec looks up a list of candidates.
 *
 * @param array $product
 * @param array $keys
 * @return string Empty string when none of the keys hold a value.
 */
function MetroVPSB2B_FirstValue(array $product, array $keys): string
{
    foreach ($keys as $key) {
        if (!isset($product[$key])) {
            continue;
        }

        $value = $product[$key];

        // A key can hold a list rather than a scalar — e.g. a list of IP
        // addresses. Those do not fit a single cell, so skip to the next
        // candidate key instead of casting an array to the string "Array".
        if ((is_int($value) || is_float($value) || is_string($value)) && $value !== '') {
            return (string) $value;
        }
    }

    return '';
}

/**
 * Append a unit to a value, unless the value is empty.
 *
 * @param string $value
 * @param string $unit
 * @return string
 */
function MetroVPSB2B_WithUnit(string $value, string $unit): string
{
    return $value === '' ? '' : $value . ' ' . $unit;
}

/**
 * Escape a value for safe output inside HTML.
 *
 * @param string $value
 * @return string
 */
function MetroVPSB2B_Escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function MetroVPSB2B_TestConnection(array $params)
{
    try {
        $module = new Module($params);
        $client = $module->getApiClient();

        if (!$client) {
            throw new Exception('Unable to initialize MetroVPS API client. Check hostname, API Key, and API Secret.');
        }

        $result = $client->test();

        if ($result['success']) {
            $name = $result['data']['name'] ?? '';
            $email = $result['data']['email'] ?? '';
            $message = 'Connection successful.';

            if ($name || $email) {
                $message .= ' Authenticated as ' . trim($name . ' <' . $email . '>');
            }

            return ['success' => true, 'error' => $message];
        }

        throw new Exception($result['error']);
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function MetroVPSB2B_CreateAccount(array $params)
{
    try {
        $module = new Module($params);
        $error = $module->provisionService($params);

        if ($error !== '') {
            return $error;
        }

        return 'success';
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

function MetroVPSB2B_SuspendAccount(array $params)
{
    try {
        return (new Module($params))->suspendService($params);
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

function MetroVPSB2B_UnsuspendAccount(array $params)
{
    try {
        return (new Module($params))->unsuspendService($params);
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

function MetroVPSB2B_TerminateAccount(array $params)
{
    try {
        $serviceId = (int) ($params['serviceid'] ?? 0);

        if ($serviceId > 0) {
            Database::deleteByServiceId($serviceId);
        }

        return 'success';
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

function MetroVPSB2B_ChangePackage(array $params)
{
    logModuleCall('MetroVPSB2B', __FUNCTION__, $params, 'Stub invoked: package change logic not yet implemented.', '');
    return 'success';
}

/**
 * Return additional fields to display on the Module tab of the admin
 * service edit page. WHMCS calls this automatically when an admin views
 * a service assigned to the MetroVPSB2B server module.
 *
 * @param array $params
 * @return array  "Label" => "HTML" pairs
 */
function MetroVPSB2B_AdminServicesTabFields(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);

    if ($serviceId <= 0) {
        return [];
    }

    try {
        $module = new Module($params);
        return $module->getAdminTabFields($serviceId);
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return ['Error' => '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'];
    }
}

/**
 * Register custom buttons on the admin service edit page.
 *
 * WHMCS renders these in the "Module Commands" section of
 * clientsservices.php. Each key is a button label; each value is the
 * suffix of the handler function (MetroVPSB2B_{value}).
 *
 * @return array
 */
function MetroVPSB2B_AdminCustomButtonArray()
{
    return [
        "Sync VPS Details" => "syncVpsDetails",
        "Power On"         => "powerBoot",
        "Power Off"        => "powerShutdown",
        "Restart"          => "powerRestart",
        "Reset Password"   => "resetPasswordAdmin",
    ];
}

/**
 * Admin power action handlers — same MetroVPS power API as the client area.
 */
function MetroVPSB2B_powerBoot(array $params)
{
    return MetroVPSB2B_RunPowerAction('boot', $params);
}

function MetroVPSB2B_powerShutdown(array $params)
{
    return MetroVPSB2B_RunPowerAction('shutdown', $params);
}

function MetroVPSB2B_powerRestart(array $params)
{
    return MetroVPSB2B_RunPowerAction('restart', $params);
}

/**
 * Admin reset-password handler — resets the root password at MetroVPS and
 * emails the new password to the client.
 */
function MetroVPSB2B_resetPasswordAdmin(array $params)
{
    try {
        $serviceId = (int) ($params['serviceid'] ?? 0);

        if ($serviceId <= 0) {
            return 'Invalid service ID.';
        }

        return (new Module($params))->resetPasswordAction($serviceId);
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', 'resetPasswordAdmin', $params, $e->getMessage(), '');
        return $e->getMessage();
    }
}

/**
 * Shared runner for power actions from admin and client handlers.
 *
 * @param string $action 'boot', 'shutdown' or 'restart'
 * @param array  $params
 * @return string 'success' or an error message
 */
function MetroVPSB2B_RunPowerAction(string $action, array $params): string
{
    try {
        $serviceId = (int) ($params['serviceid'] ?? 0);

        if ($serviceId <= 0) {
            return 'Invalid service ID.';
        }

        return (new Module($params))->powerAction($action, $serviceId);
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', 'powerAction:' . $action, $params, $e->getMessage(), '');
        return $e->getMessage();
    }
}

/**
 * Sync VPS details from MetroVPS into WHMCS.
 *
 * Called when an admin clicks "Sync VPS Details" on the service edit page.
 * Fetches the latest VPS data (IP, password, status) from MetroVPS and
 * updates the WHMCS service fields.
 *
 * @param array $params
 * @return string  'success' or an error message displayed to the admin
 */
function MetroVPSB2B_syncVpsDetails(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);

    if ($serviceId <= 0) {
        return 'Invalid service ID.';
    }

    try {
        $record = Database::getByServiceId($serviceId);

        if (!$record || empty($record->b2b_order_id)) {
            return 'No provision record found. Provision the VPS first before syncing.';
        }

        $b2bOrderId     = (int) $record->b2b_order_id;
        $metroServiceId = (int) ($record->metro_service_id ?? 0);

        if ($metroServiceId <= 0) {
            return 'MetroVPS service ID not found in provision record. Cannot sync.';
        }

        $module = new Module();
        $credentials = $module->getServerCredentialsFromDb();

        if (!$credentials) {
            return 'No active MetroVPS B2B server found. Check server configuration.';
        }

        $client = new ApiClient(
            $credentials['base_url'],
            $credentials['api_key'],
            $credentials['api_secret']
        );

        $result = $client->getVpsDetails($metroServiceId, $b2bOrderId);

        logModuleCall(
            'MetroVPSB2B',
            __FUNCTION__,
            ['service_id' => $serviceId, 'metro_service_id' => $metroServiceId, 'b2b_order_id' => $b2bOrderId],
            $result,
            $result['success'] ? 'success' : 'error'
        );

        if (!$result['success'] || empty($result['data'])) {
            return 'Failed to fetch VPS details: ' . $result['error'];
        }

        $vpsData = $result['data'];

        Database::updateCallbackResult($serviceId, $vpsData);

        $hostingUpdate = [];
        $ipv4 = $vpsData['ipv4'] ?? null;

        if ($ipv4 !== null && $ipv4 !== '') {
            $hostingUpdate['dedicatedip'] = $ipv4;
        }

        $rootPassword = $vpsData['root_password'] ?? null;

        if ($rootPassword !== null && $rootPassword !== '') {
            $hostingUpdate['password'] = encrypt($rootPassword);
            $hostingUpdate['username'] = 'root';
        }

        $hostname = $vpsData['hostname'] ?? null;

        if ($hostname !== null && $hostname !== '') {
            $hostingUpdate['domain'] = $hostname;
        }

        if (!empty($hostingUpdate)) {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update($hostingUpdate);
        }

        return 'success';
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}
