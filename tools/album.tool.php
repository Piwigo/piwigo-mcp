<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

class PiwigoAlbumCapabilities
{
  /**
   * Get the albums matching the name
   *
   * Info return: id, name, comment, parent album, status, visible, number of photos
   *
   * @param string $album_name name of the album, several albums can share the same name
   */
  public function get_album(string $album_name)
  {
    $album_name = trim($album_name);

    if ('' == $album_name)
    {
      throw new ToolCallException('Provide an album name.');
    }

    $album_ids = $this->fetch_album_id_from_name($album_name);

    if (0 == count($album_ids))
    {
      throw new ToolCallException('No album found with name "'.$album_name.'".');
    }

    return $this->get_all_albums($album_ids);
  }

  /**
   * List all albums
   *
   * Info return: id, name, comment, parent album, status, visible, number of photos
   */
  public function get_all_albums(?array $album_ids = null, ?int $limit = null)
  {
    if (null !== $album_ids and 0 == count($album_ids))
    {
      return array('albums' => array());
    }

    $query = '
      SELECT
          id,
          name,
          comment,
          id_uppercat,
          uppercats,
          status,
          visible
      FROM '.CATEGORIES_TABLE
      .(null !== $album_ids ? '
      WHERE id IN ('.implode(',', array_map('intval', $album_ids)).')' : '')
      .'
      ORDER BY global_rank'
      .(null !== $limit ? '
      LIMIT '.intval($limit) : '').'
      ;';

    $albums_list = array();

    foreach (query2array($query) as $album_row)
    {
      $album_id = intval($album_row['id']);

      $album_row['id'] = $album_id;
      $album_row['id_uppercat'] = null === $album_row['id_uppercat'] ? null : intval($album_row['id_uppercat']);

      //init of the album photo counter
      $album_row['nb_images'] = 0;

      $albums_list[$album_id] = $album_row;
    }

    // Get the number of photos directly linked to each album
    if (count($albums_list) > 0)
    {
      $count_query = '
  SELECT
    category_id,
    COUNT(*) AS nb_images
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id IN ('.implode(',', array_keys($albums_list)).')
  GROUP BY category_id;';

      foreach (query2array($count_query) as $count)
      {
        $albums_list[ intval($count['category_id']) ]['nb_images'] = intval($count['nb_images']);
      }
    }

    return array('albums' => array_values($albums_list));
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
    #[Schema(enum: array('first','last'))]
    ?string $position = null,
    ?string $comment = null,
    #[Schema(enum: array('public', 'private'))]
    ?string $status = null,
    ?bool $visible = null,
    ?bool $commentable = null,
    ?bool $inherit = null
    )
  {
    global $conf;

    $album_name = trim($album_name);

    if ('' == $album_name)
    {
      throw new ToolCallException('Provide a name for the album to create.');
    }

    if (null !== $parent_id or (null !== $parent_name and '' != trim($parent_name)))
    {
      $parent_id = $this->resolve_single_album($parent_name, $parent_id);
    }

    $options = array(
      'comment' => $comment,
      'status' => $status,
      'visible' => $visible,
      'commentable' => $commentable,
      'inherit' => $inherit,
      );

    foreach ($options as $option => $value)
    {
      if (null === $value)
      {
        unset($options[$option]);
      }
    }

    //use Piwigo core function
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    if ($position != null) 
    {
      $conf['newcat_default_position'] = $position;
    }
    $created = \create_virtual_category($album_name, $parent_id, $options);

    if (isset($created['error']))
    {
      throw new ToolCallException($created['error']);
    }

    \invalidate_user_cache();

    return $this->get_all_albums(array($created['id']));
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
  * @param string|null $status "private" restricts the album to the users granted access to it
  */
  public function edit_album(
    ?string $album_name = null,
    ?int $album_id = null,
    ?string $new_album_name = null,
    ?string $comment = null,
    ?bool $visible = null,
    #[Schema(enum: array('public','private'))]
    ?string $status = null,
    // ?string $image_order = null
  )
  {
    $album_id = $this->resolve_single_album($album_name, $album_id);

    if (null !== $new_album_name and '' == trim($new_album_name))
    {
      throw new ToolCallException('An album cannot be renamed to an empty name.');
    }

    $fields = array(
      'name' => null === $new_album_name ? null : trim($new_album_name),
      'comment' => $comment,
      'status' => $status,
      // Piwigo core validates visible against the strings "true" and "false"
      'visible' => null === $visible ? null : ($visible ? 'true' : 'false'),
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

    $params['category_id'] = $album_id;

    //use Piwigo core function, ws_core holds the PwgError it returns on failure
    include_once(PHPWG_ROOT_PATH.'include/ws_core.inc.php');
    include_once(PHPWG_ROOT_PATH.'include/ws_functions/pwg.categories.php');
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    // ws_categories_setInfo() takes the web service by reference, it is unused on this path
    $service = null;
    $updated = \ws_categories_setInfo($params, $service);

    if ($updated instanceof \PwgError)
    {
      throw new ToolCallException($updated->message());
    }

    // status and visible rewrite the whole subtree
    \invalidate_user_cache();

    return $this->get_all_albums(array($album_id));
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
    #[Schema(enum: array('no_delete','delete_orphans','force_delete'))]
    ?string $mode = 'no_delete'
    )
  {
    $album_id = $this->resolve_single_album($album_name, $album_id);

    //use Piwigo core function
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    // delete_categories() removes the whole subtree, list it before the rows are gone
    $deleted_paths = $this->build_album_paths(get_subcat_ids(array($album_id)));

    \delete_categories(array($album_id), null === $mode ? 'no_delete' : $mode);
    \update_global_rank();
    \invalidate_user_cache();

    $deleted_albums = array();

    foreach ($deleted_paths as $deleted_id => $path)
    {
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

  /********************************************************
   *
   *Helper funcion
   *
   ********************************************************/
  /**
   * Fetch the ids of the albums having this name.
   *
   * Several albums can share the same name, so every match is returned.
   *
   * @param string $album_name name to look for
   * @return int[] ids of the matching albums, empty when none matches
   */
  private function fetch_album_id_from_name(string $album_name)
  {
    $query = '
      SELECT
          id
      FROM '.CATEGORIES_TABLE.'
      WHERE name = \''.pwg_db_real_escape_string($album_name).'\'
      ORDER BY global_rank
      ;';

    return array_map('intval', query2array($query, null, 'id'));
  }

  /**
   * Resolve a name or an id to the single album an action applies to.
   *
   * The id is prioritized when both are given, a name matching several albums is refused.
   *
   * @param string|null $album_name name to look for, ignored when album_id is given
   * @param int|null $album_id id of the album
   * @return int id of the album
   */
  private function resolve_single_album(?string $album_name, ?int $album_id)
  {
    if (null !== $album_id)
    {
      $album = $this->get_all_albums(array($album_id));

      if (0 == count($album['albums']))
      {
        throw new ToolCallException('No album with the id '.$album_id.'.');
      }

      return $album_id;
    }

    $album_name = null === $album_name ? '' : trim($album_name);

    if ('' == $album_name)
    {
      throw new ToolCallException('Provide an album name or an album id.');
    }

    $album_ids = $this->fetch_album_id_from_name($album_name);

    if (0 == count($album_ids))
    {
      throw new ToolCallException('No album found with name "'.$album_name.'".');
    }

    // several albums can share the same name, the caller has to tell which one
    if (count($album_ids) > 1)
    {
      $candidates = array();

      foreach ($this->build_album_paths($album_ids) as $candidate_id => $path)
      {
        $candidates[] = 'album_id '.$candidate_id.' ('.$path.')';
      }

      throw new ToolCallException(
        count($album_ids).' albums are named "'.$album_name.'": '.implode(', ', $candidates)
        .'. Call again with the album_id of the one you want.'
        );
    }

    return $album_ids[0];
  }

  /**
   * Build the readable path of each album, such as "future / sub_future / sub_album".
   *
   * @param int[] $album_ids ids of the albums to describe
   * @return array<int,string> path of each album, keyed by id
   */
  private function build_album_paths(array $album_ids)
  {
    if (0 == count($album_ids))
    {
      return array();
    }

    $query = '
      SELECT
          id,
          uppercats
      FROM '.CATEGORIES_TABLE.'
      WHERE id IN ('.implode(',', array_map('intval', $album_ids)).')
      ORDER BY global_rank
      ;';

    $paths = array();

    foreach (query2array($query) as $album_row)
    {
      // Piwigo core turns uppercats into a breadcrumb, on a cached id => name map
      $paths[ intval($album_row['id']) ] = strip_tags(get_cat_display_name_cache($album_row['uppercats'], null));
    }

    return $paths;
  }

}
