<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

class PiwigoUserCapabilities
{
  /**
   * Get a single user, by username or by email
   *
   * @param string|null $username username of the user, takes precedence over email
   * @param string|null $email email used when username is not given
   */
  public function get_user(?string $username = null, ?string $email = null)
  {
    if (null !== $username and '' != trim($username))
    {
      $username = trim($username);
      $user_id = get_userid($username);
      $identifier = 'username "'.$username.'"';

      if (false === $user_id)
      {
        throw new ToolCallException('No user found with '.$identifier.'.');
      }
    }
    else if (null !== $email and '' != trim($email))
    {
      $email = trim($email);
      $user_id = get_userid_by_email($email);
      $identifier = 'email "'.$email.'"';

      if (false === $user_id)
      {
        throw new ToolCallException('No user found with '.$identifier.'.');
      }
    }
    else
    {
      throw new ToolCallException('Provide either username or email.');
    }

    $user = $this->fetch_user(array($user_id));

    if (0 == count($user)){
      throw new ToolCallException('No profile data for the user with '.$identifier.'');
    }

    return array('user' => array_values($user)[0]);
  }

  /**
   * List all users
   *
   * Info return: username, email, status, level, registration_date, last_visit, groups
   */
  public function get_all_users()
  {
    $users_list = $this->fetch_user();

    return array('users' => array_values($users_list));
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
    )
  {
    $username = trim($username);
    $user_id = get_userid($username);

    if (false === $user_id)
    {
      throw new ToolCallException('No user found with username "'.$username.'".');
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

    foreach ($fields as $field => $value)
    {
      if (null !== $value)
      {
        $params[$field] = $value;
      }
    }

    if (0 == count($params))
    {
      throw new ToolCallException('Nothing to update, provide at least one field to edit.');
    }

    $params['user_id'] = array($user_id);

    //update the member info through Piwigo core function
    $updated = check_and_save_user_infos($params);

    if (isset($updated['error']))
    {
      throw new ToolCallException($updated['error']['message']);
    }

    return $this->get_user(isset($params['username']) ? $params['username'] : $username);
  }

  /**
   * Delete a user.
   *
   * @param string $username username to delete
   */
  public function delete_user(string $username)
  {
    global $conf, $user;

    //use Piwigo core function
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $user_id = get_userid($username);

    if (false === $user_id)
    {
      throw new ToolCallException('No user found with name '.$username.'.');
    }

    //These users cannot be deleted
    $protected_users = array(
      $user['id'],
      $conf['guest_id'],
      $conf['default_user_id'],
      $conf['webmaster_id'],
      );

    if ('admin' == $user['status'])
    {
      $query = '
SELECT
    user_id
  FROM '.USER_INFOS_TABLE.'
  WHERE status IN (\'webmaster\', \'admin\')
;';
      $protected_users = array_merge($protected_users, query2array($query, null, 'user_id'));
    }

    if (in_array($user_id, $protected_users))
    {
      throw new ToolCallException('User ('.$username.') is protected and can not be deleted.');
    }

    // global Piwigo delete user function
    \delete_user($user_id);

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
   * Fetch users with their groups, keyed by user id.
   *
   * @param int[]|null $user_ids restrict to these ids, null for all users
   * @param int|null $limit maximum number of rows to return
   */
  private function fetch_user(?array $user_ids = null, ?int $limit = null)
  {
    global $conf;

    load_language('admin.lang');

    // an explicit but empty id list matches nothing, no need to hit the database
    if (null !== $user_ids and 0 == count($user_ids))
    {
      return array();
    }

    $query = '
      SELECT
          u.'.$conf['user_fields']['id'].' AS id,
          u.'.$conf['user_fields']['username'].' AS username,
          u.'.$conf['user_fields']['email'].' AS email,
          ui.status,
          ui.level,
          ui.registration_date,
          ui.last_visit
      FROM '.USERS_TABLE.' AS u
      INNER JOIN '.USER_INFOS_TABLE.' AS ui ON u.'.$conf['user_fields']['id'].' = ui.user_id'
      .(null !== $user_ids ? '
      WHERE u.'.$conf['user_fields']['id'].' IN ('.implode(',', array_map('intval', $user_ids)).')' : '')
      .(null !== $limit ? '
      LIMIT '.intval($limit) : '').'
      ;';

    $users_list = array();

    foreach (query2array($query) as $user_row)
    {
      $user_id = intval($user_row['id']);
      unset($user_row['id']);

      //privacy level
      $level = intval($user_row['level']);
      unset($user_row['level']);
      $user_row['level_label'] = l10n(sprintf('Level %d', $level));

      //init of user's group attribute
      $user_row['groups'] = array();

      $users_list[$user_id] = $user_row;
    }

    // Get the group user are in
    if (count($users_list) > 0)
    {
      $group_query = '
  SELECT
    ug.user_id,
    g.id,
    g.name
  FROM '.USER_GROUP_TABLE.' AS ug
    INNER JOIN '.GROUPS_TABLE.' AS g
      ON g.id = ug.group_id
  WHERE ug.user_id IN ('.implode(',', array_keys($users_list)).')
  ORDER BY g.name;';

      foreach (query2array($group_query) as $group)
      {
        $users_list[ intval($group['user_id']) ]['groups'][] = array(
          'id' => intval($group['id']),
          'name' => $group['name'],
          );
      }
    }

    return $users_list;
    }

}