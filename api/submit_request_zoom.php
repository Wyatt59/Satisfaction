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

// Toute erreur est renvoyée en JSON (et non en page d'erreur PHP) pour que
// indexzoom.php affiche la cause réelle ; le détail est vérifiable sur test_mail.php.
try {
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
} catch (Throwable $e) {
    error_log('Zoom Room : échec de l\'enregistrement de la demande : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors de l\'enregistrement : ' . $e->getMessage() . ' (voir test_mail.php)',
    ]);
    exit;
}

$id = (int)$pdo->lastInsertId();

// La demande est enregistrée : un problème d'envoi d'e-mail ou de lecture du
// lien de redirection ne doit pas faire échouer la validation.
$mailEnvoye = false;
if ($email !== null) {
    try {
        $lienReponse = getAppBaseUrl() . '/reponsesatisfaction.php?token=' . $token;
        $mailEnvoye  = envoyerMailSatisfactionZoom($email, $nom, $lienReponse);
    } catch (Throwable $e) {
        error_log("Zoom Room : erreur lors de l'envoi de l'e-mail (demande #{$id}) : " . $e->getMessage());
    }
    if (!$mailEnvoye) {
        error_log("Zoom Room : échec de l'envoi de l'e-mail de satisfaction (demande #{$id}).");
    }
} else {
    error_log("Zoom Room : adresse e-mail introuvable dans l'AD pour l'agent {$agent} (demande #{$id}).");
}

try {
    $redirect = getLienZoomRoom($pdo);
} catch (Throwable $e) {
    $redirect = null;
}

echo json_encode([
    'success'     => true,
    'id'          => $id,
    'mail_envoye' => $mailEnvoye,
    'redirect'    => $redirect,
]);
