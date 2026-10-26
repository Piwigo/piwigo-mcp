<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

// check_and_save_user_infos() reports errors with the WS_ERR_* constants defined here
include_once(PHPWG_ROOT_PATH . 'include/ws_core.inc.php');

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

class PiwigoUserCapabilities
{
  /**
   * List the users one page at a time, or find one user
   *
   * user_id, username and email match exactly, to get one user. search matches a part
   * of a username, an email or a group name. All the filters given apply together.
   *
   * Info return: paging (page, per_page, count, total_count) and users (id, username, email, status, level, group ids, registration_date, last_visit)
   *
   * @param int|null $page page to return, the first one is 0 (the default)
   * @param int|null $per_page number of users per page, 50 by default
   * @param string|null $search part of a username, an email or a group name
   * @param string|null $status only the users having this status
   * @param string|null $order_by sort the users by this field, id by default. status sorts webmaster, admin, normal, generic, guest
   * @param string|null $order_direction asc (the default) or desc
   * @param int|null $user_id only the user with this id
   * @param string|null $username only the user with exactly this username, case sensitive
   * @param string|null $email only the user with exactly this email, case ignored
   * @param int|null $group_id only the members of this group
   * @param int|null $min_level only the users with this privacy level or higher, one of the levels available (0, 1, 2, 4, 8)
   * @param int|null $max_level only the users with this privacy level or lower, one of the levels available (0, 1, 2, 4, 8)
   * @param string|null $min_register only the users registered on or after this date: YYYY, YYYY-mm or YYYY-mm-dd
   * @param string|null $max_register only the users registered on or before this date: YYYY, YYYY-mm or YYYY-mm-dd
   */
  public function get_all_users(
    ?int $page = null,
    #[Schema(minimum: 1, maximum: 200)]
    ?int $per_page = null,
    ?string $search = null,
    #[Schema(enum: array('guest', 'generic', 'normal', 'admin', 'webmaster'))]
    ?string $status = null,
    #[Schema(enum: array('id', 'username', 'email', 'level'))]
    ?string $order_by = null,
    #[Schema(enum: array('asc', 'desc'))]
    ?string $order_direction = null,
    ?int $user_id = null,
    ?string $username = null,
    ?string $email = null,
    ?int $group_id = null,
    ?int $min_level = null,
    ?int $max_level = null,
    ?string $min_register = null,
    ?string $max_register = null
  ) {
    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.users.php');

    // the first page is 0, 50 users by default, 200 at most
    $page = max(0, $page ?? 0);
    $per_page = max(1, min($per_page ?? 50, 200));

    $user_ids = null === $user_id ? array() : array($user_id);

    foreach (array('username' => $username, 'email' => $email) as $field => $value) {
      if (null === $value or '' == trim($value)) {
        continue;
      }

      $found_id = 'username' == $field ? get_userid(trim($value)) : get_userid_by_email(trim($value));

      // no such user, or not the same user as the other exact filters: nothing matches
      if (false === $found_id or (count($user_ids) > 0 and !in_array($found_id, $user_ids))) {
        return array(
          'paging' => array('page' => $page, 'per_page' => $per_page, 'count' => 0, 'total_count' => 0),
          'users' => array(),
        );
      }

      $user_ids = array(intval($found_id));
    }

    //API method call - get the user list
    $result = PiwigoMCPWebService::check(\ws_users_getList(PiwigoMCPWebService::escape(array(
      'page' => $page,
      'per_page' => $per_page,
      'order' => ($order_by ?? 'id') . ' ' . ($order_direction ?? 'asc') . ('id' == ($order_by ?? 'id') ? '' : ', id'),
      'display' => 'basics,registration_date,last_visit,total_count', ##temporary since there are lots of more options in the ws_func
      'filter' => $search,
      'status' => null === $status ? array() : array($status),
      'user_id' => $user_ids,
      'group_id' => null === $group_id ? array() : array($group_id),
      // max_level is read by the function but not declared on pwg.users.getList
      'min_level' => $min_level,
      'max_level' => $max_level,
      // the function checks the date format
      'min_register' => $min_register,
      'max_register' => $max_register,
    )), $service));

    // the same total is already in paging, ws_users_getList() keeps this copy for old clients
    unset($result['total_count']);

    return $result;
  }

