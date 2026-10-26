<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

class PiwigoTagCapabilities
{
    /**
     * List the tags one page at a time, or find one tag
     *
     * tag_name and tag_id match exactly, to get one tag. search matches a part of a tag
     * name. All the filters given apply together.
     *
     * Info return: paging (page, per_page, count, total_count) and tags (id, name, url_name, lastmodified, number of photos)
     *
     * @param string|null $search part of the tag name
     * @param int|null $page page to return, the first one is 0 (the default)
     * @param int|null $per_page number of tags per page, 50 by default
     * @param string|null $tag_name only the tag with exactly this name, case ignored
     * @param int|null $tag_id only the tag with this id
     */
    public function get_all_tags(
        ?string $search = null,
        ?int $page = null,
        #[Schema(minimum: 1, maximum: 200)]
        ?int $per_page = null,
        ?string $tag_name = null,
        ?int $tag_id = null
    ) {
        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.tags.php');

        $search = null === $search ? '' : trim($search);
        $tag_name = null === $tag_name ? '' : trim($tag_name);

        // ws_tags_getAdminList() lists every tag but without counts, ws_tags_getList() counts the
        // photos the caller can see but skips the tags without photos, so both are merged
        $service = null;
        $all_tags = PiwigoMCPWebService::check(\ws_tags_getAdminList(array(), $service));

        // ws_tags_getList() reads sort_by_counter without checking it
        $counted_tags = PiwigoMCPWebService::check(\ws_tags_getList(array(
            'sort_by_counter' => false,
        ), $service));

        $nb_images_of = array_column($counted_tags['tags'], 'counter', 'id');
        $tags = array();

        // neither function filters, the filters given apply together here
        foreach ($all_tags['tags'] as $tag) {
            if (null !== $tag_id and $tag['id'] != $tag_id) {
                continue;
            }

            if ('' != $tag_name and 0 != strcasecmp($tag['name'], $tag_name)) {
                continue;
            }

            if ('' != $search and false === mb_stripos($tag['name'], $search)) {
                continue;
            }

            $tags[] = array(
                'id' => intval($tag['id']),
                'name' => $tag['name'],
                'url_name' => $tag['url_name'],
                'lastmodified' => $tag['lastmodified'],
                'nb_images' => intval($nb_images_of[$tag['id']] ?? 0),
            );
        }

        // ws_tags_getAdminList() has no paging, page its result so many tags fit the model context:
        // the first page is 0, 50 tags by default, 200 at most
        $page = max(0, $page ?? 0);
        $per_page = max(1, min($per_page ?? 50, 200));
        $page_tags = array_slice($tags, $page * $per_page, $per_page);

        return array(
            'paging' => array(
                'page' => $page,
                'per_page' => $per_page,
                'count' => count($page_tags),
                'total_count' => count($tags),
            ),
            'tags' => $page_tags,
        );
    }

    /**
     * Create a tag
     *
     * Info return: id, name, url_name
     *
     * @param string $tag_name name of the tag, must not be used by another tag
     */
    public function create_tag(string $tag_name)
    {
        $tag_name = trim($tag_name);

        if ('' == $tag_name) {
            throw new ToolCallException('Provide a name for the tag to create.');
        }

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.tags.php');

        // ws_tags_add() passes the name to create_tag(), which writes it into its SQL as given
        $service = null;
        $created = PiwigoMCPWebService::check(\ws_tags_add(PiwigoMCPWebService::escape(array(
            'name' => $tag_name,
        )), $service));

        return array(
            'tag' => array(
                'id' => intval($created['id']),
                'name' => $created['name'],
                'url_name' => $created['url_name'],
            ),
        );
    }

    /**
     * Rename a tag
     *
     * Info return: id, name, url_name
     *
     * @param string $new_tag_name new name of the tag, must not be used by another tag
     * @param string|null $tag_name current name of the tag, ignored when tag_id is given
     * @param int|null $tag_id id of the tag
     */
    public function rename_tag(string $new_tag_name, ?string $tag_name = null, ?int $tag_id = null)
    {
        $tag = $this->resolve_tag($tag_name, $tag_id);
        $new_tag_name = trim($new_tag_name);

        // ws_tags_rename() would run an empty update on an empty name
        if ('' == $new_tag_name) {
            throw new ToolCallException('A tag cannot be renamed to an empty name.');
        }

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.tags.php');

        // ws_tags_rename() puts tag_id straight into its SQL
        $service = null;
        $renamed = PiwigoMCPWebService::check(\ws_tags_rename(PiwigoMCPWebService::escape(array(
            'tag_id' => intval($tag['id']),
            'new_name' => $new_tag_name,
            'pwg_token' => get_pwg_token(),
        )), $service));

        return array(
            'tag' => array(
                'id' => intval($renamed['id']),
                'name' => $renamed['name'],
                'url_name' => $renamed['url_name'],
            ),
        );
    }

