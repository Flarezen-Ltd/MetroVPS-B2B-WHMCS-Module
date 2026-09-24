<?php

namespace WHMCS\Module\Server\MetroVPSB2B;

use WHMCS\Database\Capsule as DB;

class Module
{
    /** Key the product list snapshot is stored under in tbltransientdata. */
    const SNAPSHOT_KEY = 'metrovpsb2b.products';

    /** How long a product snapshot stays valid, in seconds (30 days). */
    const SNAPSHOT_TTL = 2592000;

    /** Cooldown between power actions, in seconds. */
    const POWER_LOCK_SECONDS = 60;

    /** Cooldown after a reinstall (rebuild), in seconds. */
    const REBUILD_LOCK_SECONDS = 180;

    protected $params;

    public function __construct(array $params = [])
    {
        $this->params = $params;
    }

    /**
     * Build an ApiClient from WHMCS server parameters.
     *
     * @return ApiClient|false
     */
    public function getApiClient()
    {
        $hostname = $this->params['serverhostname'] ?? '';
        $apiKeyEncrypted = $this->params['serverpassword'] ?? '';
        $apiSecretEncrypted = $this->params['serveraccesshash'] ?? '';

        logModuleCall(
            'MetroVPSB2B',
            'getApiClient',
            [
                'hostname' => $hostname,
                'api_key_present' => !empty($apiKeyEncrypted),
                'api_secret_present' => !empty($apiSecretEncrypted),
            ],
            'Raw server params read from WHMCS.',
            ''
        );

        if (empty($hostname) || empty($apiKeyEncrypted) || empty($apiSecretEncrypted)) {
            return false;
        }

        $apiKey = $this->safeDecrypt($apiKeyEncrypted);
        $apiSecret = $this->safeDecrypt($apiSecretEncrypted);

        if (empty($apiKey) || empty($apiSecret)) {
            return false;
        }

        $baseUrl = $this->normalizeBaseUrl($hostname);

        return new ApiClient($baseUrl, $apiKey, $apiSecret);
    }

    /**
     * Decrypt a WHMCS-stored value, falling back to the raw value if it was stored plaintext.
     *
     * @param string $value
     * @return string
     */
    protected function safeDecrypt(string $value): string
    {
        $decrypted = @decrypt($value);

        if (!empty($decrypted) && $this->isPrintable($decrypted)) {
            return $decrypted;
        }

        return $value;
    }

    /**
     * Static, public variant of safeDecrypt() for hook files and endpoints
     * that already have a WHMCS-stored value on hand.
     *
     * @param string $value
     * @return string
     */
    public static function decryptValue(string $value): string
    {
        $decrypted = @decrypt($value);

        if (!empty($decrypted) && preg_match('/^[\x20-\x7E]+$/', $decrypted) === 1) {
            return $decrypted;
        }

        return $value;
    }

    /**
     * Check whether a string is plain ASCII without control/binary characters.
     * MetroVPS tokens are alphanumeric plus underscore and hyphen.
     *
     * @param string $value
     * @return bool
     */
    protected function isPrintable(string $value): bool
    {
        return preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    /**
     * Ensure the base URL has a scheme and no trailing slash.
     *
     * @param string $hostname
     * @return string
     */
    protected function normalizeBaseUrl(string $hostname): string
    {
        $hostname = trim($hostname);

        if (!preg_match('/^https?:\/\//i', $hostname)) {
            $hostname = 'https://' . $hostname;
        }

        return rtrim($hostname, '/');
    }

    /**
     * Read the first active MetroVPSB2B server from the WHMCS database.
     *
     * @return array|false
     */
    public function getServerCredentialsFromDb()
    {
        $server = DB::table('tblservers')
            ->where('type', 'MetroVPSB2B')
            ->where('disabled', 0)
            ->orderBy('id', 'asc')
            ->first();

        if (!$server) {
            return false;
        }

        $apiKey = $this->safeDecrypt($server->password);
        $apiSecret = $this->safeDecrypt($server->accesshash);

        if (empty($server->hostname) || empty($apiKey) || empty($apiSecret)) {
            return false;
        }

        return [
            'base_url' => $this->normalizeBaseUrl($server->hostname),
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ];
    }

    /**
     * Fetch B2B products from the MetroVPS API.
     *
     * @return array
     * @throws \Exception
     */
    public function getProducts(): array
    {
        $client = $this->getApiClient();

        if (!$client) {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                throw new \Exception('No active MetroVPS B2B server found. Configure a server first.');
            }

            $client = new ApiClient(
                $credentials['base_url'],
                $credentials['api_key'],
                $credentials['api_secret']
            );
        }

        $result = $client->get('/b2b/api/products');

        logModuleCall(
            'MetroVPSB2B',
            'getProducts',
            [],
            $result,
            $result['success'] ? '' : 'API error'
        );

        if (!$result['success']) {
            throw new \Exception('Unable to fetch products: ' . $result['error']);
        }

        $body = $result['body'];

        if (!is_array($body) || empty($body['data']) || !is_array($body['data'])) {
            throw new \Exception('Invalid product list returned by MetroVPS.');
        }

        $this->saveProductSnapshot($body['data']);

        return $body['data'];
    }

    /**
     * Persist the latest product list so the client area can show package
     * specs without making an outbound API call.
     *
     * Stored in WHMCS' generic key/value table rather than on disk, because
     * the module directory is not writable by the web user.
     *
     * @param array $products
     * @return void
     */
    protected function saveProductSnapshot(array $products): void
    {
        try {
            DB::table('tbltransientdata')->updateOrInsert(
                ['name' => self::SNAPSHOT_KEY],
                [
                    'data' => json_encode([
                        'products' => $products,
                        'fetched_at' => time(),
                    ]),
                    'expires' => time() + self::SNAPSHOT_TTL,
                ]
            );
        } catch (\Throwable $e) {
            // A failed snapshot must never break the page that triggered the fetch.
            logModuleCall('MetroVPSB2B', 'saveProductSnapshot', [], $e->getMessage(), '');
        }
    }

    /**
     * Read the saved product list. Never calls the MetroVPS API.
     *
     * @return array Empty array when no unexpired snapshot exists.
     */
    public function getSavedProducts(): array
    {
        try {
            // tbltransientdata has no unique index on `name`, so concurrent
            // writes can leave more than one row. Take the newest.
            $row = DB::table('tbltransientdata')
                ->where('name', self::SNAPSHOT_KEY)
                ->orderBy('id', 'desc')
                ->first();
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'getSavedProducts', [], $e->getMessage(), '');
            return [];
        }

