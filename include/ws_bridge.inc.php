<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');


include_once(PHPWG_ROOT_PATH . 'include/ws_core.inc.php');

include_once(PHPWG_ROOT_PATH . 'include/ws_functions.inc.php');

use Mcp\Exception\ToolCallException;


class PiwigoMCPWebService
{
  private static $loaded = false;

  /**
   * Escape the string parameters the way common.inc.php escapes $_GET and $_POST.
   *
   * The ws_ functions, and core functions such as check_and_save_user_infos(), expect
   * that pre-escaped input: many write names straight into their SQL (create_tag(),
   * single_insert(), single_update()). The MCP arguments come from the JSON body and
   * are never escaped, so a quote in a name would break the query, or inject SQL.
   * Only strings are escaped, ints and booleans keep their type.
   *
   * @param array $params parameters for a ws_ or core function
   * @return array the same parameters, strings passed through addslashes()
   */
  public static function escape(array $params)
  {
    array_walk_recursive(
      $params,
      function (&$value) {
        if (is_string($value)) {
          $value = addslashes($value);
        }
      }
    );

    return $params;
  }

  /**
   * Check the result of a ws_function and turn it into plain arrays.
   *
   * @param mixed $result what the ws_ function returned
   * @return mixed the result, PwgNamedArray and PwgNamedStruct flattened to arrays
   */
  public static function check($result)
  {
    if ($result instanceof PwgError) {
      throw new ToolCallException($result->message());
    }

    PwgResponseEncoder::flattenResponse($result);

    return $result;
  }


  public static function service(string $method)
  {
    global $service;

    self::load();

    // PwgServer::isAuthorizedMethodForAPIKEY() reads it in the nested invoke(), as in ws.php
    $_REQUEST['method'] = $method;

    return $service;
  }

  /**
   * Call a web service method the way ws.php does.
   *
   * @param string $method name of the method, such as "pwg.users.getList"
   * @param array $params parameters of the method, null values are left out
   * @return mixed result of the method, flattened to plain arrays
   */
  public static function call(string $method, array $params = array())
  {
    global $service;

    self::load();

    // read by PwgServer::isAuthorizedMethodForAPIKEY() to apply $conf['api_key_forbidden_methods']
    $_REQUEST['method'] = $method;

    // a null value would replace the default of the method (per_page => null breaks the LIMIT)
    $params = array_filter(
      $params,
      function ($value) {
        return null !== $value;
      }
    );

    // same token as a key authenticated call on ws.php (include/user.inc.php)
    $params['pwg_token'] = get_pwg_token();

    // previous version, kept for comparison: the JSON arguments reached invoke() unescaped
    // $result = $service->invoke($method, $params);
    $result = $service->invoke($method, self::escape($params));

    if ($result instanceof PwgError) {
      throw new ToolCallException($result->message());
    }

    // PwgNamedArray and PwgNamedStruct become plain arrays
    PwgResponseEncoder::flattenResponse($result);

    return $result;
  }

  /**
   * Build the service ws.php runs, without letting it handle the current request.
   */
  private static function load()
  {
    global $conf, $service;

    if (self::$loaded) {
      return;
    }

    if (!$conf['allow_web_services']) {
      throw new ToolCallException('Web services are disabled on this Piwigo.');
    }

    // creates $service and hooks ws_addDefaultMethods() on ws_add_methods
    include_once(PHPWG_ROOT_PATH . 'include/ws_init.inc.php');

    // run() in ws.php loads the methods then answers the request, the MCP answers
    // itself so this handler makes the second step a no-op (no output in the MCP JSON)
    $noop_handler = new class extends PwgRequestHandler
    {
      function handleRequest(&$service) {}
    };
    $service->setHandler('mcp', $noop_handler);

    // ws.php defines PHPWG_ROOT_PATH again, that warning is the only one expected
    set_error_handler(
      function ($errno, $errstr) {
        return 0 === strpos($errstr, 'Constant PHPWG_ROOT_PATH already defined');
      },
      E_WARNING
    );
    ob_start();

    try {
      include_once(PHPWG_ROOT_PATH . 'ws.php');
    } finally {
      ob_end_clean();
      restore_error_handler();
    }

    if (!$service->hasMethod('pwg.getVersion')) {
      throw new ToolCallException('The Piwigo web service methods could not be loaded.');
    }

    // post_only methods check $_POST, a key authenticated call on ws.php has the token there too
    $_POST['pwg_token'] = $_GET['pwg_token'] = get_pwg_token();

    self::$loaded = true;
  }
}
