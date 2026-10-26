<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

class PiwigoGroupCapabilities
{
    /**
     * List the groups one page at a time, or find one group
     *
     * group_name and group_id match exactly, to get one group. search matches a part of
     * a group name. All the filters given apply together. The members of a group are
     * listed by get_all_users with its group_id.
     *
     * Info return: paging (page, per_page, count, total_count) and groups (id, name, is_default, lastmodified, number of users)
     *
     * @param string|null $group_name only the group with exactly this name, case ignored
     * @param int|null $group_id only the group with this id
     * @param string|null $search part of the group name
     * @param int|null $page page to return, the first one is 0 (the default)
     * @param int|null $per_page number of groups per page, 50 by default
     * @param string|null $order_by sort the groups by this field, name by default
     * @param string|null $order_direction asc (the default) or desc
     */
    public function get_group(
        ?string $group_name = null,
        ?int $group_id = null,
        ?string $search = null,
        ?int $page = null,
        #[Schema(minimum: 1, maximum: 200)]
        ?int $per_page = null,
        #[Schema(enum: array('id', 'name', 'nb_users', 'is_default'))]
        ?string $order_by = null,
        #[Schema(enum: array('asc', 'desc'))]
        ?string $order_direction = null
    ) {
        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.groups.php');

        // the first page is 0, 50 groups by default, 200 at most
        $page = max(0, $page ?? 0);
        $per_page = max(1, min($per_page ?? 50, 200));
        $search = null === $search ? '' : trim($search);
        $group_name = null === $group_name ? '' : trim($group_name);
        $order_by = $order_by ?? 'name';

        $nothing = array(
            'paging' => array('page' => $page, 'per_page' => $per_page, 'count' => 0, 'total_count' => 0),
            'groups' => array(),
        );

        if ('' != $group_name) {
            $service = null;
            $candidates = PiwigoMCPWebService::check(\ws_groups_getList(PiwigoMCPWebService::escape(array(
                'name' => $group_name,
                'order' => 'id',
                'page' => 0,
                'per_page' => 200,
            )), $service));

            $found_id = null;

            foreach ($candidates['groups'] as $candidate) {
                if (0 == strcasecmp($candidate['name'], $group_name)) {
                    $found_id = intval($candidate['id']);
                }
            }

            // no such group, or not the same group as group_id: nothing matches
            if (null === $found_id or (null !== $group_id and $found_id != $group_id)) {
                return $nothing;
            }

            $group_id = $found_id;
        }

        $service = null;
        $result = PiwigoMCPWebService::check(\ws_groups_getList(PiwigoMCPWebService::escape(array(
            'group_id' => null === $group_id ? null : array($group_id),
            'name' => '' == $search ? null : '%' . $search . '%',
            // goes as is into ORDER BY, id last so groups with the same value keep one order across pages
            'order' => $order_by . ' ' . ($order_direction ?? 'asc') . ('id' == $order_by ? '' : ', id'),
            'page' => $page,
            'per_page' => $per_page,
        )), $service));

        return $result;
    }

    /**
     * Create a group
     *
     * Info return: id, name, is_default, lastmodified, number of users
     *
     * @param string $group_name name of the group, must not be used by another group
     * @param bool|null $is_default true to put every new user in this group
     */
    public function create_group(string $group_name, ?bool $is_default = null)
    {
        $group_name = trim($group_name);

        if ('' == $group_name) {
            throw new ToolCallException('Provide a name for the group to create.');
        }

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.groups.php');

        $service = PiwigoMCPWebService::service('pwg.groups.add');
        $result = PiwigoMCPWebService::check(\ws_groups_add(PiwigoMCPWebService::escape(array(
            'name' => $group_name,
            // read without checking it
            'is_default' => true === $is_default,
        )), $service));

        return array('group' => $result['groups'][0]);
    }

    /**
     * Edit a group
     *
     * Only the given fields are changed, the others are left untouched.
     *
     * Info return: id, name, is_default, lastmodified, number of users
     *
     * @param string|null $group_name name of the group to edit, ignored when group_id is given
     * @param int|null $group_id id of the group
     * @param string|null $new_group_name new name of the group
     * @param bool|null $is_default true to put every new user in this group
     */
    public function edit_group(
        ?string $group_name = null,
        ?int $group_id = null,
        ?string $new_group_name = null,
        ?bool $is_default = null
    ) {
        $group = $this->fetch_group($group_name, $group_id);

        if (null !== $new_group_name and '' == trim($new_group_name)) {
            throw new ToolCallException('A group cannot be renamed to an empty name.');
        }

        if (null === $new_group_name and null === $is_default) {
            throw new ToolCallException('Nothing to update, provide at least one field to edit.');
        }

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.groups.php');

        $service = PiwigoMCPWebService::service('pwg.groups.setInfo');
        $result = PiwigoMCPWebService::check(\ws_groups_setInfo(PiwigoMCPWebService::escape(array(
            'group_id' => intval($group['id']),
            'name' => null === $new_group_name ? null : trim($new_group_name),
            'is_default' => $is_default,
            'pwg_token' => get_pwg_token(),
        )), $service));

        return array('group' => $result['groups'][0]);
    }

