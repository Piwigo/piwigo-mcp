<?php

use Http\Discovery\Psr17Factory;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

function mcp_create_request()
{
  $psr17 = new Psr17Factory();
  $request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
  $host_header = $request->getHeader('Host');
  if (!empty($host_header))
  {
    $request = $request->withHeader('Host', $host_header[0]);
  }

  return array(
    'request' => $request,
    'psr17' => $psr17,
  );
}

function mcp_create_http_transport(array $request)
{
  $host = parse_url(get_absolute_root_url(), PHP_URL_HOST);
  $transport = new StreamableHttpTransport(
    $request['request'],
    $request['psr17'],
    $request['psr17'],
    null,
    array(
      new CorsMiddleware(),
      new DnsRebindingProtectionMiddleware(array($host, 'localhost', '127.0.0.1')),
      new ProtocolVersionMiddleware(),
      new Piwigo_MCP_AuthMiddleware(),
    )
  );

  return $transport;
}

function mcp_get_session_dir()
{
  global $conf;

  $session_dir = isset($conf['piwigo_mcp_session_dir'])
    ? $conf['piwigo_mcp_session_dir']
    : sys_get_temp_dir() . '/piwigo_mcp_' . substr(md5(PHPWG_ROOT_PATH), 0, 12) . '/sessions';

  // create session dir in 0700
  if (!is_dir($session_dir))
  {
    @mkdir($session_dir, 0700, true);
  }

  return $session_dir;
}
