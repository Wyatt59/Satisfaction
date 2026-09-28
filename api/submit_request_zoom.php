<?php
declare(strict_types=1);

/**
 * Enregistre une demande saisie depuis indexzoom.php (canal Zoom Room) :
 *  - la demande est rattachée au site "ZoomRoom" (canal = 'zoomroom'),
 *  - un e-mail est envoyé au demandeur (adresse récupérée dans l'AD) avec un
 *    lien unique vers reponsesatisfaction.php,
 *  - la réponse contient l'URL de redirection (ad_config.lienzoomroom).
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ldap_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
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

if ($nom === '' || !preg_match('/^[0-9]{5}$/', $agent) || $service === '' || !in_array($motif, $motifsValides, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Veuillez remplir correctement tous les champs.']);
    exit;
}

$site  = getSiteZoomRoom($pdo);
$email = lookupAgentEmailInActiveDirectory($pdo, $agent);
$token = bin2hex(random_bytes(32));

$stmt = $pdo->prepare(
    'INSERT INTO demandes (nom_utilisateur, numero_agent, service, motif, detail_demande, date_creation, statut,
                           site_id, canal, token_satisfaction, email_demandeur)
     VALUES (:nom, :agent, :service, :motif, :detail, NOW(), "en_attente",
             :site_id, "zoomroom", :token, :email)'
);

$stmt->execute([
    ':nom'     => $nom,
    ':agent'   => $agent,
    ':service' => $service,
    ':motif'   => $motif,
    ':detail'  => $detail,
    ':site_id' => $site['id'],
    ':token'   => $token,
    ':email'   => $email,
]);

$id = (int)$pdo->lastInsertId();

$mailEnvoye = false;
if ($email !== null) {
    $lienReponse = getAppBaseUrl() . '/reponsesatisfaction.php?token=' . $token;
    $mailEnvoye  = envoyerMailSatisfactionZoom($email, $nom, $lienReponse);
    if (!$mailEnvoye) {
        error_log("Zoom Room : échec de l'envoi de l'e-mail de satisfaction (demande #{$id}).");
    }
} else {
    error_log("Zoom Room : adresse e-mail introuvable dans l'AD pour l'agent {$agent} (demande #{$id}).");
}

echo json_encode([
    'success'     => true,
    'id'          => $id,
    'mail_envoye' => $mailEnvoye,
    'redirect'    => getLienZoomRoom($pdo),
]);
