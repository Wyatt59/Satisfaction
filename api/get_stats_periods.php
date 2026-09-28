<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$siteId = isset($_GET['site_id']) ? (string)$_GET['site_id'] : 'all';

$siteFilterSql = '';
$params = [];

if ($siteId !== 'all' && $siteId !== '' && ctype_digit($siteId)) {
    $siteFilterSql = ' WHERE site_id = :site_id';
    $params[':site_id'] = (int)$siteId;
}

$stmt = $pdo->prepare(
    "SELECT DISTINCT YEAR(date_creation) AS annee, MONTH(date_creation) AS mois
     FROM demandes" . $siteFilterSql . "
     ORDER BY annee DESC, mois DESC"
);
$stmt->execute($params);

$rows = $stmt->fetchAll();

echo json_encode([
    'success'  => true,
    'periodes' => $rows,
]);
