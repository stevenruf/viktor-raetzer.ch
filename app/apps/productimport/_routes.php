<?php
declare(strict_types=1);

return [
  [
    'method'  => ['GET'],
    'path'    => '/app/productimport/',
    'handler' => 'App\\productimport\\ProductImportController@run',
  ],
  [
    'method'  => ['GET'],
    'path'    => '/api/productimport/list/',
    'handler' => 'App\\productimport\\ProductImportController@apiList',
  ],
  [
    'method'  => ['POST'],
    'path'    => '/api/productimport/upload/',
    'handler' => 'App\\productimport\\ProductImportController@apiUpload',
  ],
  [
    'method'  => ['POST'],
    'path'    => '/api/productimport/zip/',
    'handler' => 'App\\productimport\\ProductImportController@apiZip',
  ],
];