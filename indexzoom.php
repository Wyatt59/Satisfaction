<?php
declare(strict_types=1);

/**
 * Variante de index.php pour les demandes d'intervention passant par le canal
 * Zoom Room (au domicile ou au bureau de l'agent) : pas de liste des demandes
 * en attente, la demande est rattachée au site "ZoomRoom", un e-mail invitant
 * à répondre via reponsesatisfaction.php est envoyé au demandeur, puis la page
 * redirige vers ad_config.lienzoomroom.
 */

require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Demande Zoom - Support informatique</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="alternate icon" href="assets/img/favicon.ico">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/rgpd.css">
</head>
<body data-mode="zoom">

<a href="stats.php" id="stats-link" class="stats-link hidden" title="Statistiques">📊</a>
<a href="confidentialite.php" class="rgpd-link" title="Confidentialité des données (RGPD)">🔒 Confidentialité</a>

<!-- Pas de clavier virtuel : saisie au clavier physique du poste -->
<div class="kiosk-wrapper zoom-wrapper">

    <!-- ============ NOUVELLE DEMANDE (pas de liste des demandes en attente) ============ -->
    <div class="panel form-panel">
        <div class="panel-header header-blue">
            <span class="header-icon">🎥</span> Nouvelle demande Zoom
        </div>
        <div class="panel-body">

            <div class="field" id="field-nom">
                <label for="nom_utilisateur">Nom de l'utilisateur</label>
                <input type="text" id="nom_utilisateur" class="touch-input" autocomplete="off">
                <div id="nom-ad-status" class="ad-status"></div>
            </div>

            <div class="field" id="field-agent">
                <label for="numero_agent">Numéro d'agent (5 chiffres)</label>
                <input type="text" id="numero_agent" class="touch-input" autocomplete="off" maxlength="5" inputmode="numeric">
            </div>

            <div class="field">
                <label for="service">Service <span class="hint">(Saisissez "Autre" si il ne s'affiche pas)</span></label>
                <div class="combo-wrapper">
                    <input type="text" id="service" class="touch-input" autocomplete="off"
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
                        <textarea id="detail_demande" class="touch-input touch-textarea"
                                  data-label="Détail de la demande" placeholder="Précisez si besoin..."></textarea>
                    </div>
                </div>
            </div>

            <div class="info-box">
                Après validation, vous recevrez un e-mail contenant un lien pour nous donner
                votre avis sur la qualité de notre intervention.
            </div>

            <button id="btn-valider" class="btn btn-green">
                💾 VALIDER
            </button>

            <div id="form-message" class="form-message"></div>
        </div>
    </div>

</div>

<!-- Attente pendant la recherche de l'agent dans l'annuaire (AD) -->
<div id="ad-wait-overlay" class="overlay hidden" role="alertdialog" aria-live="assertive">
    <div class="overlay-card">
        <div class="ad-wait-spinner"></div>
        <div class="overlay-text">Recherche de vos informations dans l'annuaire…</div>
        <div class="ad-wait-hint">Merci de patienter.</div>
    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
