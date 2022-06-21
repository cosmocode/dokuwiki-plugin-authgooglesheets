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

    protected $userCacheId = 'userCache';
    protected $users = [];
    protected $requiredCols = ['user', 'pass', 'name', 'mail', 'grps'];
    protected $columnMap = [];

    protected $alpha = 'ABCDEFGHIJKLMNOPQRSTVWXYZ';
    protected $pattern;


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
    public function getUsers($start = 0, $limit = 0, $filter = null)
    {
        global $conf;
        global $INPUT;

        $userCache = new dokuwiki\Cache\Cache($this->userCacheId, 'authgooglesheets');
        $decoded = json_decode($userCache->retrieveCache(), true);

        $depends['age'] = $conf['cachetime'];
        $depends['purge'] = $INPUT->bool('purge');

        if (empty($decoded) || !$userCache->useCache($depends)) {
            $values = $this->getSheet();

            $header = array_shift($values);
            $this->columnMap = array_flip($header);

            foreach ($values as $key => $row) {
                // bump row number because index starts from 1 and we already removed the header row
                $rowNum = $key + 2;

                // ignore invalid rows without required user properties
                if (empty($row[$this->columnMap['user']]) || empty($row[$this->columnMap['pass']]) || empty($row[$this->columnMap['mail']])) {
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

            $userCache->storeCache(json_encode(['columnMap' => $this->columnMap, 'users' => $this->users]));
        } else {
            $this->users = $decoded['users'] ?? null;
            $this->columnMap = $decoded['columnMap'] ?? null;
        }

        ksort($this->users);

        return $this->getFilteredUsers($start, $limit, $filter);
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
        $this->resetUsers();
        return true;
    }

    /**
     * @param string $user
     * @param array $changes Array in which keys specify columns
     * @return bool
     */
    public function update($user, $changes)
    {
        // ensure variable is not empty, e.g. in user profile
        $this->users = $this->getUsers();

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
        $this->resetUsers();
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
                        "sheetId" => $this->getConf('sheetGid'),
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
        $this->resetUsers();
        return true;
    }

    /**
     * Filter implementation from authplain
     * @see \auth_plugin_authplain
     *
     * @param int $start
     * @param int $limit
     * @param array $filter
     * @return array
     */
    protected function getFilteredUsers($start, $limit, $filter)
    {
        $filter = $filter ?? [];
        $this->pattern = array();
        foreach ($filter as $item => $pattern) {
            $this->pattern[$item] = '/'.str_replace('/', '\/', $pattern).'/i'; // allow regex characters
        }

        $i = 0;
        $count = 0;
        $out = array();

        foreach ($this->users as $user => $info) {
            if ($this->filter($user, $info)) {
                if ($i >= $start) {
                    $out[$user] = $info;
                    $count++;
                    if (($limit > 0) && ($count >= $limit)) break;
                }
                $i++;
            }
        }

        return $out;
    }

    /**
     * return true if $user + $info match $filter criteria, false otherwise
     *
     * @author   Chris Smith <chris@jalakai.co.uk>
     *
     * @param string $user User login
     * @param array  $info User's userinfo array
     * @return bool
     */
    protected function filter($user, $info)
    {
        foreach ($this->pattern as $item => $pattern) {
            if ($item == 'user') {
                if (!preg_match($pattern, $user)) return false;
            } elseif ($item == 'grps') {
                if (!count(preg_grep($pattern, $info['grps']))) return false;
            } else {
                if (!preg_match($pattern, $info[$item])) return false;
            }
        }
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

    /**
     * Cached check if the sheet is valid, i.e. has all required columns
     *
     * @return bool
     */
    public function validateSheet()
    {
        $cache = new dokuwiki\Cache\Cache('validated', 'authgooglesheets');

        if ($cache->retrieveCache()) {
            return true;
        }

        $range = $this->getConf('sheetName') . '!1:1';
        $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $range);
        $header = $response->getValues();

        $isValid = array_intersect($this->requiredCols, $header[0]) === $this->requiredCols;

        if ($isValid) $cache->storeCache(time());

        return $isValid;
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

    /**
     * Clear users stored in class variable and filesystem cache
     *
     * @return void
     */
    protected function resetUsers()
    {
        $this->users = [];
        $userCache = new dokuwiki\Cache\Cache($this->userCacheId, 'authgooglesheets');
        $userCache->removeCache();
    }
}
