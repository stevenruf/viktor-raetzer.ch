<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use pFrame\Core\pFrameCore;

$pf = new pFrameCore(dirname(__DIR__));
$pf->run();
