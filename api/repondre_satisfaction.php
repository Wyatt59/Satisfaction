<?php
declare(strict_types=1);

/**
 * Enregistre la satisfaction donnée depuis reponsesatisfaction.php (lien reçu
 * par e-mail, canal Zoom Room). La demande est identifiée par son jeton
 * unique, jamais par son id, pour qu'un lien ne permette de répondre qu'à sa
 * propre demande.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$token        = (string)($data['token'] ?? '');
$satisfaction = (string)($data['satisfaction'] ?? '');
$commentaire  = isset($data['commentaire']) ? trim((string)$data['commentaire']) : '';

if ($commentaire === '') {
    $commentaire = null;
} elseif (mb_strlen($commentaire) > 500) {
    $commentaire = mb_substr($commentaire, 0, 500);
}

$satisfactionsValides = ['satisfait', 'neutre', 'insatisfait'];

if (!preg_match('/^[0-9a-f]{64}$/', $token) || !in_array($satisfaction, $satisfactionsValides, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

$stmt = $pdo->prepare(
    "UPDATE demandes
     SET statut = 'cloture', satisfaction = :satisfaction, commentaire = :commentaire,
         date_cloture = NOW(), type_cloture = 'manuelle'
     WHERE token_satisfaction = :token AND canal = 'zoomroom' AND statut = 'en_attente'"
);

$stmt->execute([
    ':satisfaction' => $satisfaction,
    ':commentaire'  => $commentaire,
    ':token'        => $token,
]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Demande introuvable ou déjà clôturée.']);
    exit;
}

echo json_encode(['success' => true]);
