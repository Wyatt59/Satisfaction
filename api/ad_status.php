<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ldap_auth.php';

echo json_encode([
    'success'   => true,
    'available' => isActiveDirectoryAvailable($pdo),
]);
