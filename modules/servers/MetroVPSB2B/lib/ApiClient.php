<?php

namespace WHMCS\Module\Server\MetroVPSB2B;

class ApiClient
{
    protected $baseUrl;
    protected $apiKey;
    protected $apiSecret;
    protected $timeout;

    public function __construct(string $baseUrl, string $apiKey, string $apiSecret, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->timeout = $timeout;
    }

    /**
     * Call the MetroVPS B2B test-connection endpoint to validate credentials.
     *
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function test(): array
    {
        if (empty($this->baseUrl) || empty($this->apiKey) || empty($this->apiSecret)) {
            return [
                'success' => false,
                'error' => 'API base URL, API Key, and API Secret are required.',
                'data' => null,
            ];
        }

        $result = $this->request('GET', '/b2b/api/test-connection');

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . $body['status'] . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => null,
            ];
        }

        if (empty($body['data']['b2b_enabled'])) {
            return [
                'success' => false,
                'error' => 'B2B is not enabled on this MetroVPS account.',
                'data' => null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $body['data'],
        ];
    }

    /**
     * Fetch details for a single MetroVPS B2B product by its ID.
     *
     * @param string $productId
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function getProductDetails(string $productId): array
    {
        $result = $this->request('GET', '/b2b/api/products/' . urlencode($productId));

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . $body['status'] . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => null,
            ];
        }

        // API may wrap data in a 'data' key or return the product object directly
        $productData = $body['data'] ?? $body;

        if (empty($productData)) {
            return [
                'success' => false,
                'error' => 'No product data returned.',
                'data' => null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $productData,
        ];
    }

    /**
     * Fetch available OS templates from the MetroVPS B2B API.
     *
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function getOsTemplates(): array
    {
        $result = $this->request('GET', '/b2b/api/os-templates');

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . $body['status'] . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => null,
            ];
        }

        $templates = $body['data'] ?? [];

        if (empty($templates) || !is_array($templates)) {
            return [
                'success' => false,
                'error' => 'No OS templates returned.',
                'data' => null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $templates,
        ];
    }

    /**
     * Fetch VPS details from MetroVPS (used by callback webhook to sync status).
     *
     * @param int $serviceId
     * @param int $b2bOrderId
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function getVpsDetails(int $serviceId, int $b2bOrderId): array
    {
        $endpoint = '/b2b/api/vps-details?service_id=' . $serviceId . '&b2b_order_id=' . $b2bOrderId;
        $result = $this->request('GET', $endpoint);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . ($body['status'] ?? 'unknown') . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $body['data'] ?? $body,
        ];
    }

    /**
     * Fetch details for an existing VPS (not provisioned through WHMCS) by its
     * MetroVPS service ID. Uses the `existing=true` query flag so the platform
     * does not require a B2B order ID.
     *
     * @param int $serviceId MetroVPS service ID of the existing VPS
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function getExistingVpsDetails(int $serviceId): array
    {
        $endpoint = '/b2b/api/vps-details?service_id=' . $serviceId . '&existing=true';
        $result = $this->request('GET', $endpoint);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . ($body['status'] ?? 'unknown') . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $body['data'] ?? $body,
        ];
    }

    /**
     * Provision a new VPS via the MetroVPS B2B API.
     *
     * @param array $payload
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function provisionVps(array $payload): array
    {
        $result = $this->request('POST', '/b2b/api/provision-vps', $payload);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . ($body['status'] ?? 'unknown') . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => $body['data'] ?? null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $body['data'] ?? $body,
        ];
    }

    /**
     * Send a power action (boot / shutdown / restart) for a VPS.
     *
     * @param int    $serviceId
     * @param int    $b2bOrderId
     * @param string $action "boot", "shutdown" or "restart"
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function powerAction(int $serviceId, int $b2bOrderId, string $action): array
    {
        $result = $this->request('POST', '/b2b/api/vps/power', [
            'service_id'   => $serviceId,
            'b2b_order_id' => $b2bOrderId,
            'action'       => $action,
        ]);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . ($body['status'] ?? 'unknown') . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => $body['data'] ?? null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $body['data'] ?? $body,
        ];
    }

    /**
     * Reset the root password of a VPS via the MetroVPS B2B API.
     *
     * @param int $serviceId
     * @param int $b2bOrderId
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function resetPassword(int $serviceId, int $b2bOrderId): array
    {
        $result = $this->request('POST', '/b2b/api/vps/reset-password', [
            'service_id'   => $serviceId,
            'b2b_order_id' => $b2bOrderId,
        ]);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . ($body['status'] ?? 'unknown') . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => $body['data'] ?? null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $body['data'] ?? $body,
        ];
    }

    /**
     * Rebuild (reinstall) a VPS from an OS template via the MetroVPS B2B API.
     *
     * @param int    $serviceId
     * @param int    $b2bOrderId
     * @param int    $templateId
     * @param string $hostname
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function rebuild(int $serviceId, int $b2bOrderId, int $templateId, string $hostname): array
    {
        $result = $this->request('POST', '/b2b/api/vps/rebuild', [
            'service_id'   => $serviceId,
            'b2b_order_id' => $b2bOrderId,
            'template_id'  => $templateId,
            'hostname'     => $hostname,
        ]);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . ($body['status'] ?? 'unknown') . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => $body['data'] ?? null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $body['data'] ?? $body,
        ];
    }

    /**
     * Suspend a VPS via the MetroVPS B2B API.
     *
     * @param int    $serviceId
     * @param int    $b2bOrderId
     * @param string $reason
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function suspendVps(int $serviceId, int $b2bOrderId, string $reason = ''): array
    {
        $result = $this->request('POST', '/b2b/api/vps/suspend', [
            'service_id'   => $serviceId,
            'b2b_order_id' => $b2bOrderId,
            'reason'       => $reason,
        ]);

        return $this->unwrap($result);
    }

    /**
     * Unsuspend a VPS via the MetroVPS B2B API.
     *
     * @param int $serviceId
     * @param int $b2bOrderId
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function unsuspendVps(int $serviceId, int $b2bOrderId): array
    {
        $result = $this->request('POST', '/b2b/api/vps/unsuspend', [
            'service_id'   => $serviceId,
            'b2b_order_id' => $b2bOrderId,
        ]);

        return $this->unwrap($result);
    }

    /**
     * Renew a VPS via the MetroVPS B2B API.
     *
     * @param int $serviceId   MetroVPS service ID
     * @param int $b2bOrderId  MetroVPS B2B order ID
     * @return array ['success' => bool, 'error' => string, 'data' => array|null]
     */
    public function renewVps(int $serviceId, int $b2bOrderId): array
    {
        $result = $this->request('POST', '/b2b/api/vps/renew', [
            'service_id'   => $serviceId,
            'b2b_order_id' => $b2bOrderId,
        ]);

        return $this->unwrap($result);
    }

