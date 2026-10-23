<?php
return [
    'db' => ['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null],
    'app_key' => base64_encode(str_repeat('k', 32)),
    'web_root' => sys_get_temp_dir() . '/ina-postaje-test-public',
    'ina_delay_ms' => 0,
];