  /**
   * Register a new user, with a random password nobody sees
   *
   * Only creates the account, as pwg.users.add does: the user gets the Piwigo default.
   *
   * Info return: user (id, username, email, status, level, group ids) and password_link (whether the mail was sent, to whom, how long the link stays valid)
   *
   * @param string $username login of the new user
   * @param string|null $email email address, needed to send the password link
   * @param bool|null $send_password_link true to mail the user a link to set their password
   */
  public function register_user(string $username, ?string $email = null, ?bool $send_password_link = null)
  {
    $username = trim($username);
    $email = null === $email ? '' : trim($email);

    if ('' == $username) {
      throw new ToolCallException('Provide a username.');
    }

    if (false !== get_userid($username)) {
      throw new ToolCallException('A user with username "' . $username . '" already exists.');
    }

    // refused before anything is created, the link can only go by mail
    if ($send_password_link and '' == $email) {
      throw new ToolCallException('Provide an email to send the password link to.');
    }

    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.users.php');

    // API method call - register a new user with auto-generated password
    $service = PiwigoMCPWebService::service('pwg.users.add');
    $created_user = PiwigoMCPWebService::check(\ws_users_add(PiwigoMCPWebService::escape(array(
      'username' => $username,
      'password' => null,
      'password_confirm' => null,
      'email' => '' == $email ? null : $email,
      'auto_password' => true,
      'pwg_token' => get_pwg_token(),
    )), $service));

    $registered = array('user' => $created_user['users'][0]);

    /**
     *
     * A code block might be dedicated in case we need to handle the password generation link sent via email
     *
     **/

    return $registered;
  }

  /**
   * Edit a user.
   *
   * @param string $username username of the user to edit
   * @param string|null $new_username new login
   * @param string|null $email new email address
   * @param string|null $status new status, only a webmaster can grant "admin" or "webmaster"
   * @param int|null $level privacy level, one of the levels available (0, 1, 2, 4, 8)
   * @param string|null $language language code, such as "en_UK"
   * @param string|null $theme name of an installed theme
   * @param int[]|null $group_id groups of the user, replaces the current ones, use [-1] to remove them all
   */
  public function edit_user(
    string $username,
    ?string $new_username = null,
    ?string $email = null,
    #[Schema(enum: array('guest', 'generic', 'normal', 'admin', 'webmaster'))]
    ?string $status = null,
    ?int $level = null,
    ?string $language = null,
    ?string $theme = null,
    ?array $group_id = null
  ) {
    global $conf, $user;

    $username = trim($username);
    $user_id = get_userid($username);

    if (false === $user_id) {
      throw new ToolCallException('No user found with username "' . $username . '".');
    }

    $target = $this->fetch_user_by_id($user_id, 'username "' . $username . '"');

    $this->assert_can_manage($user_id, $target['status']);

    if (null !== $status) {
      $this->assert_can_grant_status($status);

      // core silently discards status updates for these ids, refuse loudly instead
      $status_protected = array(
        $user['id'],
        $conf['guest_id'],
        $conf['webmaster_id'],
      );

      if (in_array($user_id, $status_protected)) {
        throw new ToolCallException('The status of user "' . $username . '" cannot be changed.');
      }
    }

    $fields = array(
      'username' => $new_username,
      'email' => $email,
      'status' => $status,
      'level' => $level,
      'language' => $language,
      'theme' => $theme,
      'group_id' => empty($group_id) ? null : array_map('intval', $group_id),
    );

    $params = array();

    foreach ($fields as $field => $value) {
      if (null !== $value) {
        $params[$field] = $value;
      }
    }

    if (0 == count($params)) {
      throw new ToolCallException('Nothing to update, provide at least one field to edit.');
    }

    $params['user_id'] = array($user_id);
    $params['pwg_token'] = get_pwg_token();

    $service = PiwigoMCPWebService::service('pwg.users.getList');
    $result = PiwigoMCPWebService::check(\ws_users_setInfo(PiwigoMCPWebService::escape($params), $service));

    return $result;
  }

