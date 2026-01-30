<?php
declare(strict_types=1);

namespace App\test;

class TestController
{
  public function run(array $params = []): void
  {
    echo "pFrame Route /app/test/ works ✅";
  }

  public function api(array $params = []): void
  {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => true,
      'message' => 'pFrame API works ✅',
      'uri' => $_SERVER['REQUEST_URI'] ?? null,
    ], JSON_PRETTY_PRINT);
  }
}
