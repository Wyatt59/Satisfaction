<?php
declare(strict_types=1);

/**
 * Établit la connexion et le bind au service AD (compte de service), sans
 * effectuer de recherche. Retourne null au moindre échec (extension absente,
 * config manquante/désactivée, mot de passe incohérent, hôte injoignable,
 * bind refusé...).
 */
function connectAdService(PDO $pdo): ?array
{
    if (!extension_loaded('ldap')) {
        return null;
    }

    $stmt = $pdo->query('SELECT * FROM ad_config WHERE id = 1 LIMIT 1');
    $cfg = $stmt->fetch();

    if (!$cfg || (int)$cfg['actif'] !== 1 || $cfg['host'] === '' || $cfg['base_dn'] === '' || $cfg['bind_dn'] === '') {
        return null;
    }

    if (!defined('AD_BIND_PASSWORD') || AD_BIND_PASSWORD === '') {
        return null;
    }

    // Le mot de passe en clair de config.php doit correspondre à l'empreinte
    // MD5 stockée en base ; sinon on considère la configuration comme non
    // fiable et on refuse de tenter la connexion.
    if ($cfg['bind_password_md5'] === '' || md5(AD_BIND_PASSWORD) !== $cfg['bind_password_md5']) {
        return null;
    }

    $conn = @ldap_connect($cfg['host'], (int)$cfg['port']);
    if (!$conn) {
        return null;
    }

    @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 4);

    if (!empty($cfg['use_tls']) && !@ldap_start_tls($conn)) {
        return null;
    }

    if (!@ldap_bind($conn, $cfg['bind_dn'], AD_BIND_PASSWORD)) {
        return null;
    }

    return ['conn' => $conn, 'cfg' => $cfg];
}

/**
 * Indique si l'AD est joignable et utilisable (sans effectuer de recherche).
 * Utilisé par api/ad_status.php pour décider de l'ordre d'affichage des
 * champs du formulaire (numéro d'agent en premier si l'AD fonctionne).
 */
function isActiveDirectoryAvailable(PDO $pdo): bool
{
    return connectAdService($pdo) !== null;
}

/**
 * Sélectionne la meilleure entrée AD parmi plusieurs résultats correspondant
 * au même suffixe de numéro d'agent : on retient celle dont la valeur de
 * l'attribut agent (ex. sAMAccountName) est la plus courte, ce qui
 * correspond le plus souvent au compte réellement recherché (les comptes
 * plus longs ne correspondent que par coïncidence de suffixe).
 * En cas d'égalité de longueur entre les meilleurs candidats, le résultat
 * reste ambigu (aucune entrée n'est retenue), par sécurité.
 */
function selectBestAdEntry(array $entries, int $count, string $agentKey, string $nameKey): ?array
{
    $candidates = [];
    for ($i = 0; $i < $count; $i++) {
        if (isset($entries[$i][$agentKey][0])) {
            $candidates[] = $entries[$i];
        }
    }

    if (empty($candidates)) {
        return null;
    }

    usort($candidates, function ($a, $b) use ($agentKey) {
        return strlen($a[$agentKey][0]) <=> strlen($b[$agentKey][0]);
    });

    $shortestLen = strlen($candidates[0][$agentKey][0]);
    $tieCount = 0;
    foreach ($candidates as $c) {
        if (strlen($c[$agentKey][0]) === $shortestLen) {
            $tieCount++;
        }
    }

    if ($tieCount > 1) {
        return null; // égalité de longueur : toujours ambigu, par sécurité
    }

    if (!isset($candidates[0][$nameKey][0])) {
        return null;
    }

    return $candidates[0];
}

/**
 * Extrait le CN (nom commun) d'un DN LDAP, ex. "CN=IT-Support,OU=Groupes,DC=exemple,DC=com"
 * -> "IT-Support". Retourne null si le DN ne commence pas par un composant CN=.
 */
