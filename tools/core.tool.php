<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

use Mcp\Capability\Attribute\McpTool;

class PiwigoCoreCapabilities
{
  #[McpTool(name:'piwigo_ver',title:'Piwigo Version',description:'Get the Piwigo version')]
  public function get_piwigo_version()
  {
    return PHPWG_VERSION;
  }

  #[McpTool(name:'php_ver',title:'PHP Version',description:'Get the PHP version')]
  public function get_php_version()
  {
    return PHP_VERSION;
  }

  #[McpTool(name:'mariadb_ver',title:'MariaDB Version',description:'Get the database version')]
  public function get_mariadb_version()
  {
    return pwg_get_db_version();
  }

  #[McpTool(name:'piwigo_status',title:'Piwigo status',description:'Get Piwigo status')]
  public function get_status()
  {
    global $user;

    return array(
      'piwigo_version' => PHPWG_VERSION,
      'php_version' => PHP_VERSION,
      'mariadb_version' => pwg_get_db_version(),
      'user_id' => $user['id'], 
    );
  }
}