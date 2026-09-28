<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/ldap_diagnostics.php';

$numeroAgentTest = trim((string)($_GET['numero_agent'] ?? ''));
$agentInvalide = $numeroAgentTest !== '' && !preg_match('/^[0-9]{5}$/', $numeroAgentTest);

$diagnostic = runAdDiagnostics($pdo, $agentInvalide ? null : ($numeroAgentTest !== '' ? $numeroAgentTest : null));

$steps = $diagnostic['steps'];
$cfg = $diagnostic['config'];
$result = $diagnostic['result'];

function badgeIcon(bool $ok): string
{
    return $ok
        ? '<span class="step-icon ok">✓</span>'
        : '<span class="step-icon ko">✕</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test de connexion Active Directory</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="alternate icon" href="assets/img/favicon.ico">
<link rel="stylesheet" href="assets/css/stats.css">
<link rel="stylesheet" href="assets/css/ad_test.css">
</head>
<body>

<div class="stats-wrapper">

    <header class="stats-header">
        <h1>🧪 Test de connexion Active Directory</h1>
        <a href="index.php" class="btn-back">← Retour à la borne</a>
    </header>

    <div class="ad-warning">
        ⚠️ Page de diagnostic réservée aux administrateurs. Elle affiche la configuration AD
        (hors mot de passe) et permet de tester la recherche d'un agent. Pensez à la protéger
        (authentification, restriction réseau) ou à la supprimer une fois la mise en service
        validée.
    </div>

    <div class="table-card ad-config-card">
        <h2>Configuration actuelle (table <code>ad_config</code>)</h2>
        <?php if (!$cfg): ?>
            <p class="muted">Aucune configuration trouvée.</p>
        <?php else: ?>
            <table class="ad-config-table">
                <tr><th>Actif</th><td><?= (int)$cfg['actif'] === 1 ? 'Oui' : 'Non' ?></td></tr>
                <tr><th>Hôte</th><td><?= htmlspecialchars($cfg['host'] ?: '(vide)') ?></td></tr>
                <tr><th>Port</th><td><?= htmlspecialchars((string)$cfg['port']) ?></td></tr>
                <tr><th>TLS</th><td><?= (int)$cfg['use_tls'] === 1 ? 'Oui' : 'Non' ?></td></tr>
                <tr><th>Base DN</th><td><?= htmlspecialchars($cfg['base_dn'] ?: '(vide)') ?></td></tr>
                <tr><th>Bind DN (compte de service)</th><td><?= htmlspecialchars($cfg['bind_dn'] ?: '(vide)') ?></td></tr>
                <tr><th>Attribut numéro d'agent</th><td><?= htmlspecialchars($cfg['agent_attribute']) ?></td></tr>
                <tr><th>Attribut nom complet</th><td><?= htmlspecialchars($cfg['name_attribute']) ?></td></tr>
                <tr><th>Attribut service</th><td><?= htmlspecialchars($cfg['service_attribute']) ?></td></tr>
                <tr>
                    <th>Groupe autorisé aux statistiques (<code>affichage_stat</code>)</th>
                    <td>
                        <?php if (trim((string)($cfg['affichage_stat'] ?? '')) === ''): ?>
                            <span class="ad-error">(non configuré — icône Statistiques masquée pour tout le monde)</span>
                        <?php else: ?>
                            <?= htmlspecialchars($cfg['affichage_stat']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th>Mot de passe</th><td class="muted">non affiché — comparé par empreinte MD5 uniquement</td></tr>
            </table>
        <?php endif; ?>
    </div>

    <div class="table-card ad-steps-card">
        <h2>Étapes de connexion</h2>
        <ul class="step-list">
            <?php foreach ($steps as $step): ?>
                <li class="<?= $step['ok'] ? 'step-ok' : 'step-ko' ?>">
                    <?= badgeIcon($step['ok']) ?>
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

    <div class="table-card ad-search-card">
        <h2>Tester la recherche d'un numéro d'agent</h2>

        <?php if ($cfg): ?>
            <p class="ad-filter">Attribut AD utilisé (<code>agent_attribute</code>) :
                <code><?= htmlspecialchars($cfg['agent_attribute']) ?></code></p>
        <?php endif; ?>

        <form method="get" class="ad-search-form">
            <input
                type="text"
                name="numero_agent"
                value="<?= htmlspecialchars($numeroAgentTest) ?>"
                placeholder="Ex. 12345"
                maxlength="5"
                pattern="[0-9]{5}"
                inputmode="numeric"
            >
            <button type="submit">Tester</button>
        </form>

        <?php if ($agentInvalide): ?>
            <p class="ad-error">Le numéro d'agent doit comporter exactement 5 chiffres.</p>
        <?php elseif ($numeroAgentTest !== ''): ?>

            <?php if ($result['filter']): ?>
                <p class="ad-filter">Filtre LDAP utilisé : <code><?= htmlspecialchars($result['filter']) ?></code></p>
            <?php endif; ?>

            <?php if (empty($result['entries'])): ?>
                <p class="muted">Aucun compte trouvé pour ce numéro d'agent (ou une étape précédente a échoué — voir ci-dessus).</p>
            <?php else: ?>
                <table class="ad-config-table ad-results-table">
                    <thead>
                        <tr>
                            <th>DN</th>
                            <th><?= htmlspecialchars($cfg['agent_attribute']) ?></th>
                            <th>Nom complet</th>
                            <th>Service (<?= htmlspecialchars($cfg['service_attribute']) ?>)</th>
                            <th>Icône Statistiques (<code>affichage_stat</code>)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['entries'] as $entry): ?>
                            <tr class="<?= !empty($entry['selected']) ? 'row-selected' : '' ?>">
                                <td><?= htmlspecialchars($entry['dn']) ?></td>
                                <td><?= htmlspecialchars($entry['attr']) ?></td>
                                <td>
                                    <?= htmlspecialchars($entry['nom']) ?>
                                    <?php if (!empty($entry['selected'])): ?>
                                        <span class="selected-badge">retenu</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($entry['service_brut'] === null): ?>
                                        <span class="muted">(attribut absent)</span>
                                    <?php else: ?>
                                        <div class="ad-service-brut">brut : <?= htmlspecialchars($entry['service_brut']) ?></div>
                                        <?php if ($entry['service_extrait'] !== null): ?>
                                            <div>retenu : <strong><?= htmlspecialchars($entry['service_extrait']) ?></strong></div>
                                        <?php else: ?>
                                            <div class="ad-error">extraction vide → champ Service laissé tel quel</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($entry['memberof'])): ?>
                                        <span class="muted">(aucun groupe / memberOf absent)</span>
                                    <?php else: ?>
                                        <div class="ad-service-brut">groupes :
                                            <?= htmlspecialchars(implode(', ', array_map(
                                                function (string $dn): string {
                                                    return extraireCnDepuisDn($dn) ?? $dn;
                                                },
                                                $entry['memberof']
                                            ))) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($entry['est_membre']): ?>
                                        <div class="ad-success">✓ membre → icône visible</div>
                                    <?php else: ?>
                                        <div class="ad-error">✕ non membre → icône masquée</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($result['groupe_diag'] !== null): ?>
                    <?php $gd = $result['groupe_diag']; ?>
                    <details class="ad-groupe-detail">
                        <summary>Détail de la vérification du groupe « <?= htmlspecialchars($cfg['affichage_stat'] ?: '(non configuré)') ?> » (compte retenu)</summary>
                        <table class="ad-config-table">
                            <tr>
                                <th>Membre direct (attribut memberof)</th>
                                <td><?= $gd['membre_direct'] ? '✓ oui' : '✕ non' ?></td>
                            </tr>
                            <tr>
                                <th>Groupe « <?= htmlspecialchars($cfg['affichage_stat'] ?: '') ?> » trouvé sous <code>base_dn</code></th>
                                <td>
                                    <?php if (empty($gd['groupe_trouve_dn'])): ?>
                                        <span class="ad-error">Introuvable ! Le groupe n'existe pas sous ce base_dn — vérifiez l'orthographe exacte du CN dans <code>affichage_stat</code>, ou élargissez <code>base_dn</code> pour qu'il couvre l'OU contenant ce groupe.</span>
                                    <?php elseif (count($gd['groupe_trouve_dn']) > 1): ?>
                                        <span class="ad-error">Ambigu : <?= count($gd['groupe_trouve_dn']) ?> groupes différents portent ce CN sous ce base_dn :</span>
                                        <ul><?php foreach ($gd['groupe_trouve_dn'] as $dn): ?><li><?= htmlspecialchars($dn) ?></li><?php endforeach; ?></ul>
                                    <?php else: ?>
                                        <span class="ad-success">✓ trouvé :</span> <?= htmlspecialchars($gd['groupe_trouve_dn'][0]) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($gd['filtre_recursif'] !== null): ?>
                                <tr>
                                    <th>Filtre récursif testé (groupes imbriqués)</th>
                                    <td><code><?= htmlspecialchars($gd['filtre_recursif']) ?></code></td>
                                </tr>
                                <tr>
                                    <th>Recherche récursive exécutée avec succès</th>
                                    <td>
                                        <?php if ($gd['recherche_recursive_ok']): ?>
                                            ✓ oui
                                        <?php else: ?>
                                            <span class="ad-error">✕ non — le serveur LDAP a rejeté la requête (probablement un annuaire non-Microsoft AD, qui ne supporte pas cette extension)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Membre via groupe imbriqué (résultat de la recherche récursive)</th>
                                    <td><?= $gd['membre_recursif'] ? '✓ oui' : '✕ non' ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </details>
                <?php endif; ?>

                <?php if ($result['found']): ?>
                    <p class="ad-success">✓ Le formulaire de la borne afficherait automatiquement
                        "<strong><?= htmlspecialchars((string)$result['nom']) ?></strong>"<?php if (count($result['entries']) > 1): ?> (sélectionné car son attribut agent est le plus court parmi les comptes trouvés)<?php endif; ?>.
                        <?php if ($result['service'] !== null): ?>
                            Le champ Service serait préempli avec
                            "<strong><?= htmlspecialchars($result['service']) ?></strong>".
                        <?php else: ?>
                            Le champ Service resterait en sélection manuelle (attribut absent, vide, ou sans séparateur exploitable).
                        <?php endif; ?>
                        <?php if ($result['afficher_stats']): ?>
                            L'icône Statistiques serait <strong>visible</strong> (membre du groupe configuré).
                        <?php else: ?>
                            L'icône Statistiques resterait <strong>masquée</strong> (non membre du groupe configuré, ou groupe non configuré).
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <p class="ad-error">Résultat ambigu (plusieurs comptes de même longueur d'attribut, ou attribut nom absent) : le formulaire basculerait en saisie manuelle.</p>
                <?php endif; ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>

</div>

</body>
</html>
