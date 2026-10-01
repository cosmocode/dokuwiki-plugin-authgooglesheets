<?php

namespace dokuwiki\plugin\authgooglesheets;

use dokuwiki\HTTP\DokuHTTPClient;

/**
 * Minimal client for the Google Sheets API v4
 *
 * Implements only the calls this plugin needs. Request bodies are passed as
 * plain arrays in the shape documented by the REST API.
 *
 * @link https://developers.google.com/sheets/api/reference/rest
 */
class SheetsClient
{
    /** @var string OAuth scope granting read and write access to spreadsheets */
    public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    /** @var string API base URL */
    protected const BASE_URL = 'https://sheets.googleapis.com/v4/spreadsheets/';

    /** @var string */
    protected $spreadsheetId;

    /** @var ServiceAccountAuth */
    protected $auth;

    /** @var DokuHTTPClient */
    protected $http;

    /**
     * @param string $spreadsheetId ID of the spreadsheet, the part between /d/ and /edit in its URL
     * @param ServiceAccountAuth $auth provides the access tokens
     */
    public function __construct(string $spreadsheetId, ServiceAccountAuth $auth)
    {
        $this->spreadsheetId = $spreadsheetId;
        $this->auth = $auth;
        $this->http = new DokuHTTPClient();
        $this->http->timeout = 30;
    }

    /**
     * Reads the values of a range
     *
     * @link https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets.values/get
     * @param string $range range in A1 notation, e.g. "Sheet1!A1:Z"
     * @return array[] rows of cell values, trailing empty cells omitted; empty if the range has no values
     * @throws SheetsException
     */
    public function getValues(string $range): array
    {
        $response = $this->request('GET', 'values/' . rawurlencode($range));
        return $response['values'] ?? [];
    }

    /**
     * Appends rows after the last row of the table found in the range
     *
     * @link https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets.values/append
     * @param string $range range in A1 notation used to find the table
     * @param array[] $rows rows of cell values
     * @param string $valueInputOption RAW or USER_ENTERED
     * @return void
     * @throws SheetsException
     */
    public function appendValues(string $range, array $rows, string $valueInputOption = 'RAW'): void
    {
        $this->request(
            'POST',
            'values/' . rawurlencode($range) . ':append',
            ['values' => $rows],
            ['valueInputOption' => $valueInputOption]
        );
    }

    /**
     * Writes values to one or more ranges
     *
     * @link https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets.values/batchUpdate
     * @param array[] $data list of ['range' => string, 'values' => array[]]
     * @param string $valueInputOption RAW or USER_ENTERED
     * @return void
     * @throws SheetsException
     */
    public function batchUpdateValues(array $data, string $valueInputOption = 'RAW'): void
    {
        $this->request(
            'POST',
            'values:batchUpdate',
            ['valueInputOption' => $valueInputOption, 'data' => $data]
        );
    }

    /**
     * Applies structural updates to the spreadsheet, e.g. deleting rows
     *
     * @link https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets/batchUpdate
     * @param array[] $requests list of request objects, e.g. ['deleteDimension' => [...]]
     * @return void
     * @throws SheetsException
     */
    public function batchUpdate(array $requests): void
    {
        $this->request('POST', ':batchUpdate', ['requests' => $requests]);
    }

    /**
     * Sends an authorized request to the spreadsheet's endpoint
     *
     * @param string $method GET or POST
     * @param string $path path relative to the spreadsheet URL, starting with "values" or ":"
     * @param array|null $body request body, sent as JSON
     * @param array $query query parameters
     * @return array decoded response
     * @throws SheetsException on transport errors and non-2xx responses
     */
    protected function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = self::BASE_URL . rawurlencode($this->spreadsheetId);
        // ":batchUpdate" attaches to the spreadsheet ID, "values/..." is a sub-path
        $url .= ($path[0] === ':' ? '' : '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $this->http->headers['Authorization'] = 'Bearer ' . $this->auth->getAccessToken();
        $data = '';
        if ($body !== null) {
            $this->http->headers['Content-Type'] = 'application/json';
            $data = json_encode($body);
        } else {
            unset($this->http->headers['Content-Type']);
        }

        if (!$this->http->sendRequest($url, $data, $method)) {
            throw new SheetsException('Sheets API request failed: ' . $this->http->error);
        }

        $response = json_decode((string)$this->http->resp_body, true);
        if ($this->http->status < 200 || $this->http->status > 299) {
            $reason = $response['error']['message'] ?? 'HTTP ' . $this->http->status;
            throw new SheetsException('Sheets API request failed: ' . $reason);
        }

        return is_array($response) ? $response : [];
    }
}
