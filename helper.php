<?php

/**
 * DokuWiki Plugin authgooglesheets (Helper Component)
 *
 * @author  Anna Dabrowska <dokuwiki@cosmocode.de>
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */

require_once(__DIR__ . '/vendor/autoload.php');

/**
 * Class helper_plugin_authgooglesheets
 */
class helper_plugin_authgooglesheets extends DokuWiki_Plugin
{
    /** @var Google_Service_Sheets */
    protected $service;

    protected $users = [];
    protected $columnMap = [];

    public function __construct()
    {
        $client = $this->getClient();
        $this->service = new Google_Service_Sheets($client);
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
     * @throws \dokuwiki\Exception\FatalException
     */
    public function getUsers()
    {
        if (empty($this->users)) {
            $values = $this->getSheet();

            $header = array_shift($values);
            foreach ($header as $index => $column) {
                $this->columnMap[$column] = $index;
            }

            foreach ($values as $key => $row) {
                $rowNum = $key + 1;
                $grps = array_map('trim', explode(',', $row[$this->columnMap['grps']]));
                $this->users[$row[$this->columnMap['user']]] = [
                    'pass' => $row[$this->columnMap['pass']],
                    'name' => $row[$this->columnMap['name']],
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
        $spreadsheetId = $this->getConf('sheetId');
        $range = $this->getConf('sheetName') . '!A2';
        $params = [
            'valueInputOption' => 'RAW'
        ];
        $body = new \Google\Service\Sheets\ValueRange(['values' => [$userData]]);
        try {
            $this->service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);
            // add stat 'user creation'
            $this->writeStat($userData[0], 'created', dformat(time()));
        } catch (Exception $e) {
            msg('User cannot be added');
            return false;
        }

        return true;
    }

    /**
     * Records the timestamp of an event, such as user creation or login
     *
     * @param string $user
     * @param string $stat
     * @param string $value
     * @return void
     */
    public function writeStat($user, $stat, $value)
    {
        $spreadsheetId = $this->getConf('sheetId');
        $range = $this->getConf('sheetNameStats') . '!A2';

        $params = [
            'valueInputOption' => 'RAW'
        ];
        $body = new \Google\Service\Sheets\ValueRange(['values' => [[$user, $stat, $value]]]);
        try {
            $this->service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);
        } catch (Exception $e) {
            msg('Stat cannot be added');
        }
    }

    /**
     * Returns all user rows from the auth sheet
     *
     * @return array[]
     * @throws \dokuwiki\Exception\FatalException
     */
    protected function getSheet()
    {
        $sheetId = $this->getConf('sheetId');

        if (empty($sheetId)) {
            throw new \dokuwiki\Exception\FatalException('Google Sheet ID not set!');
        }

        $spreadsheetId = $this->getConf('sheetId');
        $range = $this->getConf('sheetName') . '!A1:Z';
        $response = $this->service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();

        return $values;
    }

    public function validateSheet()
    {
        // FIXME check the existence and write access to the sheet
        return true;
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
        $client->setAuthConfig(DOKU_CONF . 'authgooglesheets_credentials.json');
        $client->setScopes([
            \Google_Service_Sheets::SPREADSHEETS,
        ]);
        return $client;
    }
}
