<?php

namespace dokuwiki\plugin\authgooglesheets\test;

use dokuwiki\plugin\authgooglesheets\ServiceAccountAuth;
use dokuwiki\plugin\authgooglesheets\SheetsClient;
use dokuwiki\plugin\authgooglesheets\SheetsException;
use DokuWikiTest;

/**
 * Tests for the service account token handling
 *
 * @group plugin_authgooglesheets
 * @group plugins
 */
class ServiceAccountAuthTest extends DokuWikiTest
{
    /** @var string PEM encoded private key generated for each test */
    protected $privateKey;

    /** @var string PEM encoded public key matching $privateKey */
    protected $publicKey;

    /** @var string unique per test so cached tokens do not leak between tests */
    protected $clientEmail;

    /** @inheritdoc */
    public function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->clientEmail = uniqid('wiki-') . '@example.iam.gserviceaccount.com';
    }

    /**
     * Writes a credentials file and returns its path
     *
     * @param array $overrides fields to replace in the default service account key, null removes a field
     * @return string
     */
    protected function writeCredentials(array $overrides = []): string
    {
        $credentials = array_merge([
            'type' => 'service_account',
            'project_id' => 'test',
            'private_key_id' => 'abc',
            'private_key' => $this->privateKey,
            'client_email' => $this->clientEmail,
            'token_uri' => 'https://oauth2.example.com/token',
        ], $overrides);
        $credentials = array_filter($credentials, static fn($value) => $value !== null);

        $file = io_mktmpdir() . '/credentials.json';
        file_put_contents($file, json_encode($credentials));
        return $file;
    }

    /**
     * Creates the auth object with the HTTP client replaced by a stub
     *
     * @param string $credentialsFile
     * @param HTTPClientStub $http
     * @return ServiceAccountAuth
     */
    protected function createAuth(string $credentialsFile, HTTPClientStub $http): ServiceAccountAuth
    {
        $auth = new ServiceAccountAuth($credentialsFile, [SheetsClient::SCOPE]);
        $this->setInaccessibleProperty($auth, 'http', $http);
        return $auth;
    }

    /**
     * Decodes a base64url encoded JWT segment
     *
     * @param string $segment
     * @return string
     */
    protected function base64urlDecode(string $segment): string
    {
        return base64_decode(strtr($segment, '-_', '+/'));
    }

    /**
     * The JWT carries the expected claims and a valid RS256 signature
     */
    public function testCreateJwt()
    {
        $auth = new ServiceAccountAuth($this->writeCredentials(), [SheetsClient::SCOPE]);
        $jwt = $this->callInaccessibleMethod($auth, 'createJwt', [1_700_000_000]);

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);
        foreach ($parts as $part) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $part);
        }

        $this->assertSame(
            ['alg' => 'RS256', 'typ' => 'JWT'],
            json_decode($this->base64urlDecode($parts[0]), true)
        );
        $this->assertSame(
            [
                'iss' => $this->clientEmail,
                'scope' => SheetsClient::SCOPE,
                'aud' => 'https://oauth2.example.com/token',
                'iat' => 1_700_000_000,
                'exp' => 1_700_003_600,
            ],
            json_decode($this->base64urlDecode($parts[1]), true)
        );

        $verified = openssl_verify(
            $parts[0] . '.' . $parts[1],
            $this->base64urlDecode($parts[2]),
            $this->publicKey,
            OPENSSL_ALGO_SHA256
        );
        $this->assertSame(1, $verified);
    }

    /**
     * Without token_uri in the credentials Google's default endpoint is used
     */
    public function testDefaultTokenUri()
    {
        $http = new HTTPClientStub();
        $http->addResponse(200, ['access_token' => 'tok', 'expires_in' => 3599]);
        $auth = $this->createAuth($this->writeCredentials(['token_uri' => null]), $http);

        $auth->getAccessToken();

        $this->assertSame(ServiceAccountAuth::DEFAULT_TOKEN_URI, $http->requests[0]['url']);
    }

    /**
     * The token request uses the JWT bearer grant and the token is cached afterwards
     */
    public function testGetAccessTokenIsCached()
    {
        $http = new HTTPClientStub();
        $http->addResponse(200, ['access_token' => 'tok-1', 'expires_in' => 3599, 'token_type' => 'Bearer']);
        $credentials = $this->writeCredentials();

        $auth = $this->createAuth($credentials, $http);
        $this->assertSame('tok-1', $auth->getAccessToken());
        $this->assertSame('tok-1', $auth->getAccessToken());

        $this->assertCount(1, $http->requests);
        $request = $http->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://oauth2.example.com/token', $request['url']);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $request['data']['grant_type']);
        $this->assertCount(3, explode('.', $request['data']['assertion']));

        // a new instance, as in the next PHP request, reads the token from the file cache
        $auth = $this->createAuth($credentials, $http);
        $this->assertSame('tok-1', $auth->getAccessToken());
        $this->assertCount(1, $http->requests);
    }

    /**
     * A token about to expire is not reused
     */
    public function testExpiredTokenIsRefreshed()
    {
        $http = new HTTPClientStub();
        $http->addResponse(200, ['access_token' => 'short', 'expires_in' => 30]);
        $http->addResponse(200, ['access_token' => 'fresh', 'expires_in' => 3599]);

        $auth = $this->createAuth($this->writeCredentials(), $http);
        $this->assertSame('short', $auth->getAccessToken());
        $this->assertSame('fresh', $auth->getAccessToken());
        $this->assertCount(2, $http->requests);
    }

    /**
     * Error responses from the token endpoint are reported with Google's description
     */
    public function testTokenErrorResponse()
    {
        $http = new HTTPClientStub();
        $http->addResponse(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.']);
        $auth = $this->createAuth($this->writeCredentials(), $http);

        $this->expectException(SheetsException::class);
        $this->expectExceptionMessage('Invalid JWT Signature.');
        $auth->getAccessToken();
    }

    /**
     * Transport errors during the token request are reported
     */
    public function testTokenTransportError()
    {
        $http = new HTTPClientStub();
        $http->addTransportError('Could not connect');
        $auth = $this->createAuth($this->writeCredentials(), $http);

        $this->expectException(SheetsException::class);
        $this->expectExceptionMessage('Could not connect');
        $auth->getAccessToken();
    }

    /**
     * A key that openssl cannot read is reported when signing
     */
    public function testInvalidPrivateKey()
    {
        $auth = $this->createAuth($this->writeCredentials(['private_key' => 'not a key']), new HTTPClientStub());

        $this->expectException(SheetsException::class);
        $this->expectExceptionMessage('Invalid private key in credentials');
        $auth->getAccessToken();
    }

    /**
     * @return array[] credentials file contents and expected exception message
     */
    public function provideInvalidCredentials(): array
    {
        return [
            'not json' => ['{nope', 'not valid JSON'],
            'oauth client' => [json_encode(['installed' => ['client_id' => 'x']]), 'not a service account key'],
            'wrong type' => [json_encode(['type' => 'authorized_user']), 'not a service account key'],
            'no email' => [json_encode(['type' => 'service_account', 'private_key' => 'k']), 'lacks client_email'],
            'no key' => [json_encode(['type' => 'service_account', 'client_email' => 'a@b']), 'lacks client_email'],
        ];
    }

    /**
     * Unusable credentials files are rejected on construction
     *
     * @dataProvider provideInvalidCredentials
     * @param string $content
     * @param string $message
     */
    public function testInvalidCredentials(string $content, string $message)
    {
        $file = io_mktmpdir() . '/credentials.json';
        file_put_contents($file, $content);

        $this->expectException(SheetsException::class);
        $this->expectExceptionMessage($message);
        new ServiceAccountAuth($file, [SheetsClient::SCOPE]);
    }

    /**
     * A missing credentials file keeps the message the plugin always showed
     */
    public function testMissingCredentials()
    {
        $this->expectException(SheetsException::class);
        $this->expectExceptionMessage('Authentication configuration missing!');
        new ServiceAccountAuth('/does/not/exist.json', [SheetsClient::SCOPE]);
    }
}
