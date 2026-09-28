<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

autoCloseOverdueRequests($pdo);

$siteActuel = getSiteActuel($pdo);

if (!siteEstConfigure($siteActuel)) {
    echo json_encode(['success' => true, 'demandes' => []]);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, nom_utilisateur, numero_agent, service, motif, date_creation
     FROM demandes
     WHERE statut = 'en_attente' AND site_id = :site_id
     ORDER BY date_creation ASC"
);
$stmt->execute([':site_id' => $siteActuel['id']]);

$rows = $stmt->fetchAll();

$labelsMotif = [
    'materiel' => 'Problème matériel',
    'logiciel' => 'Problème logiciel',
    'autre'    => 'Autre',
];

foreach ($rows as &$row) {
    $row['heure']       = date('H:i', strtotime($row['date_creation']));
    $row['motif_label'] = $labelsMotif[$row['motif']] ?? $row['motif'];
}
unset($row);

echo json_encode([
    'success'  => true,
    'demandes' => $rows,
]);
