<?php
// include and init piwigo core
define('PHPWG_ROOT_PATH', dirname(__FILE__) . '/../../');
include_once(PHPWG_ROOT_PATH . 'include/common.inc.php');

// verify if piwigo-mcp plugin is enabled and correctly bootstrapped by main.inc.php
// (MCP_PATH is undefined if main.inc.php returned early: bad folder name or PHP < 8.1)
if (0 === count(get_db_plugins('active', 'piwigo-mcp')) or !defined('MCP_PATH'))
{
  http_response_code(503);
  header('Content-Type: application/json');
  die(json_encode(array('error' => 'Piwigo MCP is not enabled on this instance.')));
}

// require vendor and auth middleware
require_once(MCP_PATH . 'vendor/autoload.php');
include_once(MCP_PATH . 'include/auth_middleware.inc.php');

// include tools (set manually)
include_once(MCP_PATH . 'tools/core.tool.php');
include_once(MCP_PATH . 'tools/user.tool.php');

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
  ->setDiscovery(MCP_REALPATH, array('tools'))
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
