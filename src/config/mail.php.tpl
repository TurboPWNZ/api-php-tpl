<?php
return [
    'driver' => 'smtp',
    'smtp' => [
        'host' => 'mail.adm.tools',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => 'noreply@domain.com',
        'password' => '',
    ],
    'from' => [
        'email' => 'noreply@domain.com',
        'name' => 'noreply',
    ],
];
