<?php

namespace bbn\util;

use bbn;
use bbn\x;

class jwt
{

  protected $payload;

  protected $key;

  protected $sub;

  protected $aud;

  protected $ttl;

  public function prepare(string $id_user, string $fingerprint, int $ttl = 300)
  {
    $this->sub = $fingerprint;
    $this->aud = $id_user;
    $this->ttl = $ttl;
    $this->reset();
  }

  public function reset(): self
  {
    $this->payload = [
      "iss" => BBN_SERVER_NAME,
      "iat" => time(),
      "exp" => time() + $this->ttl,
      "sub" => $this->sub,
      "aud" => $this->aud,
      "data" => []
    ];
    return $this;
  }

  public function set_key($cert): self
  {
    $this->key = $cert;
    return $this;
  }

  public function set(array $data): string
  {
    $this->payload['data'] = $data;
    try {
      if ($this->key) {
        $jwt = \Firebase\JWT\JWT::encode($this->payload, $this->key, 'RS512');
      }
      else {
        $jwt = \Firebase\JWT\JWT::encode($this->payload, $this->payload['sub'], 'HS256');
      }
    }
    catch (\Firebase\JWT\ExpiredException $e) {
      x::hdump($e->getMessage());
      throw new \Exception($e);
    }
    catch (\Exception $e) {
      x::hdump($e->getMessage());
      throw new \Exception($e);
    }
    return $jwt;
  }

  public function get(string $jwt): ?array
  {
    try {
      $payload = \Firebase\JWT\JWT::decode($jwt, $this->key, ['HS256', 'RS512']);
    }
    catch (\Exception $e) {
      x::hdump($e->getMessage());
      throw new \Exception($e);
    }
    if (!empty($payload->data)) {
      return x::to_array($payload->data);
    }
    return null;
  }
}