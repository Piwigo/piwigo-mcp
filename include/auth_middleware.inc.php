<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Response;

class Piwigo_MCP_AuthMiddleware implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    global $user;

    $apikey = $this->extract_apikey($request);

    // verify api key
    if (null === $apikey or !auth_key_login($apikey, true))
    {
      return $this->unauthorized();
    }

    // build user to keep permission
    $user = build_user($user['id'], false);

    // for now this mcp is only for admin
    if (!is_admin())
    {
      return $this->unauthorized();
    }

    return $handler->handle($request);
  }

  private function extract_apikey(ServerRequestInterface $request): ?string
  {
    if (preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $matches))
    {
      return $matches[1];
    }

    // fallback on natif piwigo header
    $apikey = trim($request->getHeaderLine('X-PIWIGO-API'));

    return '' === $apikey ? null : $apikey;
  }

  private function unauthorized(): ResponseInterface
  {
    return new Response(
      401,
      array(
        'Content-Type' => 'application/json',
        'WWW-Authenticate' => 'Bearer error="invalid_token"',
      ),
      json_encode(array(
        'error' => 'Unauthorized',
        'message' => 'A Piwigo API key is required. Create one in your profile, then send it as Authorization: Bearer <key_id>:<key_secret>.',
      ))
    );
  }
}
