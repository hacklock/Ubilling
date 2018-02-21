<?php

class UbillingLDAPManager {

    /**
     * Contains available LDAP users as id=>userdata
     *
     * @var string
     */
    protected $allUsers = array();

    /**
     * Contains all of available user groups as id=>name
     *
     * @var array
     */
    protected $allGroups = array();

    /**
     * System message helper object placeholder
     *
     * @var object
     */
    protected $messages = '';

    const URL_ME = '?module=ldapmgr';

    public function __construct() {
        $this->initMessages();
        $this->loadUsers();
        $this->loadGroups();
    }

    /**
     * Inits system message helper as local instance
     * 
     * @return void
     */
    protected function initMessages() {
        $this->messages = new UbillingMessageHelper();
    }

    /**
     * Loads existing users from database into protected property for further usage
     * 
     * @return void 
     */
    protected function loadUsers() {
        $query = "SELECT * from `ldap_users`";
        $all = simple_queryall($query);
        if (!empty($all)) {
            foreach ($all as $io => $each) {
                $this->allUsers[$each['id']] = $each;
            }
        }
    }

    /**
     * Sets available groups options
     * 
     * @return
     */
    protected function loadGroups() {
        $query = "SELECT * from `ldap_groups`";
        $all = simple_queryall($query);
        if (!empty($all)) {
            foreach ($all as $io => $each) {
                $this->allGroups[$each['id']] = $each['name'];
            }
        }
    }

    /**
     * Create new group in database
     * 
     * @param string $name
     * 
     * @return void
     */
    public function createGroup($name) {
        $nameF = mysql_real_escape_string($name);
        $query = "INSERT INTO `ldap_groups` (`id`,`name`) VALUES ";
        $query.="(NULL,'" . $nameF . "');";
        nr_query($query);
        $newId = simple_get_lastid('ldap_groups');
        log_register('LDAPMGR GROUP CREATE `' . $name . '` [' . $newId . ']');
    }

    /**
     * Deletes existing group from database
     * 
     * @param int $groupId
     * 
     * @return void/string on error
     */
    public function deleteGroup($groupId) {
        $result = '';
        $groupId = vf($groupId, 3);
        if (isset($this->allGroups[$groupId])) {
            $query = "DELETE FROM `ldap_groups` WHERE `id`='" . $groupId . "';";
            nr_query($query);
            log_register('LDAPMGR GROUP DELETE  [' . $groupId . ']');
        } else {
            $result.=__('Something went wrong') . ': EX_GROUPID_NOT_EXISTS';
        }
        return ($result);
    }

    /**
     * Renders group creation interface, Fuck yeah!
     * 
     * @return string
     */
    public function renderGroupCreateFrom() {
        $result = '';
        $inputs = wf_TextInput('newldapgroupname', __('Name'), '', false, 20);
        $inputs.= wf_Submit(__('Create'));
        $result.=wf_Form(self::URL_ME . '&groups=true', 'POST', $inputs, 'glamour');
        return ($result);
    }

    /**
     * Renders existing groups list with some controls
     * 
     * @return string
     */
    public function renderGroupsList() {
        $result = '';
        if (!empty($this->allGroups)) {
            $cells = wf_TableCell(__('ID'));
            $cells.= wf_TableCell(__('Name'));
            $cells.= wf_TableCell(__('Actions'));
            $rows = wf_TableRow($cells, 'row1');
            foreach ($this->allGroups as $io => $each) {
                $cells = wf_TableCell($io);
                $cells.= wf_TableCell($each);
                $actLinks = wf_JSAlert(self::URL_ME . '&groups=true&deletegroupid=' . $io, web_delete_icon(), $this->messages->getDeleteAlert());
                $cells.= wf_TableCell($actLinks);
                $rows.= wf_TableRow($cells, 'row5');
            }
            $result.=wf_TableBody($rows, '100%', 0, 'sortable');
        } else {
            $result.=$this->messages->getStyledMessage(__('Nothing to show'), 'info');
        }
        return ($result);
    }

    /**
     * Creates new user in database
     * 
     * @param string $login
     * @param string $password
     * @param array $groups
     * 
     * @return void
     */
    public function createUser($login, $password, $groups) {
        $loginF = mysql_real_escape_string($login);
        $passwordF = mysql_real_escape_string($password);
        $groupsList = json_encode($groups);
        $query = "INSERT INTO `ldap_users` (`id`,`login`,`password`,`groups`,`changed`) VALUES ";
        $query.="(NULL,'" . $loginF . "','" . $passwordF . "','" . $groupsList . "','1');";
        nr_query($query);
        $newId = simple_get_lastid('ldap_users');
        log_register('LDAPMGR USER CREATE `' . $login . '` [' . $newId . ']');
    }

    /**
     * Sets user as already processed
     * 
     * @param int $userId
     * 
     * @return void
     */
    public function setProcessed($userId) {
        $userId = vf($userId, 3);
        if (isset($this->allUsers[$userId])) {
            simple_update_field('ldap_users', 'changed', 0, "WHERE `id`='" . $userId . "'");
        }
    }

