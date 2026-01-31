<?php
declare(strict_types=1);

return [
  [
    'method'  => ['GET'],
    'path'    => '/app/optimizeimages/',
    'handler' => 'App\\optimizeimages\\OptimizeImagesController@run',
  ],
  [
    'method'  => ['GET'],
    'path'    => '/api/optimizeimages/status/',
    'handler' => 'App\\optimizeimages\\OptimizeImagesController@apiStatus',
  ],
  [
    'method'  => ['POST'],
    'path'    => '/api/optimizeimages/run/',
    'handler' => 'App\\optimizeimages\\OptimizeImagesController@apiRun',
  ],
];