<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Garantit que les demandes du jour dépassant 17h sont bien à jour avant le calcul des stats.
autoCloseOverdueRequests($pdo);

$year   = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$month  = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$siteId = isset($_GET['site_id']) ? (string)$_GET['site_id'] : 'all';

if ($year <= 0 || $month <= 0 || $month > 12) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Période invalide.']);
    exit;
}

$siteFilterSql = '';
$params = [':year' => $year, ':month' => $month];

if ($siteId !== 'all' && $siteId !== '' && ctype_digit($siteId)) {
    $siteFilterSql = ' AND site_id = :site_id';
    $params[':site_id'] = (int)$siteId;
}

$stmt = $pdo->prepare(
    "SELECT id, nom_utilisateur, numero_agent, service, motif, detail_demande,
            date_creation, date_cloture, statut, type_cloture, satisfaction, commentaire
     FROM demandes
     WHERE YEAR(date_creation) = :year AND MONTH(date_creation) = :month" . $siteFilterSql . "
     ORDER BY date_creation ASC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$labelsMotif = [
    'materiel' => 'Problème matériel',
    'logiciel' => 'Problème logiciel',
    'autre'    => 'Autre',
];

$counts = ['satisfait' => 0, 'neutre' => 0, 'insatisfait' => 0, 'non_cloturee' => 0];

foreach ($rows as &$row) {
    $row['motif_label'] = $labelsMotif[$row['motif']] ?? $row['motif'];

    if ($row['satisfaction'] === null) {
        $counts['non_cloturee']++;
    } else {
        $counts[$row['satisfaction']]++;
    }

    if ($row['statut'] === 'en_attente') {
        $row['type_cloture_label'] = 'En attente';
    } elseif ($row['type_cloture'] === 'automatique') {
        $row['type_cloture_label'] = 'Automatique (18h)';
    } else {
        $row['type_cloture_label'] = 'Manuelle';
    }
}
unset($row);

$total = count($rows);
$percentages = [];
foreach ($counts as $key => $value) {
    $percentages[$key] = $total > 0 ? round($value / $total * 100, 1) : 0.0;
}

echo json_encode([
    'success'     => true,
    'total'       => $total,
    'counts'      => $counts,
    'percentages' => $percentages,
    'demandes'    => $rows,
]);
