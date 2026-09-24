<?php

/**
 * MetroVPS B2B — Callback / Webhook Endpoint
 *
 * MetroVPS calls this URL when a VPS provisioning event occurs.
 * The provision payload includes the webhook_url automatically, so
 * no manual configuration is required.
 *
 * Expected GET parameters:
 *   event          "vps.activated" (sync + send welcome email)
 *                  "vps.updated"   (sync only, no email)
 *                  "vps.rebuilt"   (sync + email new credentials, clear rebuild lock)
 *   service_id     MetroVPS service ID
 *   b2b_order_id   MetroVPS B2B order ID
 */

require dirname(__DIR__, 3) . '/init.php';

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Module.php';

use WHMCS\Database\Capsule as DB;
use WHMCS\Module\Server\MetroVPSB2B\ApiClient;
use WHMCS\Module\Server\MetroVPSB2B\Database;
use WHMCS\Module\Server\MetroVPSB2B\Module;

// Ensure the table schema exists.
Database::schema();

// --- Parse request ----------------------------------------------------------

$event      = $_GET['event'] ?? '';
$serviceId  = (int) ($_GET['service_id'] ?? 0);
$b2bOrderId = (int) ($_GET['b2b_order_id'] ?? 0);

if ($b2bOrderId <= 0 || $event === '') {
    http_response_code(400);
    echo json_encode(['status' => 400, 'message' => 'Missing required parameters: event, b2b_order_id']);
    exit;
}

if (!in_array($event, ['vps.activated', 'vps.updated', 'vps.rebuilt'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 400, 'message' => 'Unknown event: ' . $event]);
    exit;
}

logModuleCall('MetroVPSB2B', 'callback:' . $event, $_GET, 'Webhook received.', '');

// --- Look up WHMCS service --------------------------------------------------

$record = Database::getByB2bOrderId($b2bOrderId);

if (!$record || !$record->service_id) {
    logModuleCall('MetroVPSB2B', 'callback:error', $_GET, 'No WHMCS service found for b2b_order_id ' . $b2bOrderId, '');
    http_response_code(404);
    echo json_encode(['status' => 404, 'message' => 'No service found for this B2B order ID.']);
    exit;
}

$whmcsServiceId = (int) $record->service_id;

// --- Fetch VPS details from MetroVPS ----------------------------------------

$module = new Module();
$credentials = $module->getServerCredentialsFromDb();

if (!$credentials) {
    logModuleCall('MetroVPSB2B', 'callback:error', $_GET, 'No active MetroVPS B2B server found.', '');
    http_response_code(500);
    echo json_encode(['status' => 500, 'message' => 'No active MetroVPS B2B server configured.']);
    exit;
}

$client = new ApiClient($credentials['base_url'], $credentials['api_key'], $credentials['api_secret']);
$result = $client->getVpsDetails($serviceId, $b2bOrderId);

logModuleCall(
    'MetroVPSB2B',
    'callback:getVpsDetails',
    ['service_id' => $serviceId, 'b2b_order_id' => $b2bOrderId],
    $result,
    $result['success'] ? 'success' : 'error'
);

if (!$result['success'] || empty($result['data'])) {
    http_response_code(502);
    echo json_encode(['status' => 502, 'message' => 'Failed to fetch VPS details: ' . $result['error']]);
    exit;
}

$vpsData = $result['data'];

// --- Update mod_metrovpsb2b -------------------------------------------------

Database::updateCallbackResult($whmcsServiceId, $vpsData);

// --- Update WHMCS tblhosting fields -----------------------------------------

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
    try {
        DB::table('tblhosting')->where('id', $whmcsServiceId)->update($hostingUpdate);
        logModuleCall('MetroVPSB2B', 'callback:updateHosting', ['service_id' => $whmcsServiceId], $hostingUpdate, '');
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', 'callback:updateHosting:error', ['service_id' => $whmcsServiceId], $e->getMessage(), '');
    }
}

// --- Event-specific handling ------------------------------------------------

// vps.activated: the initial welcome email goes out now that the VPS is live.
if ($event === 'vps.activated') {
    try {
        $emailResult = localAPI('SendEmail', [
            'messagename' => 'Hosting Account Welcome Email',
            'id'          => $whmcsServiceId,
        ]);

        logModuleCall(
            'MetroVPSB2B',
            'callback:sendEmail',
            ['service_id' => $whmcsServiceId],
            $emailResult,
            $emailResult['result'] === 'success' ? 'sent' : 'failed'
        );
    } catch (Exception $e) {
        logModuleCall('MetroVPSB2B', 'callback:sendEmail:error', ['service_id' => $whmcsServiceId], $e->getMessage(), '');
    }
}

// vps.rebuilt: the reinstall finished — release the rebuild lock and email
// the client their new root password and access details.
if ($event === 'vps.rebuilt') {
    Database::setLockedUntil($whmcsServiceId);
    $module->sendRebuiltCredentialsEmail($whmcsServiceId, $vpsData);
}

// --- Respond -----------------------------------------------------------------

http_response_code(200);
echo json_encode([
    'status'  => 200,
    'message' => 'Callback processed successfully.',
    'data'    => [
        'whmcs_service_id' => $whmcsServiceId,
        'event'            => $event,
        'email_sent'       => in_array($event, ['vps.activated', 'vps.rebuilt'], true),
    ],
]);