<?php

declare(strict_types=1);

return [
    'paths' => [
        'src',
    ],

    'exclude' => [
        'migrations',
        'var',
        'vendor',
        'docker',
        'bootstrap.php',
        'src/Kernel.php',
    ],

    'preset' => 'symfony',
];



