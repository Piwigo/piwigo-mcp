<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

class PiwigoCoreCapabilities
{
  public function get_piwigo_version()
  {
    return PHPWG_VERSION;
  }

  public function get_php_version()
  {
    return PHP_VERSION;
  }

  public function get_mariadb_version()
  {
    return pwg_get_db_version();
  }

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