  /**
   * Delete a user
   *
   * Info return: deleted and the username deleted
   *
   * @param string|null $username username to delete, case sensitive
   * @param int|null $user_id id of the user to delete, must match username when both are given
   */
  public function delete_user(?string $username = null, ?int $user_id = null)
  {
    global $conf, $user;

    include_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');

    $username = null === $username ? '' : trim($username);

    if (null === $user_id and '' == $username) {
      throw new ToolCallException('Provide a username or a user id.');
    }

    if (null !== $user_id) {
      $found_username = get_username($user_id);

      if (false === $found_username) {
        throw new ToolCallException('No user found with id ' . $user_id . '.');
      }

      if ('' != $username and $found_username != $username) {
        throw new ToolCallException('The user with id ' . $user_id . ' is "' . $found_username . '", not "' . $username . '".');
      }

      $username = $found_username;
    } else {
      $user_id = get_userid($username);

      if (false === $user_id) {
        throw new ToolCallException('No user found with username "' . $username . '".');
      }
    }

    $protected_users = array(
      $user['id'],
      $conf['guest_id'],
      $conf['default_user_id'],
      $conf['webmaster_id'],
    );

    if (in_array($user_id, $protected_users)) {
      throw new ToolCallException('User "' . $username . '" is protected and cannot be deleted.');
    }

    $target = $this->fetch_user_by_id($user_id, 'username "' . $username . '"');

    $this->assert_can_manage($user_id, $target['status']);

    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.users.php');

    $service = null;
    PiwigoMCPWebService::check(\ws_users_delete(PiwigoMCPWebService::escape(array(
      'user_id' => array(intval($user_id)),
      'pwg_token' => get_pwg_token(),
    )), $service));

    if (false !== get_username($user_id)) {
      throw new ToolCallException('User "' . $username . '" was not deleted.');
    }

    return array(
      'deleted' => true,
      'username' => $username,
    );
  }


  /********************************************************
   *
   *Helper funcion
   * 
   ********************************************************/
  /**
   * Throw unless the caller can manage this user.
   *
   * A caller only reaches users ranked strictly below them, plus their own account.
   *
   * @param int $target_id id of the user being managed
   * @param string $target_status status of the user being managed
   */
  private function assert_can_manage($target_id, $target_status)
  {
    global $user;

    // editing your own account is always allowed
    if ($target_id == $user['id']) {
      return;
    }

    if (get_access_type_status($target_status) >= get_access_type_status($user['status'])) {
      throw new ToolCallException(
        'You cannot manage a user with an equal or higher status than yours ('
          . $target_status . ' vs ' . $user['status'] . ').'
      );
    }
  }

  /**
   * Throw unless the caller can assign this status.
   *
   * @param string $status status to grant
   */
  private function assert_can_grant_status($status)
  {
    global $user;

    if (get_access_type_status($status) > get_access_type_status($user['status'])) {
      throw new ToolCallException('You cannot grant a status higher than yours (' . $user['status'] . ').');
    }
  }

  /**
   * Fetch one user through pwg.users.getList.
   *
   * @param int $user_id id of the user
   * @param string $identifier how the user was named, for the error message
   * @return array id, username, email, status, level, group ids, registration_date, last_visit
   */
  private function fetch_user_by_id(int $user_id, string $identifier)
  {
    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.users.php');

    // ws_users_getList() reads order, display, per_page and page without checking them
    $service = null;
    $result = PiwigoMCPWebService::check(\ws_users_getList(PiwigoMCPWebService::escape(array(
      'user_id' => array($user_id),
      'page' => 0,
      'per_page' => 1,
      'order' => 'id',
      'display' => 'basics,registration_date,last_visit',
    )), $service));

    if (0 == count($result['users'])) {
      throw new ToolCallException('No profile data for the user with ' . $identifier . '.');
    }

    return $result['users'][0];
  }
}