    /**
     * Normalize a raw request() result into the shared API-result shape.
     *
     * @param array $result Raw result from request()
     * @return array
     */
    protected function unwrap(array $result): array
    {
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
                'data' => null,
            ];
        }

        $body = $result['body'];

        if (!is_array($body)) {
            return [
                'success' => false,
                'error' => 'Invalid response from MetroVPS API.',
                'data' => null,
            ];
        }

        if (isset($body['status']) && $body['status'] !== 200) {
            return [
                'success' => false,
                'error' => 'MetroVPS API returned status ' . ($body['status'] ?? 'unknown') . ': ' . ($body['message'] ?? 'Unknown error'),
                'data' => $body['data'] ?? null,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'data' => $body['data'] ?? $body,
        ];
    }

    /**
     * Send a GET request.
     *
     * @param string $endpoint
     * @return array
     */
    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    /**
     * Send a POST request.
     *
     * @param string $endpoint
     * @param array $payload
     * @return array
     */
    public function post(string $endpoint, array $payload = []): array
    {
        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * Send a PUT request.
     *
     * @param string $endpoint
     * @param array $payload
     * @return array
     */
    public function put(string $endpoint, array $payload = []): array
    {
        return $this->request('PUT', $endpoint, $payload);
    }

    /**
     * Send a DELETE request.
     *
     * @param string $endpoint
     * @return array
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Low-level cURL wrapper.
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $payload
     * @return array
     */
    protected function request(string $method, string $endpoint, ?array $payload = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $ch = curl_init();
        $headers = $this->buildHeaders($method);

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WHMCS-MetroVPSB2B/1.0',
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        logModuleCall(
            'MetroVPSB2B',
            $method . ' ' . $url,
            [
                'payload' => $payload,
                'headers' => $headers,
            ],
            $response,
            $curlError ?: 'HTTP ' . $httpCode
        );

        if ($curlError) {
            return [
                'success' => false,
                'error' => 'cURL error: ' . $curlError,
                'http_code' => 0,
                'body' => null,
            ];
        }

        $body = json_decode($response, true);

        $error = '';
        if ($httpCode < 200 || $httpCode >= 300) {
            $error = 'HTTP ' . $httpCode;
            if ($body !== null) {
                $apiMessage = is_array($body) ? ($body['message'] ?? '') : '';
                if ($apiMessage && is_string($apiMessage) && $apiMessage !== '') {
                    $error .= ': ' . $apiMessage;
                } else {
                    $detail = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
                    $error .= ' — ' . mb_substr($detail, 0, 500);
                }
            }
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'error' => $error,
            'http_code' => $httpCode,
            'body' => $body !== null ? $body : $response,
        ];
    }

    /**
     * Build HTTP headers including MetroVPS B2B authentication.
     *
     * @param string $method
     * @return array
     */
    protected function buildHeaders(string $method): array
    {
        $headers = [
            'Accept: application/json',
            'X-B2B-API-Key: ' . $this->apiKey,
            'X-B2B-API-Secret: ' . $this->apiSecret,
        ];

        if (strtoupper($method) !== 'GET' && strtoupper($method) !== 'DELETE') {
            $headers[] = 'Content-Type: application/json';
        }

        return $headers;
    }
}
