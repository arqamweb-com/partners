<?php
/**
 * إعدادات قاعدة بيانات الاختبار — منفصلة تمامًا عن قاعدة التطوير.
 * setup.php يعمل TRUNCATE لكل الجداول، فلا توجّهه أبدًا لقاعدة فيها بيانات.
 *
 *   cp api/tests/config.example.php api/tests/config.php
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 8889,               // MAMP
        'name'    => 'arqam_flow_test',
        'user'    => 'root',
        'pass'    => 'root',
        'charset' => 'utf8mb4',
    ],
    'session' => ['name' => 'arqam_test', 'lifetime' => 3600, 'secure' => false],
    'debug'   => true,
];
