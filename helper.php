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

    public function __construct()
    {
        $client = $this->getClient();
        $this->service = new Google_Service_Sheets($client);
    }

    /**
     * Returns user data in typical userinfo format
     *
     * @param string $user
     * @return array|false
     */
    public function getUserFromSheet($user)
    {
        $row = $this->getUserRow($user);
        if (empty($row)) return false;

        $row = array_pop($row);
        $grps = array_map('trim', explode(',', $row[4]));
        return [
            'pass' => $row[1],
            'name' => $row[2],
            'mail' => $row[3],
            'grps' => $grps,
        ];
    }

    /**
     * Returns user data as nested arrays
     *
     * @return array
     * @throws \dokuwiki\Exception\FatalException
     */
    public function getUsers()
    {
        $users = [];
        $values = $this->getSheet();

        foreach ($values as $key => $row) {
            $grps = array_map('trim', explode(',', $row[4]));
            $users[$key] = [
                'user' => $row[0],
                'userinfo' => [
                    'pass' => $row[1],
                    'name' => $row[2],
                    'mail' => $row[3],
                    'grps' => $grps,
                ],
            ];
        }

        return $users;
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
            $this->writeStat($userData[0], 'CREATE', dformat(time()));
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
     *
     * @param string $user
     * @return array[]
     * @throws \dokuwiki\Exception\FatalException
     */
    protected function getUserRow($user)
    {
        $users = $this->getSheet();
        $row = array_filter(
            $users,
            function ($row) use ($user){
                return $row[0] === $user;
            }
        );

        if (count($row) > 1) {
            throw new Exception('Invalid user data');
        }

        return $row;
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
        $range = $this->getConf('sheetName') . '!A2:E';
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
