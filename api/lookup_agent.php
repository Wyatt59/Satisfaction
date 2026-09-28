<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ldap_auth.php';

$numeroAgent = trim((string)($_GET['numero_agent'] ?? ''));

if (!preg_match('/^[0-9]{5}$/', $numeroAgent)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Numéro d\'agent invalide.']);
    exit;
}

$result = lookupAgentInActiveDirectory($pdo, $numeroAgent);

echo json_encode([
    'success'        => true,
    'available'      => $result['available'], // false = AD indisponible -> repli sur la saisie manuelle
    'found'          => $result['found'],
    'nom'            => $result['nom'],
    'afficher_stats' => $result['afficher_stats'], // false par défaut : repli fermé si non déterminable
    'service'        => $result['service'], // null = laisser le champ Service inchangé (comportement manuel actuel)
]);