    /**
     * Delete a group
     *
     * The users of the group are kept, they only leave the group.
     *
     * Info return: name of the group deleted
     *
     * @param string|null $group_name name of the group, ignored when group_id is given
     * @param int|null $group_id id of the group
     */
    public function delete_group(?string $group_name = null, ?int $group_id = null)
    {
        $group = $this->fetch_group($group_name, $group_id);

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.groups.php');

        $service = null;
        PiwigoMCPWebService::check(\ws_groups_delete(PiwigoMCPWebService::escape(array(
            'group_id' => array(intval($group['id'])),
            'pwg_token' => get_pwg_token(),
        )), $service));

        return array(
            'deleted' => true,
            'group' => $group['name'],
        );
    }

    /**
     * Add users to a group
     *
     * Info return: the group after the change
     *
     * @param string[] $usernames usernames of the users to add
     * @param string|null $group_name name of the group, ignored when group_id is given
     * @param int|null $group_id id of the group
     */
    public function add_users_to_group(array $usernames, ?string $group_name = null, ?int $group_id = null)
    {
        $group = $this->fetch_group($group_name, $group_id);

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.groups.php');

        $service = PiwigoMCPWebService::service('pwg.groups.addUser');
        $result = PiwigoMCPWebService::check(\ws_groups_addUser(PiwigoMCPWebService::escape(array(
            'group_id' => intval($group['id']),
            'user_id' => $this->fetch_user_ids($usernames),
            'pwg_token' => get_pwg_token(),
        )), $service));

        return array('group' => $result['groups'][0]);
    }

    /**
     * Remove users from a group
     *
     * The users are kept, they only leave the group.
     *
     * Info return: the group after the change
     *
     * @param string[] $usernames usernames of the users to remove
     * @param string|null $group_name name of the group, ignored when group_id is given
     * @param int|null $group_id id of the group
     */
    public function remove_users_from_group(array $usernames, ?string $group_name = null, ?int $group_id = null)
    {
        $group = $this->fetch_group($group_name, $group_id);

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.groups.php');

        $service = PiwigoMCPWebService::service('pwg.groups.deleteUser');
        $result = PiwigoMCPWebService::check(\ws_groups_deleteUser(PiwigoMCPWebService::escape(array(
            'group_id' => intval($group['id']),
            'user_id' => $this->fetch_user_ids($usernames),
            'pwg_token' => get_pwg_token(),
        )), $service));

        return array('group' => $result['groups'][0]);
    }

    /**
     * Merge groups into another group
     *
     * The users of the merged groups join the destination group, then the merged groups are deleted.
     *
     * Info return: the destination group after the merge and the groups deleted
     *
     * @param string[] $merge_group_names names of the groups to merge and delete
     * @param string|null $destination_group_name name of the group that receives the users, ignored when destination_group_id is given
     * @param int|null $destination_group_id id of the group that receives the users
     */
    public function merge_groups(
        array $merge_group_names,
        ?string $destination_group_name = null,
        ?int $destination_group_id = null
    ) {
        $destination = $this->fetch_group($destination_group_name, $destination_group_id);

        $merge_ids = array();

        foreach ($merge_group_names as $merge_group_name) {
            $merge_group = $this->fetch_group($merge_group_name, null);

            if ($merge_group['id'] == $destination['id']) {
                throw new ToolCallException('A group cannot be merged into itself ("' . $merge_group['name'] . '").');
            }

            $merge_ids[] = intval($merge_group['id']);
        }

        if (0 == count($merge_ids)) {
            throw new ToolCallException('Provide at least one group to merge.');
        }

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.groups.php');

        $service = PiwigoMCPWebService::service('pwg.groups.merge');
        $result = PiwigoMCPWebService::check(\ws_groups_merge(PiwigoMCPWebService::escape(array(
            'destination_group_id' => intval($destination['id']),
            'merge_group_id' => array_values(array_unique($merge_ids)),
            'pwg_token' => get_pwg_token(),
        )), $service));

        return array(
            'group' => $result['destination_group']['groups'][0],
            'deleted_groups' => array_column($result['deleted_group']['groups'], 'name'),
        );
    }

    /********************************************************
     *
     *Helper funcion
     *
     ********************************************************/

    /**
     * The one group a tool acts on, found through get_group().
     *
     * @param string|null $group_name name of the group, ignored when group_id is given
     * @param int|null $group_id id of the group
     * @return array id, name, is_default, lastmodified, nb_users
     */
    private function fetch_group(?string $group_name, ?int $group_id)
    {
        $group_name = null === $group_name ? '' : trim($group_name);

        if (null === $group_id and '' == $group_name) {
            throw new ToolCallException('Provide a group name or a group id.');
        }

        $groups = $this->get_group(
            group_name: null === $group_id ? $group_name : null,
            group_id: $group_id,
            per_page: 1
        )['groups'];

        if (count($groups) > 0) {
            return $groups[0];
        }

        throw new ToolCallException(
            null !== $group_id
                ? 'No group with the id ' . $group_id . '.'
                : 'No group found with name "' . $group_name . '".'
        );
    }

    /**
     * Turn usernames into user ids, refusing unknown ones.
     *
     * @param string[] $usernames
     * @return int[] ids of the users
     */
    private function fetch_user_ids(array $usernames)
    {
        $user_ids = array();
        $unknown = array();

        foreach ($usernames as $username) {
            $user_id = get_userid(trim($username));

            if (false === $user_id) {
                $unknown[] = $username;
            } else {
                $user_ids[] = intval($user_id);
            }
        }

        if (count($unknown) > 0) {
            throw new ToolCallException('No user found with username: "' . implode('", "', $unknown) . '".');
        }

        if (0 == count($user_ids)) {
            throw new ToolCallException('Provide at least one username.');
        }

        return array_values(array_unique($user_ids));
    }
}
