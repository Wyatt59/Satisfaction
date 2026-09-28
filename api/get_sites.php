<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

// Seuls les sites déjà nommés par un administrateur apparaissent dans le
// sélecteur de statistiques (un préfixe IP non configuré n'a pas de nom
// exploitable côté interface).
$stmt = $pdo->query(
    "SELECT id, nom_site
     FROM sites
     WHERE nom_site IS NOT NULL AND nom_site <> ''
     ORDER BY nom_site ASC"
);

$rows = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'sites'   => $rows,
]);
