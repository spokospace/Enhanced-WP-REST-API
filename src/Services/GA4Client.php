<?php

declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Services;

/**
 * Google Analytics 4 Data API Client
 *
 * Uses Service Account authentication with JWT tokens.
 * Communicates with GA4 Data API v1 via WordPress HTTP API.
 *
 * @see https://developers.google.com/analytics/devguides/reporting/data/v1
 */
class GA4Client
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const GA4_API_BASE = 'https://analyticsdata.googleapis.com/v1beta/properties/';
    private const TOKEN_CACHE_KEY = 'spoko_ga4_access_token';
    private const TOKEN_CACHE_GROUP = 'spoko_rest_api';

    private ?array $credentials = null;
    private ?string $accessToken = null;
    private string $lastError = '';

    public function __construct(
        private ErrorLogger $logger
    ) {}

    /**
     * Fetch page view data from GA4
     *
     * @param string $propertyId GA4 Property ID (numeric)
     * @param string $period Date range: 7d, 14d, 30d, 90d
     * @param int $limit Maximum results to return
     * @return array Array of ['path' => string, 'pageviews' => int]
     */
    public function fetchPageViews(string $propertyId, string $period = '30d', int $limit = 20): array
    {
        $credentials = $this->getCredentials();
        if (!$credentials) {
            $this->lastError = 'No credentials configured';
            return [];
        }

        $accessToken = $this->getAccessToken($credentials);
        if (!$accessToken) {
            $this->lastError = 'Failed to obtain access token: ' . $this->lastError;
            return [];
        }

        return $this->runReport($propertyId, $accessToken, $period, $limit);
    }

    /**
     * Get debug info for troubleshooting
     */
    public function getDebugInfo(string $propertyId): array
    {
        $info = [
            'property_id' => $propertyId,
            'has_credentials' => false,
            'credentials_email' => null,
            'token_obtained' => false,
            'token_error' => null,
            'api_error' => null,
        ];

        $credentials = $this->getCredentials();
        if ($credentials) {
            $info['has_credentials'] = true;
            $info['credentials_email'] = $credentials['client_email'] ?? 'missing';
        } else {
            $info['token_error'] = 'No credentials';
            return $info;
        }

        $accessToken = $this->getAccessToken($credentials);
        if ($accessToken) {
            $info['token_obtained'] = true;
        } else {
            $info['token_error'] = $this->lastError;
            return $info;
        }

        // Try API call
        $this->runReport($propertyId, $accessToken, '1d', 1);
        $info['api_error'] = $this->lastError ?: 'none';

        return $info;
    }

    /**
     * Test connection to GA4 API
     *
     * @param string $propertyId GA4 Property ID
     * @return array ['success' => bool, 'message' => string, 'property_name' => ?string]
     */
    public function testConnection(string $propertyId): array
    {
        $credentials = $this->getCredentials();
        if (!$credentials) {
            return [
                'success' => false,
                'message' => 'No credentials configured. Please add your Service Account JSON.',
                'property_name' => null
            ];
        }

        $accessToken = $this->getAccessToken($credentials);
        if (!$accessToken) {
            return [
                'success' => false,
                'message' => 'Failed to authenticate with Google. Check your Service Account credentials.',
                'property_name' => null
            ];
        }

        // Try a minimal report to verify property access
        $result = $this->runReport($propertyId, $accessToken, '1d', 1);

        if ($result === []) {
            // Check if it's an empty result or an error
            $lastError = $this->getLastError();
            if ($lastError) {
                return [
                    'success' => false,
                    'message' => "API Error: {$lastError}",
                    'property_name' => null
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Successfully connected to GA4 property.',
            'property_name' => "Property {$propertyId}"
        ];
    }

    /**
     * Parse credentials from stored option
     */
    private function getCredentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $credentialsJson = get_option('spoko_rest_ga4_credentials', '');
        if (empty($credentialsJson)) {
            return null;
        }

        $credentials = json_decode($credentialsJson, true);
        if (!$credentials || !isset($credentials['client_email'], $credentials['private_key'])) {
            $this->logger->logError('GA4: Invalid credentials format');
            return null;
        }

        $this->credentials = $credentials;
        return $credentials;
    }

    /**
     * Get or refresh OAuth2 access token using JWT
     */
    private function getAccessToken(array $credentials): ?string
    {
        // Check cache first
        $cached = wp_cache_get(self::TOKEN_CACHE_KEY, self::TOKEN_CACHE_GROUP);
        if ($cached && isset($cached['token'], $cached['expires']) && $cached['expires'] > time()) {
            return $cached['token'];
        }

        $jwt = $this->createJWT($credentials);
        if (!$jwt) {
            return null;
        }

        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'timeout' => 10,
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]
        ]);

        if (is_wp_error($response)) {
            $this->lastError = 'Token request failed: ' . $response->get_error_message();
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['access_token'])) {
            $this->logger->logError('GA4: Invalid token response', [
                'response' => $body
            ]);
            return null;
        }

        // Cache token (usually valid for 1 hour, cache for 55 minutes)
        $expiresIn = $body['expires_in'] ?? 3600;
        wp_cache_set(
            self::TOKEN_CACHE_KEY,
            [
                'token' => $body['access_token'],
                'expires' => time() + $expiresIn - 300
            ],
            self::TOKEN_CACHE_GROUP,
            $expiresIn - 300
        );

        $this->accessToken = $body['access_token'];
        return $this->accessToken;
    }

    /**
     * Create JWT for Service Account authentication
     */
    private function createJWT(array $credentials): ?string
    {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];

        $now = time();
        $payload = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud' => self::TOKEN_ENDPOINT,
            'iat' => $now,
            'exp' => $now + 3600
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signatureInput = "{$headerEncoded}.{$payloadEncoded}";

        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if (!$privateKey) {
            $this->logger->logError('GA4: Invalid private key');
            return null;
        }

        $signature = '';
        $signResult = openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$signResult) {
            $this->logger->logError('GA4: Failed to sign JWT');
            return null;
        }

        return "{$signatureInput}." . $this->base64UrlEncode($signature);
    }

    /**
     * Run GA4 Data API report
     */
    private function runReport(string $propertyId, string $accessToken, string $period, int $limit): array
    {
        $startDate = $this->parseperiodToDate($period);

        $requestBody = [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => 'today']
            ],
            'dimensions' => [
                ['name' => 'pagePath']
            ],
            'metrics' => [
                ['name' => 'screenPageViews']
            ],
            'orderBys' => [
                [
                    'metric' => ['metricName' => 'screenPageViews'],
                    'desc' => true
                ]
            ],
            'limit' => $limit * 3, // Fetch more to account for filtering
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'pagePath',
                    'stringFilter' => [
                        'matchType' => 'BEGINS_WITH',
                        'value' => '/'
                    ]
                ]
            ]
        ];

        $response = wp_remote_post(
            self::GA4_API_BASE . $propertyId . ':runReport',
            [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($requestBody)
            ]
        );

        if (is_wp_error($response)) {
            $this->logger->logError('GA4: Report request failed', [
                'error' => $response->get_error_message()
            ]);
            $this->lastError = $response->get_error_message();
            return [];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200) {
            $errorMessage = $body['error']['message'] ?? 'Unknown error';
            $this->logger->logError('GA4: API error', [
                'status' => $statusCode,
                'error' => $errorMessage
            ]);
            $this->lastError = $errorMessage;
            return [];
        }

        return $this->parseReportResponse($body);
    }

    private function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Parse GA4 API response into simplified array
     */
    private function parseReportResponse(array $response): array
    {
        $results = [];

        if (!isset($response['rows'])) {
            return $results;
        }

        foreach ($response['rows'] as $row) {
            $path = $row['dimensionValues'][0]['value'] ?? '';
            $pageviews = (int) ($row['metricValues'][0]['value'] ?? 0);

            // Skip empty paths, admin paths, and common non-content paths
            if (
                empty($path) ||
                $path === '/' ||
                strpos($path, '/wp-admin') === 0 ||
                strpos($path, '/wp-json') === 0 ||
                strpos($path, '/wp-login') === 0 ||
                strpos($path, '/wp-content') === 0 ||
                strpos($path, '/?') === 0
            ) {
                continue;
            }

            $results[] = [
                'path' => $path,
                'pageviews' => $pageviews
            ];
        }

        return $results;
    }

    /**
     * Convert period string to start date
     */
    private function parseperiodToDate(string $period): string
    {
        $days = match ($period) {
            '7d' => 7,
            '14d' => 14,
            '30d' => 30,
            '90d' => 90,
            '1d' => 1,
            default => 30
        };

        return date('Y-m-d', strtotime("-{$days} days"));
    }

    /**
     * Base64 URL-safe encoding
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
