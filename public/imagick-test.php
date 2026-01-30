<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

echo phpversion() . "\n\n";

echo "Imagick class exists: ";
var_dump(class_exists('Imagick'));

echo "\n\nLoaded PHP extensions:\n";
print_r(get_loaded_extensions());

