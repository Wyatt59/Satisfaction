<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Enregistre automatiquement le préfixe IP de la borne courante s'il est
// encore inconnu (nom_site restera NULL tant qu'un administrateur ne l'aura
// pas renseigné via phpMyAdmin).
$ipActuelle    = getClientIp();
$siteCourant   = enregistrerSiteSiInconnu($pdo);
$estConfigure  = siteEstConfigure($siteCourant);

// Liste de tous les sites déjà connus, pour référence de l'administrateur.
$stmt = $pdo->query('SELECT id, ip_prefix, nom_site, date_creation FROM sites ORDER BY ip_prefix ASC');
$sites = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Paramètres - Sites</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="alternate icon" href="assets/img/favicon.ico">
<link rel="stylesheet" href="assets/css/stats.css">
<link rel="stylesheet" href="assets/css/ad_test.css">
</head>
<body>

<div class="stats-wrapper">

    <header class="stats-header">
        <h1>⚙️ Paramètres — Sites</h1>
        <a href="index.php" class="btn-back">← Retour à la borne</a>
    </header>

    <div class="ad-warning">
        ⚠️ Page réservée aux administrateurs. Elle sert à identifier automatiquement
        chaque borne (via les deux premiers octets de son adresse IP) et à lister les
        sites déjà connus. Le nom d'un site doit ensuite être renseigné manuellement
        dans phpMyAdmin (table <code>sites</code>, colonne <code>nom_site</code>).
        Pensez à protéger ou retirer cette page une fois la mise en service validée.
    </div>

    <div class="table-card">
        <h2>Cette borne</h2>
        <table class="ad-config-table">
            <tr><th>Adresse IP détectée</th><td><?= htmlspecialchars($ipActuelle ?: '(inconnue)') ?></td></tr>
            <?php if ($siteCourant === null): ?>
                <tr><th>Préfixe de site</th><td class="muted">Non déterminable (adresse IP non IPv4)</td></tr>
            <?php else: ?>
                <tr><th>Préfixe de site</th><td><code><?= htmlspecialchars($siteCourant['ip_prefix']) ?></code></td></tr>
                <tr>
                    <th>Nom du site</th>
                    <td>
                        <?php if ($estConfigure): ?>
                            <strong><?= htmlspecialchars((string)$siteCourant['nom_site']) ?></strong>
                        <?php else: ?>
                            <span class="ad-error">Non configuré</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>

        <?php if ($siteCourant === null): ?>
            <p class="ad-error" style="margin-top:14px;">
                L'adresse IP de cette borne n'a pas pu être interprétée comme une IPv4 valide.
                Le multi-site ne peut pas fonctionner pour cette borne en l'état.
            </p>
        <?php elseif (!$estConfigure): ?>
            <p class="ad-error" style="margin-top:14px;">
                Le préfixe <code><?= htmlspecialchars($siteCourant['ip_prefix']) ?></code>
                (id <?= (int)$siteCourant['id'] ?>) vient d'être enregistré automatiquement.
                Merci d'enregistrer le nom du site dans la base de données avec phpMyAdmin
                (table <code>sites</code>, ligne id <?= (int)$siteCourant['id'] ?>, colonne
                <code>nom_site</code>). La borne restera bloquée sur l'écran de la demande
                tant que ce nom n'est pas renseigné.
            </p>
        <?php else: ?>
            <p class="ad-success" style="margin-top:14px;">
                ✓ Cette borne est correctement rattachée au site
                « <?= htmlspecialchars((string)$siteCourant['nom_site']) ?> ».
            </p>
        <?php endif; ?>
    </div>

    <div class="table-card">
        <h2>Sites déjà connus</h2>
        <?php if (empty($sites)): ?>
            <p class="muted">Aucun site enregistré pour le moment.</p>
        <?php else: ?>
            <table class="ad-config-table ad-results-table">
                <thead>
                    <tr>
                        <th style="width:auto;">Id</th>
                        <th style="width:auto;">Préfixe IP</th>
                        <th style="width:auto;">Nom du site</th>
                        <th style="width:auto;">Enregistré le</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $s): ?>
                        <tr class="<?= ($siteCourant && (int)$s['id'] === (int)$siteCourant['id']) ? 'row-selected' : '' ?>">
                            <td><?= (int)$s['id'] ?></td>
                            <td><code><?= htmlspecialchars($s['ip_prefix']) ?></code></td>
                            <td>
                                <?php if ($s['nom_site'] !== null && trim((string)$s['nom_site']) !== ''): ?>
                                    <?= htmlspecialchars((string)$s['nom_site']) ?>
                                <?php else: ?>
                                    <span class="ad-error">Non configuré</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string)$s['date_creation']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
