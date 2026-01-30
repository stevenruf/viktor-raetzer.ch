<?php
declare(strict_types=1);

return [
  'environment' => getenv('APP_ENV') ?: 'development',
  'debug' => getenv('APP_DEBUG') ? filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOL) : true,
];
