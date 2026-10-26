<?php
// include and init piwigo core
define('PHPWG_ROOT_PATH', dirname(__FILE__) . '/../../');
include_once(PHPWG_ROOT_PATH . 'include/common.inc.php');

if (0 === count(get_db_plugins('active', 'piwigo-mcp')) or !defined('MCP_REALPATH') or false === MCP_REALPATH) {
  http_response_code(503);
  header('Content-Type: application/json');
  die(json_encode(array('error' => 'Piwigo MCP is not enabled on this instance.')));
}

// require vendor and auth middleware
// MCP_REALPATH (main.inc.php) is the resolved plugin folder, without a trailing slash
require_once(MCP_REALPATH . '/vendor/autoload.php');
include_once(MCP_REALPATH . '/include/auth_middleware.inc.php');
include_once(MCP_REALPATH . '/include/ws_bridge.inc.php');
include_once(MCP_REALPATH . '/tools/core.tool.php');
include_once(MCP_REALPATH . '/tools/user.tool.php');
include_once(MCP_REALPATH . '/tools/album.tool.php');
include_once(MCP_REALPATH . '/tools/group.tool.php');
include_once(MCP_REALPATH . '/tools/tag.tool.php');

use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;

// build $request
$request = mcp_create_request();

// not recommended to put session under _data or whatever because
// nginx/apache expose it. so create a tmp session folder (customizable in localfileditor) 
$session_dir = mcp_get_session_dir();

// build mcp server
$server = Server::builder()
  ->setServerInfo('Piwigo MCP Server', '0.0.1', 'Piwigo MCP Server')
  ->setSession(new FileSessionStore($session_dir))
  ->addTool([PiwigoCoreCapabilities::class, 'get_piwigo_version'], 'piwigo_ver', 'Piwigo Version', 'Get the Piwigo version')
  ->addTool([PiwigoCoreCapabilities::class, 'get_php_version'], 'php_ver', 'PHP Version', 'Get the PHP version')
  ->addTool([PiwigoCoreCapabilities::class, 'get_mariadb_version'], 'mariadb_ver', 'MariaDB Version', 'Get the database version')
  ->addTool([PiwigoCoreCapabilities::class, 'get_status'], 'piwigo_status', 'Piwigo status', 'Get Piwigo status')
  ->addTool([PiwigoUserCapabilities::class, 'register_user'], 'register_user', 'Register User', 'Register a new user with a random password nobody sees, and optionally mail them a link to set their own. Use edit_user afterwards for status, level, language, theme or groups')
  ->addTool([PiwigoUserCapabilities::class, 'edit_user'], 'edit_user', 'Edit User', 'Edit the info of user')
  ->addTool([PiwigoUserCapabilities::class, 'delete_user'], 'delete_user', 'Delete User', 'Delete a user')
  ->addTool([PiwigoUserCapabilities::class, 'get_all_users'], 'get_all_users', 'Get All Users', 'List the users one page at a time with the total count, filter them, or find one user by id, username or email')
  ->addTool([PiwigoAlbumCapabilities::class, 'get_all_albums'], 'get_all_albums', 'Get All Albums', 'List the albums one level of the tree at a time with paging, search them, or find albums by exact name or id at any level')
  ->addTool([PiwigoAlbumCapabilities::class, 'create_album'], 'create_album', 'Create Album', 'Create a virtual album')
  ->addTool([PiwigoAlbumCapabilities::class, 'delete_album'], 'delete_album', 'Delete Album', 'Delete an album')
  ->addTool([PiwigoAlbumCapabilities::class, 'move_album'], 'move_album', 'Move Album', 'Move an album')
  ->addTool([PiwigoAlbumCapabilities::class, 'edit_album'], 'edit_album', 'Edit Album', 'Edit the information of an album')
  ->addTool([PiwigoGroupCapabilities::class, 'get_group'], 'get_group', 'Get Group', 'List the groups one page at a time with the total count, search or sort them, or find one group by name or id. A group\'s members are listed by get_all_users with its group_id')
  ->addTool([PiwigoGroupCapabilities::class, 'create_group'], 'create_group', 'Create Group', 'Create a group')
  ->addTool([PiwigoGroupCapabilities::class, 'edit_group'], 'edit_group', 'Edit Group', 'Rename a group or change whether new users join it')
  ->addTool([PiwigoGroupCapabilities::class, 'delete_group'], 'delete_group', 'Delete Group', 'Delete a group, its users are kept')
  ->addTool([PiwigoGroupCapabilities::class, 'add_users_to_group'], 'add_users_to_group', 'Add Users To Group', 'Add users to a group')
  ->addTool([PiwigoGroupCapabilities::class, 'remove_users_from_group'], 'remove_users_from_group', 'Remove Users From Group', 'Remove users from a group, the users are kept')
  ->addTool([PiwigoGroupCapabilities::class, 'merge_groups'], 'merge_groups', 'Merge Groups', 'Move the users of several groups into one group and delete the others')
  ->addTool([PiwigoTagCapabilities::class, 'get_all_tags'], 'get_all_tags', 'Get All Tags', 'List the tags one page at a time with the total count, search them, or find one tag by name or id')
  ->addTool([PiwigoTagCapabilities::class, 'create_tag'], 'create_tag', 'Create Tag', 'Create a tag')
  ->addTool([PiwigoTagCapabilities::class, 'rename_tag'], 'rename_tag', 'Rename Tag', 'Rename a tag')
  ->addTool([PiwigoTagCapabilities::class, 'delete_tag'], 'delete_tag', 'Delete Tag', 'Delete a tag, its photos are kept')
  ->addTool([PiwigoTagCapabilities::class, 'merge_tags'], 'merge_tags', 'Merge Tags', 'Give the photos of several tags one tag and delete the others')
  ->build();

// setup http transport (instead of Stdio)
$transport = mcp_create_http_transport($request);

// send response
$response = $server->run($transport);
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
  foreach ($values as $value) {
    header($name . ': ' . $value, false);
  }
}
echo $response->getBody();
