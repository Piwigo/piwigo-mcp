<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class piwigo_mcp_maintain extends PluginMaintain
{
  function __construct($plugin_id)
  {
    parent::__construct($plugin_id);
  }

  /**
   * Plugin install
   */
  function install($plugin_version, &$errors = array())
  {
  }

  /**
   * Plugin activate
   */
  function activate($plugin_version, &$errors = array())
  {
  }

  /**
   * Plugin deactivate
   */
  function deactivate()
  {
  }

  /**
   * Plugin update
   */
  function update($old_version, $new_version, &$errors = array())
  {
    $this->install($new_version, $errors);
  }

  /**
   * Plugin uninstallation
   */
  function uninstall()
  { 
  }

}