    /**
     * Delete a tag
     *
     * The photos are kept, they only lose the tag.
     *
     * Info return: id and name of the tag deleted
     *
     * @param string|null $tag_name name of the tag, ignored when tag_id is given
     * @param int|null $tag_id id of the tag
     */
    public function delete_tag(?string $tag_name = null, ?int $tag_id = null)
    {
        $tag = $this->resolve_tag($tag_name, $tag_id);

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.tags.php');

        // ws_tags_delete() puts tag_id straight into its SQL
        $service = null;
        PiwigoMCPWebService::check(\ws_tags_delete(PiwigoMCPWebService::escape(array(
            'tag_id' => array(intval($tag['id'])),
            'pwg_token' => get_pwg_token(),
        )), $service));

        return array(
            'deleted' => true,
            'tag' => array(
                'id' => intval($tag['id']),
                'name' => $tag['name'],
            ),
        );
    }

    /**
     * Merge tags into another tag
     *
     * The photos of the merged tags get the destination tag, then the merged tags are deleted.
     *
     * Info return: the destination tag after the merge and the names of the tags deleted
     *
     * @param string[] $merge_tag_names names of the tags to merge and delete
     * @param string|null $destination_tag_name name of the tag that receives the photos, ignored when destination_tag_id is given
     * @param int|null $destination_tag_id id of the tag that receives the photos
     */
    public function merge_tags(
        array $merge_tag_names,
        ?string $destination_tag_name = null,
        ?int $destination_tag_id = null
    ) {
        $destination = $this->resolve_tag($destination_tag_name, $destination_tag_id);

        $merge_ids = array();
        $merge_names = array();

        foreach ($merge_tag_names as $merge_tag_name) {
            $merge_tag = $this->resolve_tag($merge_tag_name, null);

            if ($merge_tag['id'] == $destination['id']) {
                throw new ToolCallException('A tag cannot be merged into itself ("' . $merge_tag['name'] . '").');
            }

            $merge_ids[] = intval($merge_tag['id']);
            $merge_names[] = $merge_tag['name'];
        }

        if (0 == count($merge_ids)) {
            throw new ToolCallException('Provide at least one tag to merge.');
        }

        include_once(PHPWG_ROOT_PATH . 'include/ws_functions/pwg.tags.php');

        // ws_tags_merge() puts the ids straight into its SQL
        $service = null;
        PiwigoMCPWebService::check(\ws_tags_merge(PiwigoMCPWebService::escape(array(
            'destination_tag_id' => intval($destination['id']),
            'merge_tag_id' => array_values(array_unique($merge_ids)),
            'pwg_token' => get_pwg_token(),
        )), $service));

        return array(
            'tag' => $this->resolve_tag(null, intval($destination['id'])),
            'deleted_tags' => array_values(array_unique($merge_names)),
        );
    }

    /********************************************************
     *
     *Helper funcion
     *
     ********************************************************/
    /**
     * The one tag a tool acts on, found through get_all_tags().
     *
     *
     * @param string|null $tag_name name of the tag, ignored when tag_id is given
     * @param int|null $tag_id id of the tag
     * @return array id, name, url_name, lastmodified, nb_images
     */
    private function resolve_tag(?string $tag_name, ?int $tag_id)
    {
        $tag_name = null === $tag_name ? '' : trim($tag_name);

        if (null === $tag_id and '' == $tag_name) {
            throw new ToolCallException('Provide a tag name or a tag id.');
        }

        $tags = $this->get_all_tags(
            per_page: 1,
            tag_name: null === $tag_id ? $tag_name : null,
            tag_id: $tag_id
        )['tags'];

        if (count($tags) > 0) {
            return $tags[0];
        }

        throw new ToolCallException(
            null !== $tag_id
                ? 'No tag with the id ' . $tag_id . '.'
                : 'No tag found with name "' . $tag_name . '".'
        );
    }
}
