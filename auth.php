<?php

use dokuwiki\Extension\AuthPlugin;

/**
 * Class auth_plugin_authgooglesheets
 */
class auth_plugin_authgooglesheets extends AuthPlugin
{
    /** @var helper_plugin_authgooglesheets */
    protected $helper;

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->helper = plugin_load('helper', 'authgooglesheets');
        if ($this->helper->validateSheet()) {
            $this->cando['getUsers'] = true;
            $this->cando['addUser'] = true;
            $this->cando['delUser'] = true;
            $this->cando['modLogin'] = true;
            $this->cando['modPass'] = true;
            $this->cando['modName'] = true;
            $this->cando['modMail'] = true;
            $this->cando['modGroups'] = true;
        }
    }

    /**
     * @inheritDoc
     */
    public function checkPass($user, $pass)
    {
        $userinfo = $this->getUserData($user);
        if ($userinfo === false) return false;

        $verified = auth_verifyPassword($pass, $userinfo['pass']);

        // make sure to log the login
        if ($verified) {
            $this->helper->update($user, ['lastlogin' => dformat()]);
        }

        return $verified;
    }

    /**
     * Returns user info
     *
     * @param string $user
     * @param bool $requireGroups
     * @return array|false
     */
    public function getUserData($user, $requireGroups = true)
    {
        return $this->helper->getUserData($user);
    }

    /**
     * Creates a new user
     *
     * @param string $user
     * @param string $pwd
     * @param string $name
     * @param string $mail
     * @param array|null $grps
     * @return bool|null
     */
    public function createUser($user, $pwd, $name, $mail, $grps = null)
    {
        global $conf;

        // user mustn't already exist
        if ($this->getUserData($user) !== false) {
            msg($this->getLang('userexists'), -1);
            return false;
        }

        // the order is important
        $userData['user'] = $user;
        $userData['pass'] = $pwd;
        $userData['name'] = $name;
        $userData['mail'] = $mail;
        $userData['created'] = dformat();

        // set default group if no groups specified
        if (!is_array($grps)) $grps = array($conf['defaultgroup']);
        $userData['grps'] = implode(',', $grps);

        return $this->helper->appendUser($userData);
    }

    public function modifyUser($user, $changes)
    {
        return $this->helper->update($user, $changes);
    }

    public function deleteUsers($users)
    {
        return $this->helper->delete($users);
    }

    /**
     * Info for all users
     *
     * @param $start
     * @param $limit
     * @param $filter
     * @return array
     * @throws \dokuwiki\Exception\FatalException
     */
    public function retrieveUsers($start = 0, $limit = 0, $filter = array())
    {
        // TODO apply limits
        return $this->helper->getUsers();
    }
}
