<?php
declare(strict_types=1);

/**
 * Page ouverte depuis le lien reçu par e-mail (canal Zoom Room) : le
 * demandeur touche l'un des 3 visages, comme sur la borne (index.php), pour
 * donner son avis et clôturer sa demande. La demande est identifiée par le
 * jeton unique contenu dans le lien (?token=...).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Applique la clôture automatique (délai de réponse dépassé) avant l'affichage.
autoCloseOverdueRequests($pdo);

$token   = (string)($_GET['token'] ?? '');
$demande = null;

if (preg_match('/^[0-9a-f]{64}$/', $token)) {
    $stmt = $pdo->prepare(
        "SELECT nom_utilisateur, motif, date_creation, statut, satisfaction
         FROM demandes
         WHERE token_satisfaction = :token AND canal = 'zoomroom'"
    );
    $stmt->execute([':token' => $token]);
    $demande = $stmt->fetch() ?: null;
}

$labelsMotif = [
    'materiel' => 'Problème matériel',
    'logiciel' => 'Problème logiciel',
    'autre'    => 'Autre',
];

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Votre avis - Support informatique</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="alternate icon" href="assets/img/favicon.ico">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="reponse-page">

<div class="panel reponse-panel">
    <div class="panel-header header-blue">
        <span class="header-icon">🙂</span> Votre avis sur notre intervention
    </div>
    <div class="panel-body reponse-body" id="reponse-body">

    <?php if ($demande === null): ?>

        <div class="reponse-message">
            <div class="reponse-message-icon">⚠️</div>
            <p>Ce lien n'est pas valide.</p>
            <p class="hint">Vérifiez que vous avez bien copié l'adresse complète figurant dans l'e-mail.</p>
        </div>

    <?php elseif ($demande['statut'] !== 'en_attente'): ?>

        <div class="reponse-message">
            <div class="reponse-message-icon">✅</div>
            <?php if ($demande['satisfaction'] !== null): ?>
                <p>Vous avez déjà donné votre avis sur cette demande. Merci !</p>
            <?php else: ?>
                <p>Cette demande est clôturée : le délai pour donner votre avis est dépassé.</p>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div id="reponse-question" data-token="<?= h($token) ?>">
            <p class="reponse-intro">
                Bonjour <strong><?= h($demande['nom_utilisateur']) ?></strong>,<br>
                vous nous avez sollicités par le canal Zoom le
                <?= h(date('d/m/Y', strtotime($demande['date_creation']))) ?> à
                <?= h(date('H:i', strtotime($demande['date_creation']))) ?>
                (<?= h($labelsMotif[$demande['motif']] ?? $demande['motif']) ?>).
            </p>
            <p class="reponse-question">Êtes-vous satisfait(e) de notre intervention ?</p>
            <div class="reponse-smileys">
                <button type="button" class="smiley-btn" data-type="satisfait" title="Satisfait"></button>
                <button type="button" class="smiley-btn" data-type="neutre" title="Neutre"></button>
                <button type="button" class="smiley-btn" data-type="insatisfait" title="Insatisfait"></button>
            </div>
            <div id="reponse-erreur" class="form-message"></div>
        </div>

        <div id="reponse-merci" class="reponse-message hidden">
            <div class="overlay-icon" id="reponse-merci-icon"></div>
            <p>Merci pour votre retour !</p>
        </div>

    <?php endif; ?>

    </div>
</div>

<?php if ($demande !== null && $demande['statut'] === 'en_attente'): ?>
<!-- Motif d'insatisfaction (affiché pour neutre / insatisfait) -->
<div id="comment-overlay" class="comment-overlay hidden">
    <div class="comment-card">
        <div class="comment-header">
            <span id="comment-icon" class="comment-icon"></span>
            <div class="comment-title">Pouvez-vous préciser le motif ? <span class="hint">(facultatif)</span></div>
        </div>
        <input type="text" id="comment_input" class="touch-input" autocomplete="off" maxlength="500"
               placeholder="Ex. Délai d'intervention trop long...">
        <div class="comment-actions">
            <button type="button" id="btn-comment-skip" class="btn btn-secondary">Passer</button>
            <button type="button" id="btn-comment-submit" class="btn btn-green">Valider</button>
        </div>
        <button type="button" id="btn-comment-cancel" class="comment-cancel">Annuler</button>
    </div>
</div>

<script src="assets/js/reponse.js"></script>
<?php endif; ?>
</body>
</html>