    /**
     * Deletes some existing user from database
     * 
     * @param int $userId
     * 
     * @return void/string on error
     */
    public function deleteUser($userId) {
        $result = '';
        $userId = vf($userId, 3);
        if (isset($this->allUsers[$userId])) {
            $userData = $this->allUsers[$userId];
            $query = "DELETE from `ldap_users` WHERE `id`='" . $userId . "';";
            nr_query($query);
            //placing user to remote deletion queue
            $keyName = 'LDAPDELETEQ_' . zb_rand_string(8);
            zb_StorageSet($keyName, $userData['login']);
        } else {
            $result = __('Something went wrong') . ': EX_USERID_NOT_EXISTS';
        }
        return ($result);
    }

    /**
     * Renders user creation form
     * 
     * @return string
     */
    protected function renderUserCreateForm() {
        $result = '';
        $groupsInputs = '';
        if (!empty($this->allGroups)) {
            foreach ($this->allGroups as $io => $each) {
                $groupsInputs.=wf_CheckInput('ldapusergroup_' . $io, $each, true, false);
            }

            $inputs = wf_TextInput('newldapuserlogin', __('Login'), '', true, 20);
            $inputs.= wf_TextInput('newldapuserpassword', __('Password'), '', true, 20);
            $inputs.=$groupsInputs;
            $inputs.=wf_tag('br');
            $inputs.= wf_Submit(__('Create'));
            $result.=wf_Form(self::URL_ME, 'POST', $inputs, 'glamour');
        } else {
            $result.=$this->messages->getStyledMessage(__('Oh no') . ': ' . __('No existing groups available'), 'warning');
        }
        return ($result);
    }

    /**
     * Catches and preprocess user groups
     * 
     * @return array
     */
    public function catchNewUserGroups() {
        $result = array();
        if (!empty($_POST)) {
            foreach ($_POST as $io => $each) {
                if (ispos($io, 'ldapusergroup')) {
                    $groupId = vf($io, 3);
                    $result[$groupId] = $this->allGroups[$groupId];
                }
            }
        }
        return ($result);
    }

    /**
     * Renders JSON data for users that needs replication to local LDAP database
     * 
     * @return void
     */
    public function getChangedUsers() {
        $tmpArr = array();
        if (!empty($this->allUsers)) {
            foreach ($this->allUsers as $io => $each) {
                if ($each['changed']) {
                    $tmpArr[] = $each;
                    $this->setProcessed($each['id']);
                }
            }
        }
        $result = json_encode($tmpArr);
        die($result);
    }

    /**
     * Renders JSON data for users that requires remote deletion
     * 
     * @return void
     */
    public function getDeletedUsers() {
        $tmpArr = array();
        $queueKeys = zb_StorageFindKeys('LDAPDELETEQ_');
        if (!empty($queueKeys)) {
            foreach ($queueKeys as $io => $each) {
                if (isset($each['key'])) {
                    $userLogin = zb_StorageGet($each['key']);
                    $tmpArr[] = $userLogin;
                    zb_StorageDelete($each['key']);
                }
            }
        }
        $result = json_encode($tmpArr);
        die($result);
    }

    /**
     * Renders main control panel
     * 
     * @return string
     */
    public function panel() {
        $result = '';
        if (!wf_CheckGet(array('groups'))) {
            $result.=wf_modalAuto(wf_img('skins/add_icon.png') . ' ' . __('Users registration'), __('Users registration'), $this->renderUserCreateForm(), 'ubButton') . ' ';
            $result.= wf_Link(self::URL_ME . '&groups=true', web_icon_extended() . ' ' . __('Groups'), false, 'ubButton');
        } else {
            $result.=wf_BackLink(self::URL_ME) . ' ';
            $result.=wf_modalAuto(wf_img('skins/add_icon.png') . ' ' . __('Create'), __('Create'), $this->renderGroupCreateFrom(), 'ubButton');
        }
        return ($result);
    }

    /**
     * Unpacks and 
     * 
     * @param string $groupsData
     * 
     * @return string
     */
    protected function previewGroups($groupsData) {
        $result = '';
        if (!empty($groupsData)) {
            $groupsData = json_decode($groupsData);
            if (!empty($groupsData)) {
                foreach ($groupsData as $groupId => $groupName) {
                    $result.=$groupName . ' ';
                }
            }
        }
        return ($result);
    }

    /**
     * Renders existing users list and some controls
     * 
     * @return string
     */
    public function renderUserList() {
        $result = '';
        if (!empty($this->allUsers)) {
            $cells = wf_TableCell(__('ID'));
            $cells.= wf_TableCell(__('Login'));
            $cells.= wf_TableCell(__('Password'));
            $cells.= wf_TableCell(__('Groups'));
            $cells.= wf_TableCell(__('Changed'));
            $cells.= wf_TableCell(__('Actions'));
            $rows = wf_TableRow($cells, 'row1');
            foreach ($this->allUsers as $io => $each) {
                $cells = wf_TableCell($each['id']);
                $cells.= wf_TableCell($each['login']);
                $cells.= wf_TableCell($each['password']);
                $cells.= wf_TableCell($this->previewGroups($each['groups']));
                $cells.= wf_TableCell(web_bool_led($each['changed']));
                $actLinks = wf_JSAlert(self::URL_ME . '&deleteuserid=' . $each['id'], web_delete_icon(), $this->messages->getDeleteAlert());
                $cells.= wf_TableCell($actLinks);

                $rows.= wf_TableRow($cells, 'row5');
            }
            $result.=wf_TableBody($rows, '100%', 0, 'sortable');
        } else {
            $result.=$this->messages->getStyledMessage(__('Nothing to show'), 'info');
        }
        return ($result);
    }

}

?>