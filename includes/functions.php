<?php
declare(strict_types=1);

/**
 * Délai (en jours) laissé à un demandeur du canal Zoom Room pour répondre à
 * l'e-mail de satisfaction avant la clôture automatique de sa demande.
 */
const ZOOMROOM_DELAI_REPONSE_JOURS = 7;

/**
 * Clôture automatiquement toute demande encore "en_attente" :
 *  - demandes de la borne (canal 'borne') :
 *      - créée aujourd'hui, dès qu'il est 17h00 ou plus,
 *      - ou créée un jour précédent et jamais traitée (filet de sécurité) ;
 *  - demandes Zoom Room (canal 'zoomroom') : créées depuis plus de
 *    ZOOMROOM_DELAI_REPONSE_JOURS jours sans réponse à l'e-mail de satisfaction.
 *
 * La satisfaction reste NULL (aucune réponse donnée) et type_cloture = 'automatique'
 * permet de la distinguer d'une clôture faite par l'utilisateur via les smileys.
 */
function autoCloseOverdueRequests(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        "UPDATE demandes
         SET statut = 'cloture', date_cloture = NOW(), type_cloture = 'automatique'
         WHERE statut = 'en_attente'
           AND (
                 (canal = 'borne' AND (
                     (CURTIME() >= '17:00:00' AND DATE(date_creation) = CURDATE())
                     OR DATE(date_creation) < CURDATE()
                 ))
                 OR (canal = 'zoomroom' AND date_creation < NOW() - INTERVAL " . ZOOMROOM_DELAI_REPONSE_JOURS . " DAY)
               )"
    );
    $stmt->execute();

    return $stmt->rowCount();
}

// ============================================================
// Multi-sites : rattachement d'une borne à un site via son IP
// ============================================================

/**
 * Extrait le "préfixe site" d'une adresse IPv4 : ses deux premiers octets
 * (ex. "192.168.10.42" -> "192.168"). Retourne null si l'adresse fournie
 * n'est pas une IPv4 valide (IPv6, valeur vide, etc.).
 */
function extraireIpPrefix(string $ip): ?string
{
    $parts = explode('.', $ip);
    if (count($parts) !== 4) {
        return null;
    }

    foreach ($parts as $part) {
        if ($part === '' || !ctype_digit($part) || (int)$part > 255) {
            return null;
        }
    }

    return $parts[0] . '.' . $parts[1];
}

/**
 * Adresse IP de la borne telle que vue par le serveur.
 */
function getClientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Recherche le site (table `sites`) correspondant au préfixe IP de la borne
 * courante. Retourne null si le préfixe est inconnu (jamais visité la page
 * parametres.php) ou si l'adresse IP n'est pas exploitable.
 */
function getSiteActuel(PDO $pdo): ?array
{
    $prefix = extraireIpPrefix(getClientIp());
    if ($prefix === null) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, ip_prefix, nom_site FROM sites WHERE ip_prefix = :prefix');
    $stmt->execute([':prefix' => $prefix]);
    $site = $stmt->fetch();

    return $site ?: null;
}

/**
 * Un site n'est considéré "configuré" que si un administrateur a renseigné
 * son nom (colonne `nom_site`) via phpMyAdmin. Une ligne existante avec
 * nom_site NULL ou vide (auto-créée par parametres.php) n'est pas suffisante.
 */
function siteEstConfigure(?array $site): bool
{
    return $site !== null
        && $site['nom_site'] !== null
        && trim((string)$site['nom_site']) !== '';
}

/**
 * Enregistre automatiquement le préfixe IP de la borne courante dans la
 * table `sites` s'il n'existe pas déjà (nom_site = NULL, à compléter ensuite
 * manuellement dans phpMyAdmin). N'est appelée que depuis parametres.php.
 * Retourne la ligne existante ou nouvellement créée, ou null si l'adresse IP
 * n'est pas une IPv4 exploitable.
 */
function enregistrerSiteSiInconnu(PDO $pdo): ?array
{
    $prefix = extraireIpPrefix(getClientIp());
    if ($prefix === null) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, ip_prefix, nom_site FROM sites WHERE ip_prefix = :prefix');
    $stmt->execute([':prefix' => $prefix]);
    $site = $stmt->fetch();

    if ($site) {
        return $site;
    }

    $insert = $pdo->prepare('INSERT INTO sites (ip_prefix, nom_site) VALUES (:prefix, NULL)');
    $insert->execute([':prefix' => $prefix]);

    return [
        'id'        => (int)$pdo->lastInsertId(),
        'ip_prefix' => $prefix,
        'nom_site'  => null,
    ];
}

