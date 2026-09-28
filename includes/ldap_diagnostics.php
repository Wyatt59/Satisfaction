<?php
declare(strict_types=1);

require_once __DIR__ . '/ldap_auth.php';

/**
 * Exécute la connexion AD étape par étape, en conservant le détail de chaque
 * étape (succès/échec + message), pour un affichage de diagnostic.
 *
 * Contrairement à lookupAgentInActiveDirectory() (qui ne renvoie qu'un
 * résultat "safe" pour le formulaire de la borne), cette fonction est
 * destinée à un usage d'administration/débogage uniquement.
 */
function runAdDiagnostics(PDO $pdo, ?string $numeroAgent = null): array
{
    $steps = [];
    $result = ['found' => false, 'nom' => null, 'service' => null, 'afficher_stats' => false, 'groupe_diag' => null, 'entries' => [], 'filter' => null];

    $addStep = function (string $label, bool $ok, string $detail = '') use (&$steps) {
        $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    };

    // 1. Extension LDAP
    $extOk = extension_loaded('ldap');
    $addStep(
        'Extension PHP "ldap" chargée',
        $extOk,
        $extOk ? '' : 'L\'extension ldap n\'est pas activée sur ce serveur PHP (php -m | grep ldap).'
    );
    if (!$extOk) {
        return ['steps' => $steps, 'config' => null, 'result' => $result];
    }

    // 2. Configuration en base
    $stmt = $pdo->query('SELECT * FROM ad_config WHERE id = 1 LIMIT 1');
    $cfg = $stmt->fetch();

    $cfgOk = (bool)$cfg;
    $addStep('Ligne de configuration trouvée dans ad_config', $cfgOk, $cfgOk ? '' : 'Table ad_config vide ou absente.');
    if (!$cfgOk) {
        return ['steps' => $steps, 'config' => null, 'result' => $result];
    }

    $actifOk = (int)$cfg['actif'] === 1;
    $addStep('Connexion AD activée (actif = 1)', $actifOk, $actifOk ? '' : 'actif = 0 : passez-le à 1 pour activer la recherche AD.');

    $champsOk = $cfg['host'] !== '' && $cfg['base_dn'] !== '' && $cfg['bind_dn'] !== '';
    $addStep(
        'Champs obligatoires renseignés (host, base_dn, bind_dn)',
        $champsOk,
        $champsOk ? '' : 'Un ou plusieurs champs (host / base_dn / bind_dn) sont vides.'
    );

    // 3. Mot de passe
    $pwdDefined = defined('AD_BIND_PASSWORD') && AD_BIND_PASSWORD !== '';
    $addStep(
        'Mot de passe AD_BIND_PASSWORD renseigné dans config.php',
        $pwdDefined,
        $pwdDefined ? '' : 'La constante AD_BIND_PASSWORD est vide dans config.php.'
    );

    $pwdMatch = $pwdDefined && $cfg['bind_password_md5'] !== '' && md5(AD_BIND_PASSWORD) === $cfg['bind_password_md5'];
    $addStep(
        'Empreinte MD5 cohérente entre config.php et ad_config.bind_password_md5',
        $pwdMatch,
        $pwdMatch ? '' : 'md5(AD_BIND_PASSWORD) ne correspond pas à ad_config.bind_password_md5 (ou celui-ci est vide).'
    );

    if (!$actifOk || !$champsOk || !$pwdDefined || !$pwdMatch) {
        return ['steps' => $steps, 'config' => $cfg, 'result' => $result];
    }

    // 4. Connexion réseau
    $conn = @ldap_connect($cfg['host'], (int)$cfg['port']);
    $connOk = (bool)$conn;
    $addStep(
        'ldap_connect() vers ' . $cfg['host'] . ':' . $cfg['port'],
        $connOk,
        $connOk ? '' : 'Échec de ldap_connect (host/port injoignable ou invalide).'
    );
    if (!$connOk) {
        return ['steps' => $steps, 'config' => $cfg, 'result' => $result];
    }

    @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 4);

    // 5. TLS (si demandé)
    if (!empty($cfg['use_tls'])) {
        $tlsOk = @ldap_start_tls($conn);
        $addStep('ldap_start_tls()', $tlsOk, $tlsOk ? '' : 'Échec du démarrage TLS (certificat, port, ou serveur ne supportant pas STARTTLS).');
        if (!$tlsOk) {
            return ['steps' => $steps, 'config' => $cfg, 'result' => $result];
        }
    }

    // 6. Bind (authentification du compte de service)
    $bindOk = @ldap_bind($conn, $cfg['bind_dn'], AD_BIND_PASSWORD);
    $addStep(
        'ldap_bind() avec le compte de service (' . $cfg['bind_dn'] . ')',
        $bindOk,
        $bindOk ? '' : 'Échec du bind : identifiant ou mot de passe du compte de service incorrect, ou compte verrouillé/désactivé.'
    );
    if (!$bindOk) {
        return ['steps' => $steps, 'config' => $cfg, 'result' => $result];
    }

    // 7. Recherche (uniquement si un numéro d'agent de test a été fourni)
    if ($numeroAgent === null || $numeroAgent === '') {
        return ['steps' => $steps, 'config' => $cfg, 'result' => $result];
    }

    $agentAttr = $cfg['agent_attribute'] !== '' ? $cfg['agent_attribute'] : 'sAMAccountName';
    $nameAttr  = $cfg['name_attribute'] !== '' ? $cfg['name_attribute'] : 'displayName';
    $serviceAttr = $cfg['service_attribute'] !== '' ? $cfg['service_attribute'] : 'ExtensionName';
    $groupeCn = trim((string)($cfg['affichage_stat'] ?? ''));

    $safeAgent = ldap_escape($numeroAgent, '', LDAP_ESCAPE_FILTER);
    $filter = '(' . $agentAttr . '=*' . $safeAgent . ')';
    $result['filter'] = $filter;

    $search = @ldap_search($conn, $cfg['base_dn'], $filter, [$nameAttr, $agentAttr, $serviceAttr, 'memberof']);
    $searchOk = (bool)$search;
    $addStep('ldap_search() avec le filtre ' . $filter, $searchOk, $searchOk ? '' : 'Échec de la recherche (base_dn invalide ou droits insuffisants).');
    if (!$searchOk) {
        return ['steps' => $steps, 'config' => $cfg, 'result' => $result];
    }

    $entries = @ldap_get_entries($conn, $search);
    $count = $entries ? (int)$entries['count'] : 0;
    $addStep('Résultat de la recherche', $count > 0, $count . ' compte(s) trouvé(s) se terminant par "' . $numeroAgent . '".');

    $nameKey = strtolower($nameAttr);
    $agentKey = strtolower($agentAttr);
    $serviceKey = strtolower($serviceAttr);

    $best = $count > 1 ? selectBestAdEntry($entries, $count, $agentKey, $nameKey) : null;

    for ($i = 0; $i < $count; $i++) {
        $isBest = $best !== null
            && ($entries[$i]['dn'] ?? null) !== null
            && ($entries[$i]['dn'] ?? '') === ($best['dn'] ?? '');

        $serviceBrut = $entries[$i][$serviceKey][0] ?? null;
        $serviceExtrait = $serviceBrut !== null ? extraireServiceDepuisValeurAd((string)$serviceBrut) : '';

        $memberOfList = [];
        $memberOfCount = isset($entries[$i]['memberof']) ? (int)($entries[$i]['memberof']['count'] ?? 0) : 0;
        for ($j = 0; $j < $memberOfCount; $j++) {
            $memberOfList[] = (string)$entries[$i]['memberof'][$j];
        }

        $groupeDiag = diagnostiquerAppartenanceGroupe($conn, $cfg['base_dn'], $entries[$i], $groupeCn);

        $result['entries'][] = [
            'dn'              => $entries[$i]['dn'] ?? '',
            'nom'             => $entries[$i][$nameKey][0] ?? '(attribut absent)',
            'attr'            => $entries[$i][$agentKey][0] ?? '(attribut absent)',
            'service_brut'    => $serviceBrut,
            'service_extrait' => $serviceExtrait !== '' ? $serviceExtrait : null,
            'memberof'        => $memberOfList,
            'est_membre'      => $groupeDiag['resultat_final'],
            'groupe_diag'     => $groupeDiag,
            'selected'        => $isBest,
        ];

        if ($isBest || $count === 1) {
            $result['groupe_diag'] = $groupeDiag;
        }
    }

    if ($count === 1) {
        $result['found'] = true;
        $result['nom'] = $entries[0][$nameKey][0] ?? null;
        $serviceBrut = $entries[0][$serviceKey][0] ?? null;
        $serviceExtrait = $serviceBrut !== null ? extraireServiceDepuisValeurAd((string)$serviceBrut) : '';
        $result['service'] = $serviceExtrait !== '' ? $serviceExtrait : null;
        $result['afficher_stats'] = $result['groupe_diag']['resultat_final'] ?? false;
        $addStep('Résultat exploitable', true, 'Un seul compte correspond : le nom serait rempli automatiquement.');
    } elseif ($count > 1 && $best !== null) {
        $result['found'] = true;
        $result['nom'] = $best[$nameKey][0] ?? null;
        $serviceBrut = $best[$serviceKey][0] ?? null;
        $serviceExtrait = $serviceBrut !== null ? extraireServiceDepuisValeurAd((string)$serviceBrut) : '';
        $result['service'] = $serviceExtrait !== '' ? $serviceExtrait : null;
        $result['afficher_stats'] = $result['groupe_diag']['resultat_final'] ?? false;
        $addStep(
            'Résultat exploitable (plusieurs comptes, sélection du plus court)',
            true,
            'Plusieurs comptes correspondent : celui dont "' . $agentAttr . '" est le plus court ("' . ($best[$agentKey][0] ?? '') . '") est retenu.'
        );
    } elseif ($count > 1) {
        $addStep(
            'Résultat exploitable',
            false,
            'Plusieurs comptes correspondent avec une longueur d\'attribut identique (ambigu) : par sécurité, le formulaire basculerait en saisie manuelle.'
        );
    } else {
        $addStep('Résultat exploitable', false, 'Aucun compte ne correspond : le formulaire basculerait en saisie manuelle.');
    }

    return ['steps' => $steps, 'config' => $cfg, 'result' => $result];
}