function extraireCnDepuisDn(string $dn): ?string
{
    if (preg_match('/^CN=([^,]+)/i', $dn, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Indique si l'entrée AD (telle que retournée par ldap_get_entries, avec
 * l'attribut memberof demandé) appartient DIRECTEMENT au groupe dont le nom
 * (CN) est fourni. Comparaison insensible à la casse.
 *
 * Limite connue : ne détecte que l'appartenance directe. Si l'utilisateur
 * est membre d'un groupe A, lui-même membre du groupe recherché (groupes
 * imbriqués / "groupe de groupes"), cette fonction renvoie false — c'est
 * pourquoi determinerAppartenanceGroupe() ci-dessous doit être préférée,
 * cette fonction-ci n'étant conservée que comme repli.
 */
function estMembreDuGroupe(array $entry, string $groupeCn): bool
{
    if ($groupeCn === '' || !isset($entry['memberof'])) {
        return false;
    }

    $count = (int)($entry['memberof']['count'] ?? 0);
    for ($i = 0; $i < $count; $i++) {
        $cn = extraireCnDepuisDn((string)$entry['memberof'][$i]);
        if ($cn !== null && strcasecmp($cn, $groupeCn) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Indique si l'utilisateur (identifié par son DN) appartient au groupe dont
 * le nom (CN) est fourni, EN TENANT COMPTE DES GROUPES IMBRIQUÉS : si
 * l'utilisateur est membre d'un groupe A qui est lui-même membre du groupe
 * recherché (groupe de groupes, à n'importe quelle profondeur), le résultat
 * est true.
 *
 * Combine deux vérifications, TOUJOURS exécutées toutes les deux :
 *   1. Appartenance directe, via l'attribut memberof déjà récupéré sur $entry
 *      (estMembreDuGroupe()).
 *   2. Appartenance récursive côté serveur, via la règle de comparaison
 *      LDAP_MATCHING_RULE_IN_CHAIN (OID 1.2.840.113556.1.4.1941), une
 *      extension propre à Microsoft Active Directory qui résout
 *      l'imbrication entièrement côté serveur, en une seule requête.
 *
 * Les deux sont combinées par OR plutôt qu'en repli conditionnel : un
 * serveur LDAP générique (OpenLDAP...) qui ne supporte pas cette règle de
 * comparaison n'échoue PAS la recherche — il l'exécute silencieusement et
 * renvoie simplement 0 résultat, indiscernable d'une vraie absence
 * d'appartenance. Une logique "on tente le récursif, puis on se replie sur
 * le direct seulement si la recherche échoue" manquerait donc les membres
 * directs dans ce cas. Faire les deux systématiquement est sûr dans tous les
 * cas : sur Microsoft AD, le direct est de toute façon inclus dans le
 * résultat récursif (redondant mais sans effet) ; sur un LDAP générique, seul
 * le direct fonctionne, ce qui reste la meilleure détection possible.
 */
function determinerAppartenanceGroupe($conn, string $baseDn, array $entry, string $groupeCn): bool
{
    if ($groupeCn === '') {
        return false;
    }

    if (estMembreDuGroupe($entry, $groupeCn)) {
        return true;
    }

    $userDn = (string)($entry['dn'] ?? '');
    if ($userDn === '') {
        return false;
    }

    $safeCn = ldap_escape($groupeCn, '', LDAP_ESCAPE_FILTER);
    $safeUserDn = ldap_escape($userDn, '', LDAP_ESCAPE_FILTER);
    $filter = '(&(objectClass=group)(cn=' . $safeCn . ')(member:1.2.840.113556.1.4.1941:=' . $safeUserDn . '))';

    $search = @ldap_search($conn, $baseDn, $filter, ['cn'], 0, 1);
    if (!$search) {
        return false;
    }

    $entries = @ldap_get_entries($conn, $search);
    return $entries !== false && (int)($entries['count'] ?? 0) > 0;
}

/**
 * Version "diagnostic" de determinerAppartenanceGroupe() : exécute les mêmes
 * vérifications (directe + récursive) mais renvoie le détail de chaque étape
 * au lieu d'un simple booléen, pour permettre d'identifier PRÉCISÉMENT où une
 * appartenance attendue n'est pas détectée. Utilisée uniquement par la page
 * de diagnostic ad_test.php — jamais par le formulaire de la borne.
 *
 * Champs renvoyés :
 *   - membre_direct       : résultat de estMembreDuGroupe() (attribut memberof)
 *   - groupe_trouve_dn    : liste des DN de groupe(s) correspondant au CN
 *                           recherché sous base_dn, en excluant toute règle
 *                           récursive (recherche simple "cn=..."). Une liste
 *                           vide signifie que le groupe est INTROUVABLE sous
 *                           le base_dn configuré — cause la plus fréquente
 *                           d'un groupe imbriqué non détecté (base_dn trop
 *                           restreint, orthographe du CN incorrecte, ou
 *                           groupe situé hors de l'annuaire interrogé).
 *   - filtre_recursif     : le filtre LDAP exact utilisé pour le test récursif
 *   - recherche_recursive_ok : false si ldap_search() a échoué techniquement
 *                              (erreur réseau/protocole), true sinon (y
 *                              compris si 0 résultat trouvé)
 *   - membre_recursif     : true si la recherche récursive a trouvé une
 *                           correspondance (groupes imbriqués détectés)
 *   - resultat_final      : membre_direct OU membre_recursif
 */
function diagnostiquerAppartenanceGroupe($conn, string $baseDn, array $entry, string $groupeCn): array
{
    $diag = [
        'membre_direct'          => false,
        'groupe_trouve_dn'       => [],
        'filtre_recursif'        => null,
        'recherche_recursive_ok' => false,
        'membre_recursif'        => false,
        'resultat_final'         => false,
    ];

    if ($groupeCn === '') {
        return $diag;
    }

    $diag['membre_direct'] = estMembreDuGroupe($entry, $groupeCn);

    $safeCn = ldap_escape($groupeCn, '', LDAP_ESCAPE_FILTER);

    // Recherche simple (sans règle récursive) : le groupe existe-t-il ne
    // serait-ce que sous ce base_dn ? Indépendant de toute question
    // d'imbrication — sert uniquement à vérifier la portée de recherche.
    $filtreSimple = '(&(objectClass=group)(cn=' . $safeCn . '))';
    $rechercheSimple = @ldap_search($conn, $baseDn, $filtreSimple, ['dn'], 0, 10);
    if ($rechercheSimple) {
        $entriesSimple = @ldap_get_entries($conn, $rechercheSimple);
        $countSimple = $entriesSimple ? (int)($entriesSimple['count'] ?? 0) : 0;
        for ($i = 0; $i < $countSimple; $i++) {
            $diag['groupe_trouve_dn'][] = (string)($entriesSimple[$i]['dn'] ?? '');
        }
    }

    $userDn = (string)($entry['dn'] ?? '');
    if ($userDn !== '') {
        $safeUserDn = ldap_escape($userDn, '', LDAP_ESCAPE_FILTER);
        $filter = '(&(objectClass=group)(cn=' . $safeCn . ')(member:1.2.840.113556.1.4.1941:=' . $safeUserDn . '))';
        $diag['filtre_recursif'] = $filter;

        $search = @ldap_search($conn, $baseDn, $filter, ['cn'], 0, 1);
        $diag['recherche_recursive_ok'] = (bool)$search;
        if ($search) {
            $entriesRec = @ldap_get_entries($conn, $search);
            $diag['membre_recursif'] = $entriesRec !== false && (int)($entriesRec['count'] ?? 0) > 0;
        }
    }

    $diag['resultat_final'] = $diag['membre_direct'] || $diag['membre_recursif'];

    return $diag;
}

/**
 * Extrait la partie utile d'une valeur d'attribut AD représentant une
 * organisation/service (ex. "Direction Générale<>Support Informatique") :
 * les caractères situés après le dernier séparateur "<>". S'il n'y a aucun
 * "<>" dans la valeur, celle-ci est retournée telle quelle (après trim).
 * Retourne une chaîne vide si la valeur fournie est vide ou ne contient que
 * des espaces.
 */
function extraireServiceDepuisValeurAd(string $valeur): string
{
    $valeur = trim($valeur);
    if ($valeur === '') {
        return '';
    }

    $separateur = '<>';
    $pos = strrpos($valeur, $separateur);
    if ($pos === false) {
        return $valeur;
    }

    return trim(substr($valeur, $pos + strlen($separateur)));
}

/**
 * Tente de retrouver le nom complet d'un agent dans l'Active Directory à
 * partir de son numéro d'agent.
 *
 * Retourne toujours un tableau "sûr" :
 *   ['available' => bool, 'found' => bool, 'nom' => string|null, 'afficher_stats' => bool, 'service' => string|null]
 *
 * 'available' = false dans TOUS les cas d'échec (extension LDAP absente, AD
 * non configuré/désactivé, mot de passe non renseigné ou ne correspondant
 * pas à l'empreinte enregistrée, hôte injoignable, échec du bind...). Le
 * formulaire de la borne doit alors se comporter exactement comme en saisie
 * manuelle : c'est la responsabilité de l'appelant (api/lookup_agent.php).
 *
 * 'afficher_stats' indique si l'agent retrouvé est membre du groupe AD
 * configuré (ad_config.affichage_stat) et donc autorisé à voir l'icône
 * Statistiques sur la borne — appartenance directe OU via des groupes
 * imbriqués (voir determinerAppartenanceGroupe()). Comportement
 * volontairement restrictif (repli fermé) : false dès que l'appartenance
 * n'a pas pu être positivement établie (groupe non configuré, AD
 * indisponible, agent non trouvé, etc.).
 *
 * 'service' contient la partie utile (après le dernier séparateur "<>") de
 * l'attribut AD configuré (ad_config.service_attribute, "ExtensionName" par
 * défaut), destinée à préremplir automatiquement le champ Service. Vaut null dès que cette
 * valeur ne peut pas être positivement déterminée (attribut absent/vide,
 * agent non trouvé, AD indisponible...) : l'appelant doit alors laisser le
 * champ Service inchangé (comportement manuel actuel).
 *
 * Si plusieurs comptes AD se terminent par le même numéro d'agent, celui
 * dont l'attribut agent est le plus court est retenu (voir selectBestAdEntry()).
 *
 * Cette fonction n'émet jamais de warning/erreur PHP visible : tous les
 * appels LDAP sont protégés par @ et vérifiés explicitement.
 */
function lookupAgentInActiveDirectory(PDO $pdo, string $numeroAgent): array
{
    $failure = ['available' => false, 'found' => false, 'nom' => null, 'afficher_stats' => false, 'service' => null];

    $svc = connectAdService($pdo);
    if ($svc === null) {
        return $failure;
    }
    $conn = $svc['conn'];
    $cfg = $svc['cfg'];

    $agentAttr   = $cfg['agent_attribute'] !== '' ? $cfg['agent_attribute'] : 'sAMAccountName';
    $nameAttr    = $cfg['name_attribute'] !== '' ? $cfg['name_attribute'] : 'displayName';
    $serviceAttr = $cfg['service_attribute'] !== '' ? $cfg['service_attribute'] : 'ExtensionName';
    $groupeCn    = trim((string)($cfg['affichage_stat'] ?? ''));

    // Le numéro d'agent correspond aux 5 derniers caractères du nom d'utilisateur AD
    // (ex. attribut sAMAccountName "jdupont12345") : on recherche donc par suffixe,
    // avec un filtre LDAP de type "se termine par".
    $safeAgent = ldap_escape($numeroAgent, '', LDAP_ESCAPE_FILTER);
    $filter = '(' . $agentAttr . '=*' . $safeAgent . ')';

    $search = @ldap_search($conn, $cfg['base_dn'], $filter, [$nameAttr, $agentAttr, 'memberof', $serviceAttr]);
    if (!$search) {
        return ['available' => true, 'found' => false, 'nom' => null, 'afficher_stats' => false, 'service' => null];
    }

    $entries = @ldap_get_entries($conn, $search);
    $nameKey = strtolower($nameAttr);
    $agentKey = strtolower($agentAttr);
    $serviceKey = strtolower($serviceAttr);
    $count = $entries ? (int)$entries['count'] : 0;

    if ($count === 0) {
        return ['available' => true, 'found' => false, 'nom' => null, 'afficher_stats' => false, 'service' => null];
    }

    if ($count === 1) {
        if (!isset($entries[0][$nameKey][0])) {
            return ['available' => true, 'found' => false, 'nom' => null, 'afficher_stats' => false, 'service' => null];
        }
        $service = isset($entries[0][$serviceKey][0])
            ? extraireServiceDepuisValeurAd((string)$entries[0][$serviceKey][0])
            : '';

        return [
            'available'      => true,
            'found'          => true,
            'nom'            => (string)$entries[0][$nameKey][0],
            'afficher_stats' => determinerAppartenanceGroupe($conn, $cfg['base_dn'], $entries[0], $groupeCn),
            'service'        => $service !== '' ? $service : null,
        ];
    }

    // Plusieurs comptes se terminent par le même numéro d'agent : on retient
    // celui dont l'attribut agent est le plus court.
    $best = selectBestAdEntry($entries, $count, $agentKey, $nameKey);
    if ($best === null) {
        return ['available' => true, 'found' => false, 'nom' => null, 'afficher_stats' => false, 'service' => null];
    }

    $service = isset($best[$serviceKey][0])
        ? extraireServiceDepuisValeurAd((string)$best[$serviceKey][0])
        : '';

    return [
        'available'      => true,
        'found'          => true,
        'nom'            => (string)$best[$nameKey][0],
        'afficher_stats' => determinerAppartenanceGroupe($conn, $cfg['base_dn'], $best, $groupeCn),
        'service'        => $service !== '' ? $service : null,
    ];
}


/**
 * Retrouve l'adresse e-mail (attribut AD "mail") d'un agent à partir de son
 * numéro d'agent, avec la même logique de recherche par suffixe que
 * lookupAgentInActiveDirectory(). Utilisée par le canal Zoom Room pour
 * envoyer l'e-mail de satisfaction au demandeur.
 *
 * Retourne null dès que l'adresse ne peut pas être déterminée de façon sûre
 * (AD indisponible, agent introuvable ou ambigu, attribut absent/invalide).
 */
function lookupAgentEmailInActiveDirectory(PDO $pdo, string $numeroAgent): ?string
{
    $svc = connectAdService($pdo);
    if ($svc === null) {
        return null;
    }
    $conn = $svc['conn'];
    $cfg = $svc['cfg'];

    $agentAttr = $cfg['agent_attribute'] !== '' ? $cfg['agent_attribute'] : 'sAMAccountName';
    $mailAttr  = 'mail';

    $safeAgent = ldap_escape($numeroAgent, '', LDAP_ESCAPE_FILTER);
    $filter = '(' . $agentAttr . '=*' . $safeAgent . ')';

    $search = @ldap_search($conn, $cfg['base_dn'], $filter, [$agentAttr, $mailAttr]);
    if (!$search) {
        return null;
    }

    $entries = @ldap_get_entries($conn, $search);
    $count = $entries ? (int)$entries['count'] : 0;
    if ($count === 0) {
        return null;
    }

    // Même règle que pour le nom : en cas de plusieurs comptes, celui dont
    // l'attribut agent est le plus court (et qui possède une adresse e-mail).
    $entry = $count === 1
        ? $entries[0]
        : selectBestAdEntry($entries, $count, strtolower($agentAttr), $mailAttr);

    $mail = trim((string)($entry[$mailAttr][0] ?? ''));

    return filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : null;
}
