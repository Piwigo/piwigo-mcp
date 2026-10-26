<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

class PiwigoAlbumCapabilities
{
  /**
   * Get the albums matching the name, through get_all_albums()
   *
   * Info return: albums (id, name, full path, comment, status, visible, parent id, number of photos, number of sub-albums)
   *
   * @param string $album_name name of the album, several albums can share the same name
   */
  public function get_album(string $album_name)
  {
    $album_name = trim($album_name);

    if ('' == $album_name) {
      throw new ToolCallException('Provide an album name.');
    }

    // every album with this exact name, whatever its level
    $albums = $this->get_all_albums(per_page: 200, album_name: $album_name)['albums'];

    if (0 == count($albums)) {
      throw new ToolCallException('No album found with name "' . $album_name . '".');
    }

    return array('albums' => $albums);
  }

  /**
   * List some albums or find all albums
   *
   * Without parent nor search: the top level albums. With a parent: its sub-albums.
   * With search: the albums whose name contains the text, at any level (100 at most).
   * album_name and album_id match exactly, at any level: several albums can share a name.
   * All the filters given apply together.
   *
   * Info return: paging (page, per_page, count, total_count) and albums (id, name, full path, comment, status, visible, parent id, number of photos, number of sub-albums)
   *
   * @param string|null $parent_name name of the album to list the sub-albums of
   * @param int|null $parent_id id of the album to list the sub-albums of, needed when several albums share the same name
   * @param string|null $search part of the album name, looks at every level
   * @param bool|null $recursive true to list every level below the parent instead of one
   * @param int|null $page page to return, the first one is 0 (the default)
   * @param int|null $per_page number of albums per page, 50 by default
   * @param string|null $album_name only the albums with exactly this name, case ignored, at any level
   * @param int|null $album_id only the album with this id
   */
  public function get_all_albums(
    ?string $parent_name = null,
    ?int $parent_id = null,
    ?string $search = null,
    ?bool $recursive = null,
    ?int $page = null,
    #[Schema(minimum: 1, maximum: 200)]
    ?int $per_page = null,
    ?string $album_name = null,
    ?int $album_id = null
  ) {
    if (null !== $parent_id or (null !== $parent_name and '' != trim($parent_name))) {
      $parent_id = $this->resolve_single_album($parent_name, $parent_id);
    }

    $search = null === $search ? '' : trim($search);
    $album_name = null === $album_name ? '' : trim($album_name);

    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.categories.php');

    $service = null;
    $limit_reached = null;


    if (null !== $album_id or '' != $album_name) {
      // if have $album_name, handle the condition `LIKE` in the core SQL to find the album with exact name only
      if ('' != $album_name) {
        $result = PiwigoMCPWebService::check(\ws_categories_getAdminList(PiwigoMCPWebService::escape(array(
          'cat_id' => 0,
          'recursive' => true,
          'search' => $album_name,
        )), $service));

        if ($result['limit_reached']) {
          global $conf;

          throw new ToolCallException(
            'More than ' . $conf['linked_album_search_limit'] . ' albums contain "' . $album_name
              . '", the exact name cannot be checked. Use album_id (find it with parent_id or a longer search).'
          );
        }
      } else {
        $result = PiwigoMCPWebService::check(\ws_categories_getAdminList(PiwigoMCPWebService::escape(array(
          'cat_id' => $album_id,
          'recursive' => false,
          'search' => null,
        )), $service));
      }

      $found = array();

      // the filters given apply together
      foreach ($result['categories'] as $album) {
        // exact name, without case as MySQL compares album names
        if ('' != $album_name and mb_strtolower($album['name']) !== mb_strtolower($album_name)) {
          continue;
        }

        if (null !== $album_id and $album['id'] != $album_id) {
          continue;
        }

        // below the parent: directly, or at any level with recursive
        if (null !== $parent_id) {
          $uppercats = explode(',', $album['uppercats']);
          $is_below = true === $recursive
            ? in_array($parent_id, array_slice($uppercats, 0, -1))
            : (count($uppercats) > 1 && $uppercats[count($uppercats) - 2] == $parent_id);

          if (!$is_below) {
            continue;
          }
        }

        if ('' != $search and false === mb_stripos($album['name'], $search)) {
          continue;
        }

        $found[] = $album;
      }
    } else {
      $result = PiwigoMCPWebService::check(\ws_categories_getAdminList(PiwigoMCPWebService::escape(array(
        'cat_id' => null === $parent_id ? 0 : $parent_id,
        'recursive' => '' != $search ? true : (true === $recursive),
        'search' => '' == $search ? null : $search,
      )), $service));

      $found = array();

      foreach ($result['categories'] as $album) {
        // the parent comes back along with its sub-albums
        if (null !== $parent_id and $album['id'] == $parent_id) {
          continue;
        }

        $found[] = $album;
      }

      $limit_reached = '' != $search ? $result['limit_reached'] : null;
    }

    // only the fields useful to the model, getAdminList() also returns dir, global_rank, image_order...
    $albums = array();

    foreach ($found as $album) {
      $uppercats = explode(',', $album['uppercats']);

      $albums[] = array(
        'id' => intval($album['id']),
        'name' => $album['name'],
        'fullname' => trim(preg_replace('/\s+/', ' ', $album['fullname'])),
        'comment' => $album['comment'],
        'status' => $album['status'],
        'uppercats' => $album['uppercats'],
        'id_uppercat' => count($uppercats) > 1 ? intval($uppercats[count($uppercats) - 2]) : null,
        'nb_images' => intval($album['nb_images']),
        'nb_categories' => intval($album['nb_categories'] ?? 0),
      );
    }

    $page = max(0, $page ?? 0);
    $per_page = max(1, min($per_page ?? 50, 200));
    $page_albums = array_slice($albums, $page * $per_page, $per_page);

    // visible (locked or not) is the one field no web service method returns: getAdminList() and
    // getList() only use it as a filter.
    if (count($page_albums) > 0) {
      $visible_of = query2array('
SELECT id, visible
  FROM ' . CATEGORIES_TABLE . '
  WHERE id IN (' . implode(',', array_column($page_albums, 'id')) . ')
;', 'id', 'visible');

      foreach ($page_albums as &$page_album) {
        $page_album['visible'] = $visible_of[$page_album['id']] ?? null;
      }
      unset($page_album);
    }

    $output = array(
      'paging' => array(
        'page' => $page,
        'per_page' => $per_page,
        'count' => count($page_albums),
        'total_count' => count($albums),
      ),
      'albums' => $page_albums,
    );

    if (null !== $limit_reached) {
      $output['search_limit_reached'] = $limit_reached;
    }

    return $output;
  }

  /**
   * Create a virtual album
   *
   * Info return: id, name, comment, parent album, status, visible, number of photos
   *
   * @param string $album_name name of the album to create
   * @param string|null $parent_name name of an existing album to create it into, none for a top level album
   * @param int|null $parent_id id of the parent album
   * @param string|null $position placement of the new album inside the the tree (from enum of 'first' or 'last')
   * @param string|null $comment description of the album
   * @param string|null $status "private" restricts the album to the users granted access to it
   * @param bool|null $visible false locks the album, only visible by administrators
   * @param bool|null $commentable allow visitors to comment the photos of this album
   * @param bool|null $inherit copy the permissions of the parent album, private albums only
   */
  public function create_album(
    string $album_name,
    ?string $parent_name = null,
    ?int $parent_id = null,
    #[Schema(enum: array('first', 'last'))]
    ?string $position = null,
    ?string $comment = null,
    #[Schema(enum: array('public', 'private'))]
    ?string $status = null,
    ?bool $visible = null,
    ?bool $commentable = null,
    ?bool $inherit = null
  ) {
    $album_name = trim($album_name);

    if ('' == $album_name) {
      throw new ToolCallException('Provide a name for the album to create.');
    }

    if (null !== $parent_id or (null !== $parent_name and '' != trim($parent_name))) {
      $parent_id = $this->resolve_single_album($parent_name, $parent_id);
    }

    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.categories.php');
    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.permissions.php');

    // API method call - create a new album
    $service = null;
    $created = PiwigoMCPWebService::check(\ws_categories_add(PiwigoMCPWebService::escape(array(
      'name' => $album_name,
      'parent' => $parent_id,
      'comment' => $comment,
      'status' => $status,
      'position' => $position,
      'pwg_token' => get_pwg_token(),
    )), $service));

    $album_id = intval($created['id']);

    //API method call - Change the properties of the album (due to add ws_function not consider the $visible & $commentable values)
    if (null !== $visible or null !== $commentable) {
      PiwigoMCPWebService::check(\ws_categories_setInfo(PiwigoMCPWebService::escape(array(
        'category_id' => $album_id,
        'visible' => $this->to_ws_bool($visible),
        'commentable' => $this->to_ws_bool($commentable),
        'pwg_token' => get_pwg_token(),
      )), $service));
    }

    // API method chain call - Update the permission of the album base on the parent album
    if (true === $inherit and 'private' == $status and null !== $parent_id) {
      $parent_access = PiwigoMCPWebService::check(\ws_permissions_getList(PiwigoMCPWebService::escape(array(
        'cat_id' => array($parent_id),
      )), $service));

      foreach ($parent_access['categories'] as $access) {
        if ($access['id'] == $parent_id and (!empty($access['users']) or !empty($access['groups']))) {
          $permissions_service = PiwigoMCPWebService::service('pwg.permissions.add');

          PiwigoMCPWebService::check(\ws_permissions_add(PiwigoMCPWebService::escape(array(
            'cat_id' => array($album_id),
            'user_id' => $access['users'],
            'group_id' => $access['groups'],
            'recursive' => false,
            'pwg_token' => get_pwg_token(),
          )), $permissions_service));
        }
      }
    }

    include_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');
    \invalidate_user_cache();

    global $cache;
    unset($cache['cat_names']);

    return array('albums' => $this->get_all_albums(album_id: $album_id)['albums']);
  }

  /**
   * Edit the information of an album
   *
   * Only the given fields are changed, the others are left untouched.
   *
   * Info return: id, name, comment, parent album, status, visible, number of photos
   *
   * @param string|null $album_name name of the album to edit, ignored when album_id is given
   * @param int|null $album_id id of the album, needed when several albums share the same name
   * @param string|null $new_album_name new name of the album
   * @param string|null $comment new description of the album, empty to clear it
   * @param bool|null $visible false locks the album, only visible by administrators
   * @param bool|null $commentable allow visitors to comment the photos of this album
   * @param string|null $status "private" restricts the album to the users granted access to it
   * @param bool|null $commentable_subalbums true to apply the commentable value to every sub-album as well
   */
  public function edit_album(
    ?string $album_name = null,
    ?int $album_id = null,
    ?string $new_album_name = null,
    ?string $comment = null,
    ?bool $commentable = null,
    ?bool $visible = null,
    #[Schema(enum: array('public', 'private'))]
    ?string $status = null,
    ?bool $commentable_subalbums = null
    // ?string $image_order = null
  ) {

    $album_id = $this->resolve_single_album($album_name, $album_id);

    if (null !== $new_album_name and '' == trim($new_album_name)) {
      throw new ToolCallException('An album cannot be renamed to an empty name.');
    }

    if (null === $new_album_name and null === $comment and null === $commentable and null === $visible and null === $status) {
      throw new ToolCallException('Nothing to update, provide at least one field to edit.');
    }

    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.categories.php');

    // API method call - Update the properties of the album
    $service = null;
    PiwigoMCPWebService::check(\ws_categories_setInfo(PiwigoMCPWebService::escape(array(
      'category_id' => intval($album_id),
      'name' => null === $new_album_name ? null : trim($new_album_name),
      'comment' => $comment,
      'status' => $status,
      'visible' => $this->to_ws_bool($visible),
      'commentable' => $this->to_ws_bool($commentable),
      // only checked to be set and truthy, so sent only when true
      'apply_commentable_to_subalbums' => true === $commentable_subalbums ? 'true' : null,
      'pwg_token' => get_pwg_token(),
    )), $service));

    include_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');
    \invalidate_user_cache();

    global $cache;
    unset($cache['cat_names']);

    return array('albums' => $this->get_all_albums(album_id: $album_id)['albums']);
  }

  /**
   * Delete an album
   *
   * The sub-albums are deleted as well. Photos are kept unless another mode is given.
   *
   * Info return: id and path of every album deleted
   *
   * @param string|null $album_name name of the album, ignored when album_id is given
   * @param int|null $album_id id of the album, needed when several albums share the same name
   * @param string|null $mode what happens to the photos of the album, they are kept by default
   */
  public function delete_album(
    ?string $album_name = null,
    ?int $album_id = null,
    #[Schema(enum: array('no_delete', 'delete_orphans', 'force_delete'))]
    ?string $mode = 'no_delete'
  ) {
    $album_id = $this->resolve_single_album($album_name, $album_id);

    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.categories.php');

    // the album and every sub-album it takes along, with their paths, read before they are gone:
    // cat_id with recursive gives the whole sub-tree, the album included
    $service = null;
    $subtree = PiwigoMCPWebService::check(\ws_categories_getAdminList(PiwigoMCPWebService::escape(array(
      'cat_id' => $album_id,
      'recursive' => true,
      'search' => null,
    )), $service));

    $deleted_paths = array();

    foreach ($subtree['categories'] as $album) {
      $deleted_paths[intval($album['id'])] = trim(preg_replace('/\s+/', ' ', $album['fullname']));
    }

    // API call - delete an album
    PiwigoMCPWebService::check(\ws_categories_delete(PiwigoMCPWebService::escape(array(
      'category_id' => array($album_id),
      'photo_deletion_mode' => null === $mode ? 'no_delete' : $mode,
      'pwg_token' => get_pwg_token(),
    )), $service));

    $deleted_albums = array();

    foreach ($deleted_paths as $deleted_id => $path) {
      $deleted_albums[] = array(
        'id' => $deleted_id,
        'path' => $path,
      );
    }

    return array(
      'deleted' => true,
      'albums' => $deleted_albums,
    );
  }


  /**
   * Move a virtual album, with its sub-albums, under another album or to the top level
   *
   * Info return: the album after the move (id, name, full path, parent id, ...)
   *
   * @param string|null $album_name name of the album to move, ignored when album_id is given
   * @param int|null $album_id id of the album, needed when several albums share the same name
   * @param string|null $parent_name name of the album to move it into, ignored when parent_id is given
   * @param int|null $parent_id id of the album to move it into, 0 for the top level
   */
  public function move_album(?string $album_name = null, ?int $album_id = null, ?string $parent_name = null, ?int $parent_id = null)
  {
    $album_id = $this->resolve_single_album($album_name, $album_id);

    if (0 !== $parent_id) {
      if (null === $parent_id and (null === $parent_name or '' == trim($parent_name))) {
        throw new ToolCallException('Provide the parent_name or parent_id to move the album into, parent_id 0 for the top level.');
      }

      $parent_id = $this->resolve_single_album($parent_name, $parent_id);
    }

    include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.categories.php');

    $service = null;
    PiwigoMCPWebService::check(\ws_categories_move(PiwigoMCPWebService::escape(array(
      'category_id' => array($album_id),
      'parent' => intval($parent_id),
      'pwg_token' => get_pwg_token(),
    )), $service));

    global $cache;
    unset($cache['cat_names']);

    return array('albums' => $this->get_all_albums(album_id: $album_id)['albums']);
  }


  /********************************************************
   *
   *Helper funcion
   *
   ********************************************************/

  /**
   * The one album an action applies to, found through get_all_albums().
   *
   * Only adds what a list cannot do: refuse when nothing is given (get_all_albums() would then
   * list the top level albums), throw when nothing matches, and refuse a name shared by several
   * albums. The id is prioritized when both are given, as the tools document.
   *
   * @param string|null $album_name name to look for, ignored when album_id is given
   * @param int|null $album_id id of the album
   * @return int id of the album
   */
  private function resolve_single_album(?string $album_name, ?int $album_id)
  {
    $album_name = null === $album_name ? '' : trim($album_name);

    if (null === $album_id and '' == $album_name) {
      throw new ToolCallException('Provide an album name or an album id.');
    }

    $albums = $this->get_all_albums(
      per_page: 200,
      album_name: null === $album_id ? $album_name : null,
      album_id: $album_id
    )['albums'];

    if (0 == count($albums)) {
      throw new ToolCallException(
        null !== $album_id
          ? 'No album with the id ' . $album_id . '.'
          : 'No album found with name "' . $album_name . '".'
      );
    }

    // several albums can share the same name, the caller has to tell which one
    if (count($albums) > 1) {
      $candidates = array();

      foreach ($albums as $candidate) {
        $candidates[] = 'album_id ' . $candidate['id'] . ' (' . $candidate['fullname'] . ')';
      }

      throw new ToolCallException(
        count($albums) . ' albums are named "' . $album_name . '": ' . implode(', ', $candidates)
          . '. Call again with the album_id of the one you want.'
      );
    }

    return $albums[0]['id'];
  }

  /**
   * Turn a tool boolean into the value pwg.categories.setInfo accepts.
   *
   * setInfo checks visible and commentable against the strings "true" and "false",
   * a PHP boolean becomes "1" or "" and is refused.
   *
   * @param bool|null $value
   * @return string|null "true", "false", or null to leave the field untouched
   */
  private function to_ws_bool(?bool $value)
  {
    return null === $value ? null : ($value ? 'true' : 'false');
  }
}
