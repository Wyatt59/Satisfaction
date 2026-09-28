<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$siteActuel  = getSiteActuel($pdo);
$siteConfigure = siteEstConfigure($siteActuel);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Borne de support informatique</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="alternate icon" href="assets/img/favicon.ico">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/rgpd.css">
</head>
<body>

<?php if (!$siteConfigure): ?>

    <div style="max-width:640px;margin:80px auto;padding:32px;background:#fff;border-radius:14px;box-shadow:0 4px 14px rgba(0,0,0,0.08);font-family:'Segoe UI',Arial,sans-serif;text-align:center;">
        <div style="font-size:40px;margin-bottom:12px;">⚠️</div>
        <h1 style="font-size:20px;color:#1f2937;margin:0 0 14px;">Site non configuré</h1>
        <p style="font-size:15px;line-height:1.6;color:#26313f;margin:0 0 10px;">
            Merci d'enregistrer le site dans la base de données avec phpMyAdmin.
        </p>
        <p style="font-size:14px;line-height:1.6;color:#6b7684;margin:0;">
            Rendez-vous sur la page « Paramètres » pour enregistrer automatiquement
            le préfixe IP de cette borne, puis complétez le nom du site dans la
            table <code>sites</code> via phpMyAdmin.
        </p>
        <a href="parametres.php" style="display:inline-block;margin-top:22px;padding:10px 20px;background:#1e6fea;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
            Aller sur la page Paramètres
        </a>
    </div>

<?php else: ?>

<a href="stats.php" id="stats-link" class="stats-link hidden" title="Statistiques">📊</a>
<a href="confidentialite.php" class="rgpd-link" title="Confidentialité des données (RGPD)">🔒 Confidentialité</a>

<div class="kiosk-wrapper">


    <!-- ============ PANNEAU GAUCHE : NOUVELLE DEMANDE ============ -->
    <div class="panel form-panel">
        <div class="panel-header header-blue">
            <span class="header-icon">🖥️</span> Nouvelle demande
        </div>
        <div class="panel-body">

            <div class="field" id="field-nom">
                <label for="nom_utilisateur">Nom de l'utilisateur</label>
                <input type="text" id="nom_utilisateur" class="touch-input" autocomplete="off" readonly>
                <div id="nom-ad-status" class="ad-status"></div>
            </div>

            <div class="field" id="field-agent">
                <label for="numero_agent">Numéro d'agent (5 chiffres)</label>
                <input type="text" id="numero_agent" class="touch-input" autocomplete="off" readonly maxlength="5" inputmode="numeric">
            </div>

            <div class="field">
                <label for="service">Service <span class="hint">(Saisissez "Autre" si il ne s'affiche pas)</span></label>
                <div class="combo-wrapper">
                    <input type="text" id="service" class="touch-input" autocomplete="off" readonly
                           placeholder="Rechercher un service...">
                    <div id="service-suggestions" class="suggestions hidden"></div>
                </div>
                <div id="service-ad-status" class="ad-status"></div>
            </div>

            <div class="field">
                <label>Motif de la demande</label>
                <div class="motif-row">
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="motif" value="materiel" checked>
                            <span class="radio-custom"></span> Problème matériel
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="motif" value="logiciel">
                            <span class="radio-custom"></span> Problème logiciel
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="motif" value="autre">
                            <span class="radio-custom"></span> Autre
                        </label>
                    </div>
                    <div class="motif-detail">
                        <label for="detail_demande" class="detail-label">Détail <span class="hint">(facultatif)</span></label>
                        <textarea id="detail_demande" class="touch-input touch-textarea" readonly
                                  data-label="Détail de la demande" placeholder="Précisez si besoin..."></textarea>
                    </div>
                </div>
            </div>

            <div class="info-box">
                Après validation, revenez sur la borne, retrouvez votre nom dans la liste à droite
                et touchez le visage correspondant à votre satisfaction pour clôturer votre demande.
            </div>

            <button id="btn-valider" class="btn btn-green">
                💾 VALIDER
            </button>

            <div id="form-message" class="form-message"></div>
        </div>
    </div>

    <!-- ============ PANNEAU DROIT : DEMANDES EN ATTENTE ============ -->
    <div class="panel list-panel">
        <div class="panel-header header-dark">
            <span class="header-icon">🙂</span> Demandes en attente
        </div>
        <div class="panel-body list-body" id="pending-list">
            <div class="empty-state">Aucune demande en attente</div>
        </div>
    </div>

</div>

<!-- ============ CLAVIER VIRTUEL ============ -->
<div id="virtual-keyboard" class="keyboard hidden">
    <div class="keyboard-target-label">Saisie : <span id="kb-target-label">-</span></div>
    <div class="kb-row" id="kb-row-numbers"></div>
    <div class="kb-row" id="kb-row-1"></div>
    <div class="kb-row" id="kb-row-2"></div>
    <div class="kb-row" id="kb-row-3"></div>
    <div class="kb-row kb-row-actions" id="kb-row-actions"></div>
</div>

<!-- Confirmation de clôture -->
<div id="close-overlay" class="overlay hidden">
    <div class="overlay-card">
        <div class="overlay-icon" id="close-overlay-icon"></div>
        <div class="overlay-text">Merci pour votre retour !</div>
    </div>
</div>

<!-- Motif d'insatisfaction (affiché pour neutre / insatisfait) -->
<div id="comment-overlay" class="comment-overlay hidden">
    <div class="comment-card">
        <div class="comment-header">
            <span id="comment-icon" class="comment-icon"></span>
            <div class="comment-title">Pouvez-vous préciser le motif ? <span class="hint">(facultatif)</span></div>
        </div>
        <input type="text" id="comment_input" class="touch-input" autocomplete="off" readonly
               placeholder="Ex. Temps d'attente trop long..." data-label="Motif">
        <div class="comment-actions">
            <button type="button" id="btn-comment-skip" class="btn btn-secondary">Passer</button>
            <button type="button" id="btn-comment-submit" class="btn btn-green">Valider</button>
        </div>
        <button type="button" id="btn-comment-cancel" class="comment-cancel">Annuler la clôture</button>
    </div>
</div>

<script src="assets/js/app.js"></script>
<?php endif; ?>
</body>
</html>