// ============================================================
// Canal Zoom Room (indexzoom.php / reponsesatisfaction.php)
// ============================================================

/**
 * Nom et préfixe "IP" réservés au site virtuel des demandes Zoom Room. Le
 * préfixe n'est pas une adresse IPv4 : il ne peut donc jamais être associé à
 * une borne physique par getSiteActuel().
 */
const SITE_ZOOMROOM_NOM    = 'ZoomRoom';
const SITE_ZOOMROOM_PREFIX = 'zoomroom';

/**
 * Retourne le site "ZoomRoom" (table `sites`), en le créant au premier usage.
 */
function getSiteZoomRoom(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT id, ip_prefix, nom_site FROM sites WHERE nom_site = :nom OR ip_prefix = :prefix ORDER BY id LIMIT 1');
    $stmt->execute([':nom' => SITE_ZOOMROOM_NOM, ':prefix' => SITE_ZOOMROOM_PREFIX]);
    $site = $stmt->fetch();

    if ($site) {
        return $site;
    }

    $insert = $pdo->prepare('INSERT INTO sites (ip_prefix, nom_site) VALUES (:prefix, :nom)');
    $insert->execute([':prefix' => SITE_ZOOMROOM_PREFIX, ':nom' => SITE_ZOOMROOM_NOM]);

    return [
        'id'        => (int)$pdo->lastInsertId(),
        'ip_prefix' => SITE_ZOOMROOM_PREFIX,
        'nom_site'  => SITE_ZOOMROOM_NOM,
    ];
}

/**
 * URL vers laquelle indexzoom.php redirige après la validation d'une demande
 * (ad_config.lienzoomroom). Retourne null si elle n'est pas renseignée ou
 * n'est pas une URL http(s) valide.
 */
function getLienZoomRoom(PDO $pdo): ?string
{
    $stmt = $pdo->query('SELECT lienzoomroom FROM ad_config WHERE id = 1 LIMIT 1');
    $lien = trim((string)($stmt->fetchColumn() ?: ''));

    if ($lien === '' || !filter_var($lien, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $lien)) {
        return null;
    }

    return $lien;
}

/**
 * URL publique de l'application (sans "/" final), utilisée pour construire
 * le lien envoyé par e-mail. Constante APP_BASE_URL de config.php si elle est
 * renseignée, sinon déduite de la requête courante (appelée depuis api/).
 */
function getAppBaseUrl(): string
{
    if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
        return rtrim(APP_BASE_URL, '/');
    }

    $https  = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    // Le script appelant se trouve dans api/ : on remonte d'un niveau.
    $dir    = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'), 2)), '/');

    return $scheme . '://' . $host . $dir;
}

/**
 * Envoie au demandeur Zoom Room l'e-mail l'invitant à donner son avis via
 * reponsesatisfaction.php. Retourne true si mail() a accepté le message.
 */
function envoyerMailSatisfactionZoom(string $destinataire, string $nom, string $lienReponse): bool
{
    $expediteur = defined('MAIL_FROM') && MAIL_FROM !== ''
        ? MAIL_FROM
        : 'no-reply@' . preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $sujet = mb_encode_mimeheader('Votre avis sur notre intervention Zoom Room', 'UTF-8', 'B');

    $nomHtml  = htmlspecialchars($nom, ENT_QUOTES, 'UTF-8');
    $lienHtml = htmlspecialchars($lienReponse, ENT_QUOTES, 'UTF-8');

    // Une ligne par élément : les lignes d'un e-mail ne doivent pas dépasser 998 caractères.
    $corps = implode("\r\n", [
        '<!DOCTYPE html>',
        '<html lang="fr"><head><meta charset="UTF-8"></head>',
        '<body style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;color:#26313f;line-height:1.6;">',
        '<p>Bonjour ' . $nomHtml . ',</p>',
        '<p>Vous nous avez sollicités par le canal Zoom Room.</p>',
        '<p>Ayant pour objectif de nous améliorer, nous vous sollicitons pour vous exprimer sur la qualité de notre service :</p>',
        '<p><a href="' . $lienHtml . '" style="display:inline-block;padding:10px 20px;background:#1e6fea;'
            . 'color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Donner mon avis</a></p>',
        '<p style="font-size:13px;color:#6b7684;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>',
        $lienHtml . '</p>',
        '<p>Merci,<br>Le support informatique</p>',
        '</body></html>',
    ]);

    $headers = [
        'From'                      => $expediteur,
        'MIME-Version'              => '1.0',
        'Content-Type'              => 'text/html; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
    ];

    return @mail($destinataire, $sujet, $corps, $headers);
}
