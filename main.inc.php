<?php
/*
Plugin Name: Piwigo MCP
Version: auto
Description: Add MCP server to your Piwigo
Plugin URI: http://piwigo.org/ext/extension_view.php?eid=
Author: Piwigo team
Author URI: http://piwigo.com
Has Settings: false
*/

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

// check root directory
if (basename(dirname(__FILE__)) != 'piwigo-mcp')
{
  add_event_handler('init', 'mcp_error');
  function mcp_error()
  {
    global $page;
    $page['errors'][] = 'Piwigo MCP plugin folder name is incorrect, uninstall the plugin and rename it to "piwigo-mcp"';
  }
  return;
}

// check php version
if (version_compare(PHP_VERSION, '8.1', '<'))
{
  add_event_handler('init', 'mcp_version_error');
  function mcp_version_error()
  {
    global $page;
    $page['errors'][] = 'PHP 8.1 or higher is required to enable the "piwigo-mcp" plugin';
  }
  return;
}

// +-----------------------------------------------------------------------+
// | Define plugin constants                                               |
// +-----------------------------------------------------------------------+

define('MCP_ID', basename(dirname(__FILE__)));
define('MCP_PATH', PHPWG_PLUGINS_PATH . MCP_ID . '/');
define('MCP_REALPATH', realpath(MCP_PATH));

// +-----------------------------------------------------------------------+
// | Init Piwigo Tag Groups                                                |
// +-----------------------------------------------------------------------+

include_once(MCP_PATH . 'include/functions.inc.php');

// $events_functions = MCP_PATH.'include/events.inc.php';
// add_event_handler('init', 'mcp_init', EVENT_HANDLER_PRIORITY_NEUTRAL, $events_functions);
