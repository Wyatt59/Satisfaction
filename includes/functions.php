<?php
declare(strict_types=1);

/**
 * Clôture automatiquement toute demande encore "en_attente" :
 *  - créée aujourd'hui, dès qu'il est 17h00 ou plus,
 *  - ou créée un jour précédent et jamais traitée (filet de sécurité).
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
                 (CURTIME() >= '17:00:00' AND DATE(date_creation) = CURDATE())
                 OR DATE(date_creation) < CURDATE()
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
