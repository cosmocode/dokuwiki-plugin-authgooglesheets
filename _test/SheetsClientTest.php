<?php

namespace dokuwiki\plugin\authgooglesheets\test;

use dokuwiki\plugin\authgooglesheets\ServiceAccountAuth;
use dokuwiki\plugin\authgooglesheets\SheetsClient;
use dokuwiki\plugin\authgooglesheets\SheetsException;
use DokuWikiTest;

/**
 * Tests for the Sheets API requests
 *
 * @group plugin_authgooglesheets
 * @group plugins
 */
class SheetsClientTest extends DokuWikiTest
{
    /** @var string */
    protected const BASE = 'https://sheets.googleapis.com/v4/spreadsheets/sheet-123';

    /** @var HTTPClientStub */
    protected $http;

    /** @var SheetsClient */
    protected $client;

    /** @inheritdoc */
    public function setUp(): void
    {
        parent::setUp();

        // skip credentials and token requests, those are covered by ServiceAccountAuthTest
        $auth = new class extends ServiceAccountAuth {
            /** Does not read any credentials */
            public function __construct()
            {
            }

            /** @inheritdoc */
            public function getAccessToken(): string
            {
                return 'test-token';
            }
        };

        $this->http = new HTTPClientStub();
        $this->client = new SheetsClient('sheet-123', $auth);
        $this->setInaccessibleProperty($this->client, 'http', $this->http);
    }

    /**
     * Returns the last recorded request
     *
     * @return array
     */
    protected function lastRequest(): array
    {
        return end($this->http->requests);
    }

    /**
     * Values are read with a GET to the URL-encoded range
     */
    public function testGetValues()
    {
        $rows = [['user', 'pass', 'name', 'mail', 'grps'], ['alice', 'hash', 'Alice', 'a@example.com']];
        $this->http->addResponse(200, ['range' => 'DokuWikiAuth!A1:Z1000', 'majorDimension' => 'ROWS', 'values' => $rows]);

        $this->assertSame($rows, $this->client->getValues('DokuWikiAuth!A1:Z'));

        $request = $this->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame(self::BASE . '/values/DokuWikiAuth%21A1%3AZ', $request['url']);
        $this->assertSame('Bearer test-token', $request['headers']['Authorization']);
        $this->assertSame('', $request['data']);
    }

    /**
     * Sheet names with spaces are encoded in the path
     */
    public function testGetValuesEncodesSpaces()
    {
        $this->http->addResponse(200, ['range' => 'x', 'values' => []]);
        $this->client->getValues('My Users!1:1');
        $this->assertSame(self::BASE . '/values/My%20Users%211%3A1', $this->lastRequest()['url']);
    }

    /**
     * Google omits "values" for empty ranges
     */
    public function testGetValuesEmptyRange()
    {
        $this->http->addResponse(200, ['range' => 'DokuWikiAuth!A1:Z1000', 'majorDimension' => 'ROWS']);
        $this->assertSame([], $this->client->getValues('DokuWikiAuth!A1:Z'));
    }

    /**
     * Appending posts the rows as JSON with valueInputOption in the query
     */
    public function testAppendValues()
    {
        $this->http->addResponse(200, ['spreadsheetId' => 'sheet-123', 'updates' => []]);
        $this->client->appendValues('DokuWikiAuth!A2', [['bob', 'hash', 'Bob', 'b@example.com']]);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame(self::BASE . '/values/DokuWikiAuth%21A2:append?valueInputOption=RAW', $request['url']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame(['values' => [['bob', 'hash', 'Bob', 'b@example.com']]], json_decode($request['data'], true));
    }

    /**
     * Batch value updates post valueInputOption and data in the body
     */
    public function testBatchUpdateValues()
    {
        $data = [['range' => 'DokuWikiAuth!C2', 'values' => [['New Name']]]];
        $this->http->addResponse(200, ['spreadsheetId' => 'sheet-123', 'totalUpdatedCells' => 1]);
        $this->client->batchUpdateValues($data);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame(self::BASE . '/values:batchUpdate', $request['url']);
        $this->assertSame(['valueInputOption' => 'RAW', 'data' => $data], json_decode($request['data'], true));
    }

    /**
     * Spreadsheet batch updates are posted to the spreadsheet itself
     */
    public function testBatchUpdate()
    {
        $requests = [[
            'deleteDimension' => [
                'range' => ['sheetId' => '0', 'dimension' => 'ROWS', 'startIndex' => 2, 'endIndex' => 3],
            ],
        ]];
        $this->http->addResponse(200, ['spreadsheetId' => 'sheet-123', 'replies' => [[]]]);
        $this->client->batchUpdate($requests);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame(self::BASE . ':batchUpdate', $request['url']);
        $this->assertSame(['requests' => $requests], json_decode($request['data'], true));
    }

    /**
     * A GET after a POST on the same client does not send a stale Content-Type
     */
    public function testGetAfterPostDropsContentType()
    {
        $this->http->addResponse(200, []);
        $this->http->addResponse(200, ['values' => []]);
        $this->client->batchUpdate([]);
        $this->client->getValues('A1');

        $this->assertArrayNotHasKey('Content-Type', $this->lastRequest()['headers']);
    }

    /**
     * API errors are reported with Google's message
     */
    public function testErrorResponse()
    {
        $this->http->addResponse(403, [
            'error' => ['code' => 403, 'message' => 'The caller does not have permission', 'status' => 'PERMISSION_DENIED'],
        ]);

        $this->expectException(SheetsException::class);
        $this->expectExceptionMessage('The caller does not have permission');
        $this->client->getValues('DokuWikiAuth!1:1');
    }

    /**
     * Error responses without a JSON body still fail with the HTTP status
     */
    public function testErrorResponseWithoutBody()
    {
        $this->http->addResponse(502, '<html>Bad Gateway</html>');

        $this->expectException(SheetsException::class);
        $this->expectExceptionMessage('HTTP 502');
        $this->client->batchUpdate([]);
    }

    /**
     * Transport errors are reported
     */
    public function testTransportError()
    {
        $this->http->addTransportError('Could not connect to ssl://sheets.googleapis.com:443');

        $this->expectException(SheetsException::class);
        $this->expectExceptionMessage('Could not connect');
        $this->client->getValues('DokuWikiAuth!1:1');
    }
}
