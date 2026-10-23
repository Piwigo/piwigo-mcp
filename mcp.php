<?php
// include and init piwigo core
define('PHPWG_ROOT_PATH', dirname(__FILE__) . '/../../');
include_once(PHPWG_ROOT_PATH . 'include/common.inc.php');

// verify if piwigo-mcp plugin is enabled
if (0 === count(get_db_plugins('active', 'piwigo-mcp')))
{
  http_response_code(503);
  header('Content-Type: application/json');
  die(json_encode(array('error' => 'Piwigo MCP is not enabled on this instance.')));
}

// require vendor and auth middleware
require_once __DIR__ . '/vendor/autoload.php';
include_once(__DIR__ . '/include/auth_middleware.inc.php');

// include tools (set manually)
include_once(__DIR__ . '/tools/core.tool.php');
include_once(__DIR__ . '/tools/user.tool.php');
include_once(__DIR__ . '/tools/album.tool.php');

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
  ->addTool([PiwigoUserCapabilities::class, 'get_user'], 'get_user', 'Get User', 'Get a user')
  ->addTool([PiwigoUserCapabilities::class, 'edit_user'], 'edit_user', 'Edit User', 'Edit the info of user')
  ->addTool([PiwigoUserCapabilities::class, 'delete_user'], 'delete_user', 'Delete User', 'Delete a user')
  ->addTool([PiwigoUserCapabilities::class, 'get_all_users'], 'get_all_users', 'Get All Users', 'Get all the users')
  ->addTool([PiwigoAlbumCapabilities::class, 'get_album'], 'get_album', 'Get Album', 'Get the albums matching a name')
  ->addTool([PiwigoAlbumCapabilities::class, 'get_all_albums'], 'get_all_albums', 'Get All Albums', 'Get all the albums in the gallery')
  ->addTool([PiwigoAlbumCapabilities::class, 'create_album'], 'create_album', 'Create Album', 'Create a virtual album')
  ->addTool([PiwigoAlbumCapabilities::class, 'delete_album'], 'delete_album', 'Delete Album', 'Delete an album')
  ->addTool([PiwigoAlbumCapabilities::class, 'edit_album'], 'edit_album', 'Edit Album', 'Edit the information of an album')
  ->build();

// setup http transport (instead of Stdio)
$transport = mcp_create_http_transport($request);

// send response
$response = $server->run($transport);
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values)
{
  foreach ($values as $value)
  {
    header($name.': '.$value, false);
  }
}
echo $response->getBody();
