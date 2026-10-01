<?php

namespace dokuwiki\plugin\authgooglesheets\test;

use dokuwiki\HTTP\DokuHTTPClient;

/**
 * HTTP client that records requests and replays canned responses instead of using the network
 */
class HTTPClientStub extends DokuHTTPClient
{
    /** @var array[] recorded requests: ['url' => string, 'data' => mixed, 'method' => string, 'headers' => array] */
    public $requests = [];

    /** @var array[] queued responses: ['status' => int, 'body' => string] or ['error' => string] for transport errors */
    protected $responses = [];

    /**
     * Queues a response for the next request
     *
     * @param int $status HTTP status code
     * @param array|string $body response body, arrays are JSON encoded
     * @return void
     */
    public function addResponse(int $status, $body): void
    {
        $this->responses[] = [
            'status' => $status,
            'body' => is_array($body) ? json_encode($body) : $body,
        ];
    }

    /**
     * Queues a transport failure for the next request
     *
     * @param string $error error message as HTTPClient would set it
     * @return void
     */
    public function addTransportError(string $error): void
    {
        $this->responses[] = ['error' => $error];
    }

    /**
     * Records the request and returns the next queued response
     *
     * @param string $url
     * @param string|array $data
     * @param string $method
     * @return bool false for a queued transport error, true otherwise
     */
    public function sendRequest($url, $data = '', $method = 'GET')
    {
        $this->requests[] = [
            'url' => $url,
            'data' => $data,
            'method' => $method,
            'headers' => $this->headers,
        ];

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('No response queued for ' . $method . ' ' . $url);
        }

        if (isset($response['error'])) {
            $this->status = -100;
            $this->error = $response['error'];
            $this->resp_body = '';
            return false;
        }

        $this->status = $response['status'];
        $this->error = '';
        $this->resp_body = $response['body'];
        return true;
    }
}
