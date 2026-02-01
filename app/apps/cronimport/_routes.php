<?php
declare(strict_types=1);

return [
  [
    'method'  => ['GET'],
    'path'    => '/app/cronimport/',
    'handler' => 'App\\cronimport\\CronImportController@run',
  ],
  [
    'method'  => ['POST'],
    'path'    => '/api/cronimport/run/',
    'handler' => 'App\\cronimport\\CronImportController@apiRun',
  ],
];