        if (!$row || empty($row->data)) {
            return [];
        }

        if (!empty($row->expires) && (int) $row->expires < time()) {
            return [];
        }

        $decoded = json_decode($row->data, true);

        if (!is_array($decoded) || empty($decoded['products']) || !is_array($decoded['products'])) {
            return [];
        }

        return $decoded['products'];
    }

    /**
     * Find a single saved product by its MetroVPS package ID. Never calls the API.
     *
     * @param string $packageId
     * @return array|null
     */
    public function getSavedProduct(string $packageId): ?array
    {
        if ($packageId === '') {
            return null;
        }

        foreach ($this->getSavedProducts() as $product) {
            if ((string) ($product['id'] ?? '') === $packageId) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Fetch detailed information for a single product by ID.
     *
     * @param string $productId
     * @return array|null
     * @throws \Exception
     */
    public function getProductDetails(string $productId): ?array
    {
        $client = $this->getApiClient();

        if (!$client) {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                return null;
            }

            $client = new ApiClient(
                $credentials['base_url'],
                $credentials['api_key'],
                $credentials['api_secret']
            );
        }

        $result = $client->getProductDetails($productId);

        if (!$result['success']) {
            logModuleCall('MetroVPSB2B', 'getProductDetails', ['id' => $productId], $result['error'], '');
            return null;
        }

        return $result['data'] ?? null;
    }

    /**
     * Fetch OS templates available for a WHMCS product.
     *
     * Reads the selected MetroVPS package from configoption1 and filters
     * Windows templates unless the package has windows_supported enabled.
     *
     * @param int $productId
     * @return array OS template options in id => name format
     * @throws \Exception
     */
    public function getOsTemplatesForProduct(int $productId): array
    {
        $product = DB::table('tblproducts')->where('id', $productId)->first();

        if (!$product) {
            throw new \Exception('Product not found.');
        }

        if ($product->servertype !== 'MetroVPSB2B') {
            throw new \Exception('Product is not a MetroVPS B2B product.');
        }

        $packageId = $product->configoption1 ?? '';

        if ($packageId === '') {
            throw new \Exception('No MetroVPS package assigned to this product.');
        }

        $windowsSupported = false;
        $products = $this->getProducts();

        foreach ($products as $package) {
            if ((string) ($package['id'] ?? '') === (string) $packageId) {
                $windowsSupported = !empty($package['windows_supported']);
                break;
            }
        }

        $client = $this->getApiClient();

        if (!$client) {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                throw new \Exception('No active MetroVPS B2B server found.');
            }

            $client = new ApiClient(
                $credentials['base_url'],
                $credentials['api_key'],
                $credentials['api_secret']
            );
        }

        $result = $client->getOsTemplates();

        if (!$result['success']) {
            throw new \Exception('Unable to fetch OS templates: ' . $result['error']);
        }

        $options = [];

        foreach ($result['data'] as $template) {
            $id = $template['id'] ?? '';
            $name = $template['name'] ?? '';

            if ($id === '' || $name === '') {
                continue;
            }

            if (!$windowsSupported && stripos($name, 'Windows') !== false) {
                continue;
            }

            $options[$id] = $name;
        }

        return $options;
    }

    /**
     * Check whether a WHMCS product has a server group assigned.
     *
     * @param int|null $productId
     * @return bool
     */
    public function productHasServerGroup(?int $productId): bool
    {
        if (!$productId) {
            return false;
        }

        $product = DB::table('tblproducts')->where('id', $productId)->first();

        return $product && !empty($product->servergroup);
    }

    /**
     * Determine the current WHMCS product ID from request/route parameters.
     *
     * @param array $params
     * @return int|null
     */
    public function detectCurrentProductId(array $params = []): ?int
    {
        $keys = ['id', 'productid', 'pid'];

        foreach ($keys as $key) {
            $value = $params[$key] ?? $_REQUEST[$key] ?? null;

            if (!empty($value) && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Return the MetroVPS B2B currency symbol. The API always prices in BDT.
     *
     * @return string
     */
    public function getCurrencySymbol(): string
    {
        return '৳';
    }

    /**
     * Format a price value for display, dropping decimals when they are zero.
     *
     * @param float $amount
     * @return string
     */
    protected function formatPrice(float $amount): string
    {
        if (fmod($amount, 1) === 0.0) {
            return number_format($amount, 0);
        }

        return number_format($amount, 2);
    }

    /**
     * Build a human-readable dropdown label for a MetroVPS B2B product.
     *
     * @param array $product
     * @return string
     */
    public function formatProductLabel(array $product): string
    {
        $title = $product['product_title'] ?? 'Unknown';
        $cpu = (int) ($product['cpu_core'] ?? 0);
        $memory = (int) ($product['memory_gb'] ?? 0);
        $storage = (int) ($product['storage_gb'] ?? 0);
        $location = $product['product_location'] ?? '';
        $monthly = (float) ($product['reseller_monthly_price'] ?? 0);
        $annual = (float) ($product['reseller_annual_price'] ?? 0);
        $symbol = $this->getCurrencySymbol();

        $label = sprintf(
            '%s — %d vCPU, %d GB RAM, %d GB SSD',
            $title,
            $cpu,
            $memory,
            $storage
        );

        if ($location !== '') {
            $label .= ', ' . $location;
        }

        $label .= sprintf(
            ' — %s%s/mo, %s%s/yr',
            $symbol,
            $this->formatPrice($monthly),
            $symbol,
            $this->formatPrice($annual)
        );

        return $label;
    }

    /**
     * Format a WHMCS-friendly error string from an exception.
     *
     * @param \Exception $e
     * @return string
     */
    public function formatError(\Exception $e): string
    {
        return $e->getMessage();
    }

    /**
     * Map a WHMCS billing cycle to the MetroVPS API expected value.
     *
     * @param string $whmcsCycle
     * @return string
     */
    public function mapBillingCycle(string $whmcsCycle): string
    {
        $map = [
            'Free Account'  => 'monthly',
            'One Time'      => 'monthly',
            'Monthly'       => 'monthly',
            'Quarterly'     => 'quarterly',
            'Semi-Annually' => 'semi-annually',
            'Annually'      => 'annually',
            'Biennially'    => 'biennially',
            'Triennially'   => 'triennially',
        ];

        return $map[$whmcsCycle] ?? 'monthly';
    }

    /**
     * Read the value of a product-level custom field for a given service.
     *
     * @param int $serviceId
     * @param string $fieldName
     * @return string|null
     */
    public function getCustomFieldValue(int $serviceId, string $fieldName): ?string
    {
        $hosting = DB::table('tblhosting')
            ->where('id', $serviceId)
            ->first(['packageid']);

        if (!$hosting || !$hosting->packageid) {
            return null;
        }

        $field = DB::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', (int) $hosting->packageid)
            ->where('fieldname', $fieldName)
            ->first(['id']);

        if (!$field) {
            return null;
        }

        $value = DB::table('tblcustomfieldsvalues')
            ->where('fieldid', (int) $field->id)
            ->where('relid', $serviceId)
            ->value('value');

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * Build the full payload for the MetroVPS provision-vps endpoint.
     *
     * @param array $params
     * @return array
     */
    public function buildProvisionPayload(array $params): array
    {
        $clientsDetails = $params['clientsdetails'] ?? [];
        $serviceId = (int) ($params['serviceid'] ?? 0);

        $templateId = null;
        if ($serviceId > 0) {
            $templateId = $this->getCustomFieldValue($serviceId, 'Operating System');
        }

        return [
            'customer_name'    => trim(($clientsDetails['firstname'] ?? '') . ' ' . ($clientsDetails['lastname'] ?? '')),
            'customer_email'   => $clientsDetails['email'] ?? '',
            'customer_mobile'  => $clientsDetails['phonenumber'] ?? '',
            'customer_company' => $clientsDetails['companyname'] ?? '',
            'package_id'       => (int) ($params['configoption1'] ?? 0),
            'template_id'      => $templateId !== null ? (int) $templateId : 0,
            'hostname'         => $params['domain'] ?? '',
            'billing_cycle'    => $this->mapBillingCycle($params['billingcycle'] ?? 'Monthly'),
            'webhook_url'      => rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/')
                . '/modules/servers/MetroVPSB2B/callback.php',
        ];
    }

    /**
     * Execute the full VPS provision workflow: build payload, call API, store result.
     *
     * Returns an empty string on success (WHMCS convention) or an error string
     * that WHMCS displays to the admin.
     *
     * @param array $params
     * @return string
     */
    public function provisionService(array $params): string
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $payload = $this->buildProvisionPayload($params);

        logModuleCall('MetroVPSB2B', 'provisionService', $params, 'Preparing provision request.', '');

        $client = $this->getApiClient();

        if (!$client) {
            $error = 'Unable to initialize MetroVPS API client. Check server credentials.';
            logModuleCall('MetroVPSB2B', 'provisionService:error', $payload, $error, '');

            if ($serviceId > 0) {
                Database::saveProvisionError($serviceId, $error, $payload);
            }

            return $error;
        }

        $result = $client->provisionVps($payload);

        logModuleCall(
            'MetroVPSB2B',
            'provisionService:result',
            $payload,
            $result,
            $result['success'] ? 'success' : 'error'
        );

        if ($serviceId > 0) {
            if ($result['success'] && !empty($result['data'])) {
                Database::saveProvisionResult($serviceId, $result['data'], $payload);
            } else {
                $error = $result['error'] ?: 'Unknown provision error';
                Database::saveProvisionError($serviceId, $error, $payload);
            }
        }

        if (!$result['success']) {
            return $result['error'] ?: 'VPS provision failed. Check the module log for details.';
        }

        return '';
    }

    /**
     * Refresh VPS data when the stored copy is staler than the TTL
     * (default 60 seconds). Called on client service-details page loads so
     * the dashboard shows recent data without hitting the API on every
     * reload.
     *
     * Safe to call repeatedly: a fresh updated_at makes it a cheap no-op.
     * Failures are logged and the caller renders with existing data.
     *
     * @param int $serviceId
     * @param int $ttlSeconds
     * @return bool True when a sync ran, false when skipped or failed.
     */
    public function clientSyncIfStale(int $serviceId, int $ttlSeconds = 60): bool
    {
        $record = Database::getByServiceId($serviceId);

        if (!$record || empty($record->b2b_order_id) || empty($record->metro_service_id)) {
            return false;
        }

        // Gate: skip when the last sync (or callback) is within the TTL.
        if (!empty($record->updated_at) && strtotime($record->updated_at) > time() - $ttlSeconds) {
            return false;
        }

        return $this->syncVpsDetailsNow($serviceId);
    }

    /**
     * Force a VPS details sync from the MetroVPS API, ignoring the TTL gate.
     * Used by the power actions, the reset-password flow and the admin Sync
     * button path.
     *
     * @param int $serviceId
     * @return array|null The vps-details payload on success, null on failure.
     */
    public function syncVpsDetailsNow(int $serviceId): ?array
    {
        $record = Database::getByServiceId($serviceId);

        if (!$record || empty($record->b2b_order_id) || empty($record->metro_service_id)) {
            return null;
        }

        try {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                return null;
            }

            $client = new ApiClient(
                $credentials['base_url'],
                $credentials['api_key'],
                $credentials['api_secret']
            );

            $result = $client->getVpsDetails((int) $record->metro_service_id, (int) $record->b2b_order_id);

            logModuleCall(
                'MetroVPSB2B',
                'syncVpsDetailsNow',
                ['service_id' => $serviceId],
                $result,
                $result['success'] ? 'success' : 'error'
            );

            if (!$result['success'] || empty($result['data'])) {
                return null;
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
                DB::table('tblhosting')->where('id', $serviceId)->update($hostingUpdate);
            }

            return $vpsData;
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'syncVpsDetailsNow:error', ['service_id' => $serviceId], $e->getMessage(), '');
            return null;
        }
    }

    /**
     * Send a power action (boot / shutdown / restart) to MetroVPS and refresh
     * the local VPS data so the new power state shows immediately.
     *
     * @param string $action 'boot', 'shutdown' or 'restart'
     * @param int    $serviceId
     * @return string 'success' or an error message
     */
    public function powerAction(string $action, int $serviceId): string
    {
        $allowed = ['boot', 'shutdown', 'restart'];

        if (!in_array($action, $allowed, true)) {
            return 'Unknown power action.';
        }

        $record = Database::getByServiceId($serviceId);

        if (!$record || empty($record->b2b_order_id) || empty($record->metro_service_id)) {
            return 'No provision record found for this service.';
        }

        if (($record->status ?? '') === 'suspended') {
            return 'This VPS is suspended. Actions are unavailable until it is unsuspended.';
        }

        // Cooldown: one power action per 60 seconds per VPS; nothing while a
        // reinstall is in progress.
        $lockRemaining = $this->powerLockRemaining($record->power_action_at ?? null);

        if ($lockRemaining > 0) {
            return 'Power action is locked. Please try again in ' . $lockRemaining . ' second' . ($lockRemaining === 1 ? '' : 's') . '.';
        }

        $rebuildLockRemaining = $this->rebuildLockRemaining($record->locked_until ?? null);

        if ($rebuildLockRemaining > 0) {
            return 'The VPS is being reinstalled. Power actions are locked for ' . $rebuildLockRemaining . ' second' . ($rebuildLockRemaining === 1 ? '' : 's') . '.';
        }

        // Reserve the lock before the API call so two simultaneous requests
        // cannot both pass the check above.
        Database::setPowerActionAt($serviceId, date('Y-m-d H:i:s'));

        try {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                Database::setPowerActionAt($serviceId);
                return 'No active MetroVPS B2B server found. Check server configuration.';
            }

            $client  = new ApiClient($credentials['base_url'], $credentials['api_key'], $credentials['api_secret']);
            $result  = $client->powerAction((int) $record->metro_service_id, (int) $record->b2b_order_id, $action);

            logModuleCall(
                'MetroVPSB2B',
                'powerAction:' . $action,
                ['service_id' => $serviceId, 'metro_service_id' => (int) $record->metro_service_id, 'b2b_order_id' => (int) $record->b2b_order_id],
                $result,
                $result['success'] ? 'success' : 'error'
            );

            if (!$result['success']) {
                // Release the lock so a corrected retry is possible.
                Database::setPowerActionAt($serviceId);
                return 'Power action failed: ' . ($result['error'] ?: 'unknown error');
            }

            // Best-effort sync first, then overlay the deterministic
            // post-action remote state so the UI reflects the change
            // immediately, even if MetroVPS' vps-details lags behind.
            $this->syncVpsDetailsNow($serviceId);

            $remoteMap = [
                'boot'     => 'starting',
                'restart'  => 'restarting',
                'shutdown' => 'stopping',
            ];

            $this->patchRemoteState($serviceId, $remoteMap[$action]);

            return 'success';
        } catch (\Throwable $e) {
            Database::setPowerActionAt($serviceId);
            logModuleCall('MetroVPSB2B', 'powerAction:' . $action . ':error', ['service_id' => $serviceId], $e->getMessage(), '');
            return $e->getMessage();
        }
    }

    /**
     * Seconds left on the power-action cooldown for a service.
     *
     * @param string|null $powerActionAt 'Y-m-d H:i:s' or null
     * @return int 0 when no lock is active.
     */
    public function powerLockRemaining(?string $powerActionAt): int
    {
        if (empty($powerActionAt)) {
            return 0;
        }

        $elapsed   = time() - strtotime($powerActionAt);
        $remaining = self::POWER_LOCK_SECONDS - $elapsed;

        return $remaining > 0 ? (int) $remaining : 0;
    }

    /**
     * Overlay a remote_state (and derived is_running) onto the stored
     * vps-details snapshot WITHOUT touching the lifecycle status. The
     * lifecycle status only changes via MetroVPS callbacks/syncs.
     *
     * @param int    $serviceId
     * @param string $remoteState e.g. running, stopped, starting, restarting, stopping, installing
     */
    protected function patchRemoteState(int $serviceId, string $remoteState): void
    {
        $record = Database::getByServiceId($serviceId);

        if (!$record) {
            return;
        }

        $this->patchState($serviceId, $record->status ?? 'active', $remoteState);
    }

    /**
     * Persist a lifecycle status and/or remote state onto the stored
     * vps-details snapshot without an API round-trip.
     *
     * @param int         $serviceId
     * @param string      $status      lifecycle status to store
     * @param string|null $remoteState optional remote_state overlay
     */
    protected function patchState(int $serviceId, string $status, ?string $remoteState = null): void
    {
        $record = Database::getByServiceId($serviceId);

        if (!$record) {
            return;
        }

        $vpsData = json_decode($record->provision_response ?? '{}', true);

        if (!is_array($vpsData)) {
            $vpsData = [];
        }

        $vpsData['service_status'] = $status;

        if ($remoteState !== null) {
            $vpsData['remote_state'] = $remoteState;
            $vpsData['is_running']   = ($remoteState === 'running');
        }

        Database::updateCallbackResult($serviceId, $vpsData);
    }

    /**
     * Suspend the VPS at MetroVPS and persist the suspended state locally.
     *
     * @param array $params WHMCS module params (serviceid, suspendreason)
     * @return string 'success' or an error message
     */
    public function suspendService(array $params): string
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);

        if ($serviceId <= 0) {
            return 'Invalid service ID.';
        }

        $record = Database::getByServiceId($serviceId);

        if (!$record || empty($record->b2b_order_id) || empty($record->metro_service_id)) {
            return 'No provision record found for this service.';
        }

        try {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                return 'No active MetroVPS B2B server found. Check server configuration.';
            }

            $client = new ApiClient($credentials['base_url'], $credentials['api_key'], $credentials['api_secret']);
            $result = $client->suspendVps((int) $record->metro_service_id, (int) $record->b2b_order_id, (string) ($params['suspendreason'] ?? ''));

            logModuleCall(
                'MetroVPSB2B',
                'suspendService',
                ['service_id' => $serviceId, 'reason' => $params['suspendreason'] ?? ''],
                $result,
                $result['success'] ? 'success' : 'error'
            );

            if (!$result['success']) {
                return 'Suspend failed: ' . ($result['error'] ?: 'unknown error');
            }

            $this->patchState($serviceId, 'suspended', 'stopped');

            return 'success';
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'suspendService:error', ['service_id' => $serviceId], $e->getMessage(), '');
            return $e->getMessage();
        }
    }

    /**
     * Unsuspend the VPS at MetroVPS and restore the active status locally.
     *
     * @param array $params WHMCS module params
     * @return string 'success' or an error message
     */
    public function unsuspendService(array $params): string
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);

        if ($serviceId <= 0) {
            return 'Invalid service ID.';
        }

        $record = Database::getByServiceId($serviceId);

        if (!$record || empty($record->b2b_order_id) || empty($record->metro_service_id)) {
            return 'No provision record found for this service.';
        }

        try {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                return 'No active MetroVPS B2B server found. Check server configuration.';
            }

            $client = new ApiClient($credentials['base_url'], $credentials['api_key'], $credentials['api_secret']);
            $result = $client->unsuspendVps((int) $record->metro_service_id, (int) $record->b2b_order_id);

            logModuleCall(
                'MetroVPSB2B',
                'unsuspendService',
                ['service_id' => $serviceId],
                $result,
                $result['success'] ? 'success' : 'error'
            );

            if (!$result['success']) {
                return 'Unsuspend failed: ' . ($result['error'] ?: 'unknown error');
            }

            $this->patchState($serviceId, 'active');

            return 'success';
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'unsuspendService:error', ['service_id' => $serviceId], $e->getMessage(), '');
            return $e->getMessage();
        }
    }

    /**
     * Notify MetroVPS that a service has been renewed and sync the returned
     * billing state back into the local provision record.
     *
     * Fire-and-forget style notifier for the billing hooks: failures are
     * reported through the return value and the module log, never thrown, so a
     * broken upstream API can never disrupt a WHMCS service.
     *
     * @param int $serviceId WHMCS tblhosting.id
     * @return array ['success' => bool, 'error' => string]
     */
    public function renewService(int $serviceId): array
    {
        $record = Database::getByServiceId($serviceId);

        if (!$record || empty($record->metro_service_id) || empty($record->b2b_order_id)) {
            return ['success' => false, 'error' => 'No provision record found for this service.'];
        }

        try {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                return ['success' => false, 'error' => 'No active MetroVPS B2B server found.'];
            }

            $client = new ApiClient($credentials['base_url'], $credentials['api_key'], $credentials['api_secret']);
            $result = $client->renewVps((int) $record->metro_service_id, (int) $record->b2b_order_id);

            logModuleCall(
                'MetroVPSB2B',
                'renewService',
                [
                    'service_id'       => $serviceId,
                    'metro_service_id' => (int) $record->metro_service_id,
                    'b2b_order_id'     => (int) $record->b2b_order_id,
                ],
                $result,
                $result['success'] ? 'success' : 'error'
            );

            if (!$result['success']) {
                return ['success' => false, 'error' => $result['error'] ?: 'Unknown renew error'];
            }

            $this->applyRenewResult($serviceId, $result['data'] ?? []);

            return ['success' => true, 'error' => ''];
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'renewService:error', ['service_id' => $serviceId], $e->getMessage(), '');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Persist the renew response (charged amount, remaining balance, status,
     * next due date) onto the provision record.
     *
     * Uses a direct update so the existing provision_response snapshot is
     * only patched, never overwritten with a partial payload.
     *
     * @param int   $serviceId
     * @param array $data Renew response 'data' block (may be empty)
     * @return void
     */
    protected function applyRenewResult(int $serviceId, array $data): void
    {
        $updates = [
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('charged_amount', $data)) {
            $updates['charged_amount'] = $data['charged_amount'];
        }

        if (array_key_exists('currency_code', $data)) {
            $updates['currency_code'] = $data['currency_code'];
        }

        if (array_key_exists('balance_remaining', $data)) {
            $updates['balance_remaining'] = $data['balance_remaining'];
        }

        if (!empty($data['service_status'])) {
            $updates['status'] = $data['service_status'];
        }

        // next_due_date has no dedicated column; carry it on the snapshot.
        $record   = Database::getByServiceId($serviceId);
        $snapshot = [];

        if ($record && !empty($record->provision_response)) {
            $decoded = json_decode($record->provision_response, true);

            if (is_array($decoded)) {
                $snapshot = $decoded;
            }
        }

        if (array_key_exists('next_due_date', $data)) {
            $snapshot['next_due_date'] = $data['next_due_date'];
        }

        $updates['provision_response'] = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        try {
            DB::table(Database::TABLE)->where('service_id', $serviceId)->update($updates);
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'applyRenewResult:error', ['service_id' => $serviceId], $e->getMessage(), '');
        }
    }

    /**
     * Seconds left until the absolute rebuild lock expiry.
     *
     * @param string|null $lockedUntil 'Y-m-d H:i:s' absolute expiry or null
     * @return int 0 when no lock is active.
     */
    public function rebuildLockRemaining(?string $lockedUntil): int
    {
        if (empty($lockedUntil)) {
            return 0;
        }

        $remaining = strtotime($lockedUntil) - time();

        return $remaining > 0 ? (int) $remaining : 0;
    }

    /**
     * Reinstall a VPS from an OS template via MetroVPS.
     *
     * @param int $serviceId
     * @param int $templateId OS template id selected in the reinstall modal
     * @return string 'success' or an error message
     */
    public function reinstallAction(int $serviceId, int $templateId): string
    {
        if ($templateId <= 0) {
            return 'Please select an operating system.';
        }

        $record = Database::getByServiceId($serviceId);

        if (!$record || empty($record->b2b_order_id) || empty($record->metro_service_id)) {
            return 'No provision record found for this service.';
        }

        if (($record->status ?? '') === 'suspended') {
            return 'This VPS is suspended. Actions are unavailable until it is unsuspended.';
        }

        $rebuildLockRemaining = $this->rebuildLockRemaining($record->locked_until ?? null);

        if ($rebuildLockRemaining > 0) {
            return 'The VPS is being reinstalled. Please try again in ' . $rebuildLockRemaining . ' second' . ($rebuildLockRemaining === 1 ? '' : 's') . '.';
        }

        $powerLockRemaining = $this->powerLockRemaining($record->power_action_at ?? null);

        if ($powerLockRemaining > 0) {
            return 'A power action was just performed. Please wait ' . $powerLockRemaining . ' second' . ($powerLockRemaining === 1 ? '' : 's') . ' before reinstalling.';
        }

        // Pass the existing hostname through to the rebuild request.
        $hostname = (string) (DB::table('tblhosting')->where('id', $serviceId)->value('domain') ?? '');

        if ($hostname === '') {
            $decoded = json_decode($record->provision_response ?? '{}', true);
            $hostname = (string) (is_array($decoded) ? ($decoded['hostname'] ?? '') : '');
        }

        try {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                return 'No active MetroVPS B2B server found. Check server configuration.';
            }

            $client = new ApiClient($credentials['base_url'], $credentials['api_key'], $credentials['api_secret']);
            $result = $client->rebuild((int) $record->metro_service_id, (int) $record->b2b_order_id, $templateId, $hostname);

            logModuleCall(
                'MetroVPSB2B',
                'reinstallAction',
                [
                    'service_id'       => $serviceId,
                    'metro_service_id' => (int) $record->metro_service_id,
                    'b2b_order_id'     => (int) $record->b2b_order_id,
                    'template_id'      => $templateId,
                    'hostname'         => $hostname,
                ],
                $result,
                $result['success'] ? 'success' : 'error'
            );

            if (!$result['success']) {
                return 'Reinstall failed: ' . ($result['error'] ?: 'unknown error');
            }

            $this->patchRemoteState($serviceId, 'installing');
            Database::setLockedUntil($serviceId, date('Y-m-d H:i:s', time() + self::REBUILD_LOCK_SECONDS));

            return 'success';
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'reinstallAction:error', ['service_id' => $serviceId], $e->getMessage(), '');
            return $e->getMessage();
        }
    }

    /**
     * Reset the root password via MetroVPS, sync the new password into WHMCS
     * and email it to the client.
     *
     * @param int $serviceId
     * @return string 'success' or an error message
     */
    public function resetPasswordAction(int $serviceId): string
    {
        $record = Database::getByServiceId($serviceId);

        if (!$record || empty($record->b2b_order_id) || empty($record->metro_service_id)) {
            return 'No provision record found for this service.';
        }

        if (($record->status ?? '') === 'suspended') {
            return 'This VPS is suspended. Actions are unavailable until it is unsuspended.';
        }

        try {
            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                return 'No active MetroVPS B2B server found. Check server configuration.';
            }

            $client = new ApiClient($credentials['base_url'], $credentials['api_key'], $credentials['api_secret']);
            $result = $client->resetPassword((int) $record->metro_service_id, (int) $record->b2b_order_id);

            logModuleCall(
                'MetroVPSB2B',
                'resetPasswordAction',
                ['service_id' => $serviceId, 'metro_service_id' => (int) $record->metro_service_id, 'b2b_order_id' => (int) $record->b2b_order_id],
                $result,
                $result['success'] ? 'success' : 'error'
            );

            if (!$result['success']) {
                return 'Password reset failed: ' . ($result['error'] ?: 'unknown error');
            }

            // The reset response carries the new password — treat it as
            // authoritative and persist it over whatever the sync returns.
            $newPassword = $result['data']['password'] ?? '';

            // Best-effort full sync for the rest of the VPS state.
            $this->syncVpsDetailsNow($serviceId);

            $record = Database::getByServiceId($serviceId);
            $vpsData = [];

            if ($record && !empty($record->provision_response)) {
                $decoded = json_decode($record->provision_response, true);
                if (is_array($decoded)) {
                    $vpsData = $decoded;
                }
            }

            if ($newPassword !== '') {
                $vpsData['root_password'] = $newPassword;

                Database::updateCallbackResult($serviceId, $vpsData);

                DB::table('tblhosting')->where('id', $serviceId)->update([
                    'password' => encrypt($newPassword),
                    'username' => 'root',
                ]);
            }

            $this->sendPasswordResetEmail($serviceId, $vpsData);

            return 'success';
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'resetPasswordAction:error', ['service_id' => $serviceId], $e->getMessage(), '');
            return $e->getMessage();
        }
    }

    /**
     * Email the client their new root password after a reset.
     *
     * @param int   $serviceId
     * @param array $vpsData Latest vps-details payload (may be empty)
     * @return void
     */
    protected function sendPasswordResetEmail(int $serviceId, array $vpsData): void
    {
        $this->sendCredentialsEmail(
            $serviceId,
            $vpsData,
            'VPS Root Password Reset - {hostname}',
            'The root password for your VPS has been reset.'
        );
    }

    /**
     * Email the client their new login details after a rebuild (reinstall).
     *
     * @param int   $serviceId
     * @param array $vpsData Latest vps-details payload (may be empty)
     * @return void
     */
    public function sendRebuiltCredentialsEmail(int $serviceId, array $vpsData): void
    {
        $this->sendCredentialsEmail(
            $serviceId,
            $vpsData,
            'VPS Rebuild Complete - {hostname}',
            'Your VPS has been reinstalled with a new operating system. Here are your new login details:'
        );
    }

    /**
     * Shared credentials email: password, IP, hostname and SSH command.
     *
     * @param int    $serviceId
     * @param array  $vpsData
     * @param string $subject '{hostname}' in the subject is replaced after lookup
     * @param string $intro   Body paragraph introducing the new credentials
     * @return void
     */
    protected function sendCredentialsEmail(int $serviceId, array $vpsData, string $subject, string $intro): void
    {
        try {
            $hosting = DB::table('tblhosting')
                ->where('id', $serviceId)
                ->first(['userid', 'domain', 'dedicatedip']);

            if (!$hosting || !$hosting->userid) {
                logModuleCall('MetroVPSB2B', 'sendCredentialsEmail', ['service_id' => $serviceId], 'Hosting row or userid missing.', '');
                return;
            }

            $hostname = !empty($vpsData['hostname']) ? $vpsData['hostname'] : (string) $hosting->domain;
            $ip       = !empty($vpsData['ipv4']) ? $vpsData['ipv4'] : (string) $hosting->dedicatedip;
            $password = $vpsData['root_password'] ?? '';

            $message = '<p>Hello,</p>'
                . '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><strong>Hostname:</strong> ' . htmlspecialchars($hostname, ENT_QUOTES, 'UTF-8') . '<br>'
                . ($ip !== '' ? '<strong>IP Address:</strong> ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '<br>' : '')
                . ($password !== '' ? '<strong>New Root Password:</strong> <code>' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</code><br>' : '')
                . '<strong>SSH:</strong> <code>ssh root@' . htmlspecialchars($ip !== '' ? $ip : $hostname, ENT_QUOTES, 'UTF-8') . '</code></p>'
                . '<p>For security, please change the password after your first login.</p>';

            $subject = str_replace('{hostname}', $hostname, $subject);

            $emailResult = localAPI('SendEmail', [
                'customtype'    => 'product',
                'id'            => $serviceId,
                'customsubject' => $subject,
                'custommessage' => $message,
            ]);

            logModuleCall(
                'MetroVPSB2B',
                'sendCredentialsEmail',
                ['service_id' => $serviceId, 'userid' => $hosting->userid, 'subject' => $subject],
                $emailResult,
                ($emailResult['result'] ?? '') === 'success' ? 'sent' : 'failed'
            );
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'sendCredentialsEmail:error', ['service_id' => $serviceId], $e->getMessage(), '');
        }
    }

    /**
     * Fetch the provision record for a WHMCS service.
     *
     * @param int $serviceId
     * @return object|null
     */
    public function getProvisionRecord(int $serviceId): ?object
    {
        return Database::getByServiceId($serviceId);
    }

    /**
     * Link a WHMCS service to an existing (externally-created) MetroVPS VPS.
     *
     * Looks the VPS up on the MetroVPS platform with the `existing=true` flag
     * (no B2B order ID), stores the returned snapshot in mod_metrovpsb2b and
     * refreshes the WHMCS tblhosting fields (IP, root password, hostname).
     *
     * @param int $whmcsServiceId WHMCS tblhosting.id
     * @param int $metroServiceId MetroVPS service ID of the existing VPS
     * @return array ['success' => bool, 'message' => string, 'data' => array|null]
     */
    public function connectExistingVps(int $whmcsServiceId, int $metroServiceId): array
    {
        if ($metroServiceId <= 0) {
            return ['success' => false, 'message' => 'Please enter a valid MetroVPS service ID.', 'data' => null];
        }

        try {
            $hosting = DB::table('tblhosting')
                ->where('id', $whmcsServiceId)
                ->first(['id', 'packageid']);

            if (!$hosting) {
                return ['success' => false, 'message' => 'WHMCS service not found.', 'data' => null];
            }

            $isMetro = DB::table('tblproducts')
                ->where('id', (int) $hosting->packageid)
                ->where('servertype', 'MetroVPSB2B')
                ->exists();

            if (!$isMetro) {
                return ['success' => false, 'message' => 'This service is not assigned to the MetroVPS B2B module.', 'data' => null];
            }

            $credentials = $this->getServerCredentialsFromDb();

            if (!$credentials) {
                return ['success' => false, 'message' => 'No active MetroVPS B2B server found. Check server configuration.', 'data' => null];
            }

            $client = new ApiClient(
                $credentials['base_url'],
                $credentials['api_key'],
                $credentials['api_secret']
            );

            $result = $client->getExistingVpsDetails($metroServiceId);

            logModuleCall(
                'MetroVPSB2B',
                'connectExistingVps',
                ['service_id' => $whmcsServiceId, 'metro_service_id' => $metroServiceId],
                $result,
                $result['success'] ? 'success' : 'error'
            );

            if (!$result['success'] || empty($result['data'])) {
                return [
                    'success' => false,
                    'message' => 'Failed to fetch VPS details: ' . ($result['error'] ?: 'unknown error'),
                    'data' => null,
                ];
            }

            $vpsData = $result['data'];

            Database::importExistingVps($whmcsServiceId, $metroServiceId, $vpsData);

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
                DB::table('tblhosting')->where('id', $whmcsServiceId)->update($hostingUpdate);
            }

            return [
                'success' => true,
                'message' => 'VPS connected successfully.',
                'data' => $vpsData,
            ];
        } catch (\Throwable $e) {
            logModuleCall('MetroVPSB2B', 'connectExistingVps:error', ['service_id' => $whmcsServiceId, 'metro_service_id' => $metroServiceId], $e->getMessage(), '');
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Build the admin service tab fields for the WHMCS admin area.
     *
     * @param int $serviceId
     * @return array
     */
    public function getAdminTabFields(int $serviceId): array
    {
        $record = Database::getByServiceId($serviceId);

        if (!$record) {
            return [
                'Provision Status' => '<span class="label label-default">Not Yet Provisioned</span>'
                    . '<br><small class="text-muted">The VPS will be provisioned when the service is activated.</small>',
                'Connect Existing VPS' => $this->connectExistingVpsField($serviceId),
            ];
        }

        $fields = [];

        // Status badge (lifecycle)
        $statusHtml = $this->renderStatusBadge($record->status, $record->last_error);
        $fields['Provision Status'] = $statusHtml;

        // Power state (remote_state from the stored vps-details snapshot)
        $remoteState = '';

        if (!empty($record->provision_response)) {
            $snapshot = json_decode($record->provision_response, true);
            if (is_array($snapshot)) {
                $remoteState = (string) ($snapshot['remote_state'] ?? '');
            }
        }

        $powerStates = [
            'running'    => '<span class="label label-success">Running</span>',
            'stopped'    => '<span class="label label-default">Stopped</span>',
            'starting'   => '<span class="label label-info">Starting</span>',
            'restarting' => '<span class="label label-info">Restarting</span>',
            'stopping'   => '<span class="label label-info">Stopping</span>',
            'installing' => '<span class="label label-info">Installing</span>',
        ];

        $fields['Power State'] = $powerStates[$remoteState] ?? '<span class="label label-default">Unknown</span>';

        // IDs section
        $idsHtml = '<div class="row" style="margin-top: 8px;">';
        $idFields = [
            'B2B Order ID'    => $record->b2b_order_id,
            'Order ID'        => $record->order_id,
            'Order UID'       => $record->order_uid,
            'MetroVPS Service ID' => $record->metro_service_id,
            'Invoice ID'      => $record->invoice_id,
        ];

        foreach ($idFields as $label => $value) {
            if ($value !== null && $value !== '') {
                $idsHtml .= sprintf(
                    '<div class="col-sm-6"><strong>%s:</strong> %s</div>',
                    htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
                );
            }
        }
        $idsHtml .= '</div>';
        $fields['Provisioned IDs'] = $idsHtml;

        // Financials
        $finHtml = '<div class="row" style="margin-top: 8px;">';
        $finFields = [
            'Billing Cycle'      => $record->billing_cycle,
            'Charged Amount'     => $record->charged_amount !== null
                ? number_format((float) $record->charged_amount, 2) . ' ' . htmlspecialchars($record->currency_code ?? '', ENT_QUOTES, 'UTF-8')
                : null,
            'Recurring Amount'   => $record->recurring_amount !== null
                ? number_format((float) $record->recurring_amount, 2) . ' ' . htmlspecialchars($record->currency_code ?? '', ENT_QUOTES, 'UTF-8')
                : null,
            'Balance Remaining'  => $record->balance_remaining !== null
                ? number_format((float) $record->balance_remaining, 2) . ' ' . htmlspecialchars($record->currency_code ?? '', ENT_QUOTES, 'UTF-8')
                : null,
        ];

        foreach ($finFields as $label => $value) {
            if ($value !== null && $value !== '') {
                $finHtml .= sprintf(
                    '<div class="col-sm-6"><strong>%s:</strong> %s</div>',
                    htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                    $value
                );
            }
        }
        $finHtml .= '</div>';
        $fields['Financials'] = $finHtml;

        // Last error
        if (!empty($record->last_error)) {
            $fields['Last Error'] = '<div class="alert alert-danger" style="margin-bottom: 0;">'
                . '<strong>Error:</strong> '
                . htmlspecialchars($record->last_error, ENT_QUOTES, 'UTF-8')
                . '</div>';
        }

        // Timestamp
        if (!empty($record->updated_at)) {
            $fields['Last Provision Attempt'] = htmlspecialchars($record->updated_at, ENT_QUOTES, 'UTF-8');
        }

        // Offer a way to link an externally-created VPS when this service has
        // no MetroVPS service id yet.
        if (empty($record->metro_service_id)) {
            $fields['Connect Existing VPS'] = $this->connectExistingVpsField($serviceId);
        }

        return $fields;
    }

    /**
     * Render the "Connect Existing VPS" button + modal + wiring JS for the
     * admin service Module tab.
     *
     * @param int $serviceId WHMCS tblhosting.id
     * @return string HTML
     */
    protected function connectExistingVpsField(int $serviceId): string
    {
        $modalId  = 'metrovps-connect-modal';
        $errorId  = 'metrovps-connect-error';
        $inputId  = 'metrovps-connect-service-id';
        $endpoint = '/modules/servers/MetroVPSB2B/connect-existing.php';
        $service  = $serviceId;

        return '<div class="metrovps-connect-wrap">'
            . '<button type="button" class="btn btn-default" id="metrovps-connect-existing-btn">'
            . '<i class="fas fa-plug"></i> Connect Existing VPS</button>'
            . '<br><small class="text-muted">Link this service to a MetroVPS VPS that was not created from WHMCS.</small>'
            . '</div>'
            . '<div class="modal fade" id="' . $modalId . '" tabindex="-1" role="dialog" aria-hidden="true">'
            . '<div class="modal-dialog" role="document">'
            . '<div class="modal-content">'
            . '<div class="modal-header">'
            . '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>'
            . '<h4 class="modal-title"><i class="fas fa-plug"></i> Connect Existing VPS</h4>'
            . '</div>'
            . '<div class="modal-body">'
            . '<div class="alert alert-danger hidden" id="' . $errorId . '"></div>'
            . '<div class="form-group">'
            . '<label for="' . $inputId . '">MetroVPS Service ID</label>'
            . '<input type="number" class="form-control" id="' . $inputId . '" placeholder="e.g. 123">'
            . '</div>'
            . '</div>'
            . '<div class="modal-footer">'
            . '<button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>'
            . '<button type="button" class="btn btn-primary" id="metrovps-connect-submit"><i class="fas fa-plug"></i> Connect</button>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '<script>'
            . '(function () {'
            . 'var endpoint = ' . json_encode($endpoint) . ';'
            . 'var whmcsServiceId = ' . $service . ';'
            . 'var $modal = jQuery("#' . $modalId . '");'
            . 'var $input = jQuery("#' . $inputId . '");'
            . 'var $error = jQuery("#' . $errorId . '");'
            . 'jQuery("#metrovps-connect-existing-btn").on("click", function () {'
            . '$error.addClass("hidden").text("");'
            . '$input.val("");'
            . '$modal.modal("show");'
            . 'setTimeout(function () { $input.trigger("focus"); }, 200);'
            . '});'
            . 'function resetSubmit(btn) { btn.prop("disabled", false).find("i").removeClass("fa-spin"); }'
            . 'jQuery("#metrovps-connect-submit").on("click", function () {'
            . 'var btn = jQuery(this);'
            . 'var raw = String($input.val() || "").trim();'
            . 'var metroServiceId = parseInt(raw, 10);'
            . 'if (!raw || isNaN(metroServiceId) || metroServiceId <= 0) {'
            . '$error.text("Please enter a valid MetroVPS service ID.").removeClass("hidden");'
            . 'return;'
            . '}'
            . 'btn.prop("disabled", true).find("i").addClass("fa-spin");'
            . 'WHMCS.http.jqClient.post(endpoint,'
            . '"token=" + encodeURIComponent(typeof csrfToken !== "undefined" ? csrfToken : "")'
            . ' + "&serviceid=" + whmcsServiceId'
            . ' + "&metro_service_id=" + encodeURIComponent(metroServiceId),'
            . 'function (data) {'
            . 'resetSubmit(btn);'
            . 'if (data && data.success) { window.location.reload(); }'
            . 'else { $error.text((data && data.message) ? data.message : "Unable to connect the VPS.").removeClass("hidden"); }'
            . '}, "json").fail(function () {'
            . 'resetSubmit(btn);'
            . '$error.text("Request failed. Please try again.").removeClass("hidden");'
            . '});'
            . '});'
            . '})();'
            . '</script>';
    }

    /**
     * Render a status badge for the admin tab.
     *
     * @param string|null $status
     * @param string|null $lastError
     * @return string
     */
    protected function renderStatusBadge(?string $status, ?string $lastError): string
    {
        switch ($status) {
            case 'active':
                return '<span class="label label-success">Provisioned Successfully</span>';

            case 'processing':
                return '<span class="label label-warning">Processing</span>'
                    . '<br><small class="text-muted">The VPS is being provisioned by MetroVPS.</small>';

            case 'suspended':
                return '<span class="label label-warning">Suspended</span>'
                    . '<br><small class="text-muted">The VPS is suspended.</small>';

            case 'inactive':
                return '<span class="label label-default">Inactive</span>';

            case 'terminated':
                return '<span class="label label-default">Terminated</span>';

            case 'failed':
                return '<span class="label label-danger">Provision Failed</span>';

            case null:
                return '<span class="label label-default">Unknown</span>';

            default:
                if ($lastError) {
                    return '<span class="label label-danger">Provision Failed</span>';
                }

                return '<span class="label label-default">'
                    . htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
                    . '</span>';
        }
    }

    /**
     * Build the options array passed to MetroVPS from WHMCS config options.
     *
     * @return array
     */
    public function buildProvisionOptions(): array
    {
        return [
            'package_id' => $this->params['configoption1'] ?? '',
            'hostname' => $this->params['domain'] ?? '',
            'customfields' => $this->params['customfields'] ?? [],
            'configoptions' => $this->params['configoptions'] ?? [],
        ];
    }
}
