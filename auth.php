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
            $this->helper->writeStat($user, 'LOGIN', dformat());
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
        return $this->helper->getUserFromSheet($user);
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
        $userData[] = $user;
        $userData[] = auth_cryptPassword($pwd);
        $userData[] = $name;
        $userData[] = $mail;

        // set default group if no groups specified
        if (!is_array($grps)) $grps = array($conf['defaultgroup']);
        $userData[] = implode(',', $grps);

        return $this->helper->appendUser($userData);
    }

    public function modifyUser($user, $changes)
    {
        return parent::modifyUser($user, $changes); // TODO: Change the autogenerated stub
    }

    public function deleteUsers($users)
    {
        return parent::deleteUsers($users); // TODO: Change the autogenerated stub
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
        $users = [];
        $userData = $this->helper->getUsers();

        foreach ($userData as $data) {
            $users[$data['user']] = $data['userinfo'];
        }

        // TODO apply limits
        return $users;
    }
}
