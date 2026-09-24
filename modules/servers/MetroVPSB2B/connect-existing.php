<?php

/**
 * MetroVPS B2B — Connect Existing VPS Admin Endpoint
 *
 * Handles the AJAX request from the admin service Module tab's
 * "Connect Existing VPS" modal. Looks up an externally-created VPS on the
 * MetroVPS platform using /b2b/api/vps-details?service_id=X&existing=true and
 * imports its details into WHMCS.
 *
 * Expected POST parameters:
 *   token            WHMCS admin CSRF token
 *   serviceid        WHMCS tblhosting.id (the service being linked)
 *   metro_service_id MetroVPS service ID of the existing VPS
 */

require dirname(__DIR__, 3) . '/init.php';

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Module.php';

use WHMCS\Module\Server\MetroVPSB2B\Database;
use WHMCS\Module\Server\MetroVPSB2B\Module;

// Ensure the table schema exists.
Database::schema();

// --- Authentication ----------------------------------------------------------

check_token('WHMCS.admin.default');

$currentUser = new \WHMCS\Authentication\CurrentUser;

if (!$currentUser->isAuthenticatedAdmin()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// --- Parse request -----------------------------------------------------------

$whmcsServiceId = (int) ($_POST['serviceid'] ?? 0);
$metroServiceId  = (int) ($_POST['metro_service_id'] ?? 0);

if ($whmcsServiceId <= 0 || $metroServiceId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Missing service ID.']);
    exit;
}

// --- Connect existing VPS -----------------------------------------------------

$result = (new Module())->connectExistingVps($whmcsServiceId, $metroServiceId);

http_response_code($result['success'] ? 200 : 502);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => $result['success'],
    'message' => $result['message'],
]);
exit;