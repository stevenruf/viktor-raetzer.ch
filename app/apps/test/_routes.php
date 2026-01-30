<?php
declare(strict_types=1);

return [
  [
    'method'  => ['GET'],
    'path'    => '/app/test/',
    'handler' => 'App\\test\\TestController@run',
  ],
  [
    'method'  => ['GET'],
    'path'    => '/api/test/',
    'handler' => 'App\\test\\TestController@api',
  ],
];
