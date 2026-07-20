<?php

return [
    'enabled' => env('DEV_LOGIN_HELPER', false),
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', env('DEV_LOGIN_HELPER_HOSTS', 'localhost,127.0.0.1,192.168.34.104,college-dev.local'))))),
    'roles' => [
        'admin' => 'Администратор',
        'director' => 'Директор',
        'hr' => 'Отдел кадров',
        'study' => 'Учебная часть',
        'teacher' => 'Педагог',
        'student' => 'Студент',
        'security' => 'Оператор проходной',
        'access_admin' => 'Администратор проходной',
    ],
];
