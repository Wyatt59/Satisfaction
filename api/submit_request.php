<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$siteActuel = getSiteActuel($pdo);
if (!siteEstConfigure($siteActuel)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => "Merci d'enregistrer le site dans la base de données avec phpMyAdmin.",
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$nom     = trim((string)($data['nom_utilisateur'] ?? ''));
$agent   = trim((string)($data['numero_agent'] ?? ''));
$service = trim((string)($data['service'] ?? ''));
$motif   = trim((string)($data['motif'] ?? ''));
$detail  = trim((string)($data['detail'] ?? ''));

if ($detail === '') {
    $detail = null;
} elseif (mb_strlen($detail) > 1000) {
    $detail = mb_substr($detail, 0, 1000);
}

$motifsValides = ['materiel', 'logiciel', 'autre'];

if ($nom === '' || $agent === '' || $service === '' || !in_array($motif, $motifsValides, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Veuillez remplir correctement tous les champs.']);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO demandes (nom_utilisateur, numero_agent, service, motif, detail_demande, date_creation, statut, site_id)
     VALUES (:nom, :agent, :service, :motif, :detail, NOW(), "en_attente", :site_id)'
);

$stmt->execute([
    ':nom'     => $nom,
    ':agent'   => $agent,
    ':service' => $service,
    ':motif'   => $motif,
    ':detail'  => $detail,
    ':site_id' => $siteActuel['id'],
]);

echo json_encode([
    'success' => true,
    'id'      => (int)$pdo->lastInsertId(),
]);
