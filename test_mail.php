<?php
declare(strict_types=1);

/**
 * Page de diagnostic du canal Zoom Room (indexzoom.php) : vérifie tous les
 * paramètres nécessaires à l'enregistrement de la demande et à l'envoi de
 * l'e-mail de satisfaction, et permet d'envoyer un e-mail de test.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/mail_diagnostics.php';

$sections = runMailDiagnostics($pdo);

// --- Recherche de l'adresse e-mail d'un agent dans l'AD ---
$numeroAgentTest = trim((string)($_REQUEST['numero_agent'] ?? ''));
$agentInvalide = $numeroAgentTest !== '' && !preg_match('/^[0-9]{5}$/', $numeroAgentTest);
$adDisponible = null;
$emailAgent = null;
if ($numeroAgentTest !== '' && !$agentInvalide) {
    $adDisponible = connectAdService($pdo) !== null;
    $emailAgent = $adDisponible ? lookupAgentEmailInActiveDirectory($pdo, $numeroAgentTest) : null;
}

// --- Envoi d'un e-mail de test ---
$destinataire = trim((string)($_POST['destinataire'] ?? ($emailAgent ?? '')));
$envoi = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['envoyer'])) {
    if (!filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
        $envoi = ['ok' => false, 'detail' => 'Adresse e-mail du destinataire invalide.'];
    } else {
        $baseUrl = defined('APP_BASE_URL') && APP_BASE_URL !== '' ? rtrim(APP_BASE_URL, '/') : getAppBaseUrlDepuisPage();
        $lienTest = $baseUrl . '/reponsesatisfaction.php?token=' . str_repeat('0', 64);

        error_clear_last();
        try {
            $ok = envoyerMailSatisfactionZoom($destinataire, 'Test', $lienTest);
            $erreur = error_get_last();
            $envoi = [
                'ok'     => $ok,
                'detail' => $ok
                    ? 'mail() a accepté le message. Vérifiez sa réception (et le dossier courrier indésirable). Le lien du message de test mène à une page « lien non valide », c\'est normal.'
                    : 'mail() a refusé le message' . ($erreur ? ' : ' . $erreur['message'] : '.'),
            ];
        } catch (Throwable $e) {
            $envoi = ['ok' => false, 'detail' => 'Erreur PHP : ' . $e->getMessage()];
        }
    }
}

function niveauIcone(string $level): string
{
    $icones = ['ok' => '✓', 'warn' => '!', 'ko' => '✕'];
    return '<span class="step-icon ' . $level . '">' . $icones[$level] . '</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test du canal Zoom Room (e-mail)</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="alternate icon" href="assets/img/favicon.ico">
<link rel="stylesheet" href="assets/css/stats.css">
<link rel="stylesheet" href="assets/css/ad_test.css">
</head>
<body>

<div class="stats-wrapper">

    <header class="stats-header">
        <h1>✉️ Test du canal Zoom Room (e-mail)</h1>
        <a href="indexzoom.php" class="btn-back">← Retour à indexzoom.php</a>
    </header>

    <div class="ad-warning">
        ⚠️ Page de diagnostic réservée aux administrateurs. Elle affiche la configuration d'envoi
        d'e-mail et permet d'envoyer un e-mail de test. Pensez à la protéger (authentification,
        restriction réseau) ou à la supprimer une fois la mise en service validée.
    </div>

    <?php foreach ($sections as $section): ?>
        <div class="table-card ad-steps-card">
            <h2><?= htmlspecialchars($section['titre']) ?></h2>
            <ul class="step-list">
                <?php foreach ($section['steps'] as $step): ?>
                    <li class="step-<?= $step['level'] ?>">
                        <?= niveauIcone($step['level']) ?>
                        <div>
                            <div class="step-label"><?= htmlspecialchars($step['label']) ?></div>
                            <?php if ($step['detail'] !== ''): ?>
                                <div class="step-detail"><?= htmlspecialchars($step['detail']) ?></div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>

    <div class="table-card ad-search-card">
        <h2>Adresse e-mail d'un agent dans l'AD (attribut <code>mail</code>)</h2>

        <form method="get" class="ad-search-form">
            <input type="text" name="numero_agent" value="<?= htmlspecialchars($numeroAgentTest) ?>"
                   placeholder="Ex. 12345" maxlength="5" pattern="[0-9]{5}" inputmode="numeric">
            <button type="submit">Rechercher</button>
        </form>

        <?php if ($agentInvalide): ?>
            <p class="ad-error">Le numéro d'agent doit comporter exactement 5 chiffres.</p>
        <?php elseif ($adDisponible === false): ?>
            <p class="ad-error">✕ Connexion à l'AD impossible : aucun e-mail ne peut être envoyé.
                Voir le détail sur <a href="ad_test.php">ad_test.php</a>.</p>
        <?php elseif ($adDisponible === true && $emailAgent === null): ?>
            <p class="ad-error">✕ Aucune adresse e-mail trouvée pour l'agent <?= htmlspecialchars($numeroAgentTest) ?>
                (agent introuvable ou ambigu, ou attribut <code>mail</code> vide).
                La demande serait enregistrée, mais sans envoi d'e-mail.</p>
        <?php elseif ($emailAgent !== null): ?>
            <p class="ad-success">✓ Adresse trouvée : <?= htmlspecialchars($emailAgent) ?></p>
        <?php endif; ?>
    </div>

    <div class="table-card ad-search-card">
        <h2>Envoyer un e-mail de test</h2>
        <p class="ad-filter">Envoie le même e-mail que celui reçu par un demandeur Zoom Room.</p>

        <form method="post" class="ad-search-form">
            <?php if ($numeroAgentTest !== '' && !$agentInvalide): ?>
                <input type="hidden" name="numero_agent" value="<?= htmlspecialchars($numeroAgentTest) ?>">
            <?php endif; ?>
            <input type="email" name="destinataire" value="<?= htmlspecialchars($destinataire) ?>"
                   placeholder="prenom.nom@exemple.fr" required>
            <button type="submit" name="envoyer" value="1">Envoyer</button>
        </form>

        <?php if ($envoi !== null): ?>
            <p class="<?= $envoi['ok'] ? 'ad-success' : 'ad-error' ?>">
                <?= $envoi['ok'] ? '✓' : '✕' ?> <?= htmlspecialchars($envoi['detail']) ?>
            </p>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
