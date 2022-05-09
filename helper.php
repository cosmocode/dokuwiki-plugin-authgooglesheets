<?php

/**
 * DokuWiki Plugin authgooglesheets (Helper Component)
 *
 * @author  Anna Dabrowska <dokuwiki@cosmocode.de>
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */

use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateValuesRequest;

require_once(__DIR__ . '/vendor/autoload.php');

/**
 * Class helper_plugin_authgooglesheets
 */
class helper_plugin_authgooglesheets extends DokuWiki_Plugin
{
    /** @var Google_Service_Sheets */
    protected $service;
    protected $spreadsheetId;

    protected $users = [];
    protected $columnMap = [];

    protected $alpha = 'ABCDEFGHIJKLMNOPQRSTVWXYZ';


    public function __construct()
    {
        try {
            $this->spreadsheetId = $this->getConf('sheetId');
            if (empty($this->spreadsheetId)) {
                throw new Exception('Google Spreadsheet ID not set!');
            }

            $client = $this->getClient();
            $this->service = new Google_Service_Sheets($client);
        } catch (Exception $e) {
            msg('Authentication Error: ' . $e->getMessage());
        }
    }

    /**
     * Returns user data or false if user does not exist
     *
     * @param string $user
     * @return array|false
     */
    public function getUserData($user)
    {
         $users = $this->getUsers();
         return $users[$user] ?? false;
    }

    /**
     * Returns user data as nested arrays
     *
     * @return array
     */
    public function getUsers()
    {
        if (empty($this->users)) {
            $values = $this->getSheet();

            $header = array_shift($values);
            $this->columnMap = array_flip($header);

            foreach ($values as $key => $row) {
                // bump row number because index starts from 1 and we already removed the header row
                $rowNum = $key + 2;

                // ignore invalid rows without required user properties
                if (!$row[$this->columnMap['user']] || !$row[$this->columnMap['pass']] || !$row[$this->columnMap['mail']]) {
                    continue;
                }

                $name = $row[$this->columnMap['name']] ?? '';
                $grps = $row[$this->columnMap['grps']] ?? '';

                $grps = array_map('trim', explode(',', $grps));
                $this->users[$row[$this->columnMap['user']]] = [
                    'pass' => $row[$this->columnMap['pass']],
                    'name' => $name,
                    'mail' => $row[$this->columnMap['mail']],
                    'grps' => $grps,
                    'row' => $rowNum
                ];
            }
        }

        return $this->users;
    }

    /**
     * Appends new user to auth sheet and writes a user creation stat
     *
     * @param array $userData
     * @return bool
     */
    public function appendUser($userData)
    {
        $range = $this->getConf('sheetName') . '!A2';
        $params = [
            'valueInputOption' => 'RAW'
        ];

        $data = [];
        foreach ($this->columnMap as $col => $index) {
            if ($col === 'pass') {
                $userData[$col] = auth_cryptPassword($userData[$col]);
            }
            $data[] = $userData[$col] ?? '';
        }

        $body = new \Google\Service\Sheets\ValueRange(['values' => [$data]]);
        try {
            $this->service->spreadsheets_values->append($this->spreadsheetId, $range, $body, $params);
        } catch (Exception $e) {
            msg('User cannot be added');
            return false;
        }
        // reset users
        $this->users = [];
        return true;
    }

    /**
     * @param string $user
     * @param array $changes Array in which keys specify columns
     * @return bool
     */
    public function update($user, $changes)
    {
        $rangeStart = $this->getConf('sheetName') . '!';

        $data = [];
        foreach ($changes as $col => $value) {
            if ($col === 'pass') {
                $value = auth_cryptPassword($value);
            }
            if ($col === 'grps') {
                $value = implode(',', $value);
            }
            $data[] = [
                'range' => $rangeStart . $this->alpha[$this->columnMap[$col]] . ($this->users[$user]['row']),
                'values' => [
                    [$value]
                ],
            ];
        }

        $body = new BatchUpdateValuesRequest(
            [
                'valueInputOption' => 'RAW',
                'data' => $data
            ]
        );

        try {
            $this->service->spreadsheets_values->batchUpdate($this->spreadsheetId, $body);
        } catch (Exception $e) {
            msg('Update failed');
            return false;
        }
        // reset users
        $this->users = [];
        return true;
    }

    /**
     * @param array $users
     * @return bool
     */
    public function delete($users)
    {
        if (empty($users)) return false;

        // FIXME load users somewhere else
        $this->users = $this->getUsers();

        $requests = [];

        $users = array_reverse($users);
        foreach ($users as $user) {
            $rowNum = $this->users[$user]['row'];

            $requests[] = [
                "deleteDimension" => [
                    "range" => [
                        "sheetId" => 0,
                        "dimension" => "ROWS",
                        "startIndex" => $rowNum - 1, // 0 based index here!
                        "endIndex" => $rowNum
                    ]
                ]
            ];
        }

        $body = new BatchUpdateSpreadsheetRequest(
            [
                'requests' => $requests
            ]
        );

        try {
            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $body);
        } catch (Exception $e) {
            msg('Deletion failed');
            return false;
        }

        // reset users
        $this->users = [];
        return true;
    }

    /**
     * Returns all user rows from the auth sheet
     *
     * @return array[]
     */
    protected function getSheet()
    {
        $range = $this->getConf('sheetName') . '!A1:Z';
        $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $range);
        $values = $response->getValues();

        return $values;
    }

    public function validateSheet()
    {
        // FIXME check the existence and write access to the sheet
        $range = $this->getConf('sheetName') . '!1:1';
        $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $range);
        $header = $response->getValues();

        $cols = ['user', 'pass', 'name', 'mail', 'grps'];

        return array_intersect($cols, $header[0]) === $cols;
    }

    /**
     * Returns an authorized API client.
     *
     * @return Google_Client the authorized client object
     * @throws \Google\Exception
     */
    protected function getClient()
    {
        $client = new \Google_Client();
        $config = DOKU_CONF . 'authgooglesheets_credentials.json';
        if (!is_file($config)) {
            throw new Exception('Authentication configuration missing!');
        }
        $client->setAuthConfig($config);
        $client->setScopes([
            \Google_Service_Sheets::SPREADSHEETS,
        ]);
        return $client;
    }
}
