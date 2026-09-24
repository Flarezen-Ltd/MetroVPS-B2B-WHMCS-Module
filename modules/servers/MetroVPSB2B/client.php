<?php

/**
 * MetroVPS B2B — Client Area Action Endpoint
 *
 * Handles AJAX requests from the client area dashboard
 * (assets/js/metrovps-clientarea.js).
 *
 * Actions:
 *   state           GET   Real: current lifecycle status + remote_state from DB
 *   ostemplates     GET   Real: list OS templates for the service's product
 *   poweron         POST  Real: MetroVPS power API (action=boot)
 *   poweroff        POST  Real: MetroVPS power API (action=shutdown)
 *   restart         POST  Real: MetroVPS power API (action=restart)
 *   reinstall       POST  Real: MetroVPS rebuild API (os_id param)
 *   resetpassword   POST  Real: MetroVPS reset-password API + email
 */

require dirname(__DIR__, 3) . '/init.php';

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Module.php';

use WHMCS\Database\Capsule as DB;
use WHMCS\Module\Server\MetroVPSB2B\Module;

// Ensure the table schema exists before queries run.
WHMCS\Module\Server\MetroVPSB2B\Database::schema();

function metrovps_client_output(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// --- Authentication ----------------------------------------------------------

$currentUser = new \WHMCS\Authentication\CurrentUser;

if (!$currentUser->isAuthenticatedUser()) {
    metrovps_client_output(['success' => false, 'error' => 'Not authenticated.'], 401);
}

$client = $currentUser->client();

if (!$client) {
    metrovps_client_output(['success' => false, 'error' => 'Not authenticated.'], 401);
}

$action    = (string) ($_REQUEST['action'] ?? '');
$serviceId = (int) ($_REQUEST['id'] ?? ($_REQUEST['serviceid'] ?? 0));

if ($action === '' || $serviceId <= 0) {
    metrovps_client_output(['success' => false, 'error' => 'Missing action or service ID.'], 400);
}

// --- Ownership check ---------------------------------------------------------

$hosting = DB::table('tblhosting')
    ->where('id', $serviceId)
    ->where('userid', (int) $client->id)
    ->first(['id', 'packageid']);

if (!$hosting) {
    metrovps_client_output(['success' => false, 'error' => 'Service not found.'], 404);
}

$product = DB::table('tblproducts')
    ->where('id', (int) $hosting->packageid)
    ->where('servertype', 'MetroVPSB2B')
    ->first(['id']);

if (!$product) {
    metrovps_client_output(['success' => false, 'error' => 'Service not found.'], 404);
}

// --- Action router -----------------------------------------------------------

switch ($action) {
    case 'ostemplates':
        try {
            $module = new Module();
            $templates = $module->getOsTemplatesForProduct((int) $hosting->packageid);

            $options = [];
            foreach ($templates as $id => $name) {
                $options[] = ['id' => (string) $id, 'name' => $name];
            }

            metrovps_client_output(['success' => true, 'templates' => $options]);
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'client:ostemplates', ['service_id' => $serviceId], $e->getMessage(), '');
            metrovps_client_output(['success' => false, 'error' => 'Unable to load OS templates.'], 502);
        }
        // no break — output exits

    case 'state':
        $record = WHMCS\Module\Server\MetroVPSB2B\Database::getByServiceId($serviceId);

        if (!$record) {
            metrovps_client_output(['success' => false, 'error' => 'No provision record found.'], 404);
        }

        $remote = '';
        if (!empty($record->provision_response)) {
            $snapshot = json_decode($record->provision_response, true);
            if (is_array($snapshot)) {
                $remote = (string) ($snapshot['remote_state'] ?? '');
            }
        }

        metrovps_client_output([
            'success'      => true,
            'status'       => (string) ($record->status ?? ''),
            'remote_state' => $remote,
        ]);
        // no break — output exits

    case 'poweron':
    case 'poweroff':
    case 'restart':
        $powerMap = ['poweron' => 'boot', 'poweroff' => 'shutdown', 'restart' => 'restart'];

        $module = new Module();
        $result = $module->powerAction($powerMap[$action], $serviceId);

        if ($result === 'success') {
            metrovps_client_output([
                'success' => true,
                'message' => 'Power action completed successfully.',
            ]);
        }

        metrovps_client_output(['success' => false, 'error' => $result], 200);
        // no break — output exits

    case 'resetpassword':
        $module = new Module();
        $result = $module->resetPasswordAction($serviceId);

        if ($result === 'success') {
            metrovps_client_output([
                'success' => true,
                'message' => 'Root password has been reset. The new password has been emailed to you.',
            ]);
        }

        metrovps_client_output(['success' => false, 'error' => $result], 200);
        // no break — output exits

    case 'reinstall':
        $module = new Module();
        $osId   = (int) ($_REQUEST['os_id'] ?? 0);
        $result = $module->reinstallAction($serviceId, $osId);

        if ($result === 'success') {
            metrovps_client_output([
                'success' => true,
                'message' => 'Reinstallation started. The VPS will be ready in a few minutes.',
            ]);
        }

        metrovps_client_output(['success' => false, 'error' => $result], 200);
        // no break — output exits

    default:
        metrovps_client_output(['success' => false, 'error' => 'Unknown action.'], 400);
}