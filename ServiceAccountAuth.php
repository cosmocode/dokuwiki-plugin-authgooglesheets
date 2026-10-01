<?php

namespace dokuwiki\plugin\authgooglesheets;

use dokuwiki\Cache\Cache;
use dokuwiki\HTTP\DokuHTTPClient;

/**
 * OAuth 2.0 access tokens for a Google service account
 *
 * A JWT signed with the service account's private key is exchanged for a short-lived access token.
 * Tokens are kept in DokuWiki's cache.
 *
 * @link https://developers.google.com/identity/protocols/oauth2/service-account#httprest
 */
class ServiceAccountAuth
{
    /** @var string token endpoint used when the credentials file does not name one */
    public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var int lifetime of the signed JWT in seconds, Google allows at most one hour */
    protected const JWT_LIFETIME = 3600;

    /** @var int seconds before expiry at which a cached token is no longer used */
    protected const EXPIRY_MARGIN = 60;

    /** @var string service account email address, the JWT issuer */
    protected $clientEmail;

    /** @var string PEM encoded private key */
    protected $privateKey;

    /** @var string OAuth token endpoint */
    protected $tokenUri;

    /** @var string[] requested OAuth scopes */
    protected $scopes;

    /** @var DokuHTTPClient */
    protected $http;

    /** @var array|null token of the current request: ['access_token' => string, 'expires_at' => int] */
    protected $token;

    /**
     * Reads and validates the service account credentials
     *
     * @param string $credentialsFile path to the JSON key file downloaded from the Google Cloud console
     * @param string[] $scopes OAuth scopes to request
     * @throws SheetsException when the file is missing or not a usable service account key
     */
    public function __construct(string $credentialsFile, array $scopes)
    {
        if (!is_file($credentialsFile) || !is_readable($credentialsFile)) {
            throw new SheetsException('Authentication configuration missing!');
        }

        $credentials = json_decode((string)file_get_contents($credentialsFile), true);
        if (!is_array($credentials)) {
            throw new SheetsException('Authentication configuration is not valid JSON');
        }
        if (($credentials['type'] ?? '') !== 'service_account') {
            throw new SheetsException('Authentication configuration is not a service account key');
        }
        if (empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new SheetsException('Authentication configuration lacks client_email or private_key');
        }

        $this->clientEmail = $credentials['client_email'];
        $this->privateKey = $credentials['private_key'];
        $this->tokenUri = $credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI;
        $this->scopes = $scopes;
        $this->http = new DokuHTTPClient();
    }

    /**
     * Returns a valid access token, fetching a new one if needed
     *
     * @return string
     * @throws SheetsException when the token request fails
     */
    public function getAccessToken(): string
    {
        $now = time();
        if ($this->isUsable($this->token, $now)) {
            return $this->token['access_token'];
        }

        $cache = $this->getCache();
        $cached = json_decode((string)$cache->retrieveCache(), true);
        if ($this->isUsable($cached, $now)) {
            $this->token = $cached;
            return $this->token['access_token'];
        }

        $this->token = $this->fetchToken($now);
        $cache->storeCache(json_encode($this->token));
        return $this->token['access_token'];
    }

    /**
     * Exchanges a freshly signed JWT for an access token
     *
     * @param int $now current unix timestamp
     * @return array ['access_token' => string, 'expires_at' => int]
     * @throws SheetsException
     */
    protected function fetchToken(int $now): array
    {
        $params = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->createJwt($now),
        ];

        if (!$this->http->sendRequest($this->tokenUri, $params, 'POST')) {
            throw new SheetsException('Token request failed: ' . $this->http->error);
        }

        $response = json_decode((string)$this->http->resp_body, true);
        if ($this->http->status < 200 || $this->http->status > 299 || empty($response['access_token'])) {
            $reason = $response['error_description'] ?? $response['error'] ?? 'HTTP ' . $this->http->status;
            throw new SheetsException('Token request failed: ' . $reason);
        }

        return [
            'access_token' => $response['access_token'],
            'expires_at' => $now + (int)($response['expires_in'] ?? self::JWT_LIFETIME),
        ];
    }

    /**
     * Builds the signed JWT assertion for the token request
     *
     * @param int $now current unix timestamp, used as issue time
     * @return string header.claims.signature, each part base64url encoded
     * @throws SheetsException when the private key cannot be used
     */
    protected function createJwt(int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->clientEmail,
            'scope' => implode(' ', $this->scopes),
            'aud' => $this->tokenUri,
            'iat' => $now,
            'exp' => $now + self::JWT_LIFETIME,
        ];

        $input = self::base64url(json_encode($header)) . '.' . self::base64url(json_encode($claims));

        $key = openssl_pkey_get_private($this->privateKey);
        if ($key === false) {
            throw new SheetsException('Invalid private key in credentials');
        }
        if (!openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new SheetsException('Signing the token request failed');
        }

        return $input . '.' . self::base64url($signature);
    }

    /**
     * URL-safe base64 without padding, as required by JWT
     *
     * @param string $data
     * @return string
     */
    protected static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Checks whether a token is present and not about to expire
     *
     * @param mixed $token token array as returned by fetchToken(), anything else is unusable
     * @param int $now current unix timestamp
     * @return bool
     */
    protected function isUsable($token, int $now): bool
    {
        return is_array($token)
            && !empty($token['access_token'])
            && isset($token['expires_at'])
            && $token['expires_at'] - self::EXPIRY_MARGIN > $now;
    }

    /**
     * Cache holding the token for this service account and scope set
     *
     * @return Cache
     */
    protected function getCache(): Cache
    {
        $key = 'token:' . $this->clientEmail . ':' . $this->tokenUri . ':' . implode(' ', $this->scopes);
        return new Cache($key, '.authgooglesheets');
    }
}
