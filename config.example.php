<?php
/**
 * Configuration de connexion à la base de données.
 * Adaptez les identifiants à votre environnement.
 */

declare(strict_types=1);

$DB_HOST = 'localhost';
$DB_NAME = 'satisfaction';
$DB_USER = 'root';
$DB_PASS = 'change_moi';
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erreur de connexion à la base de données.']);
    } else {
        echo 'Erreur de connexion à la base de données : ' . htmlspecialchars($e->getMessage());
    }
    exit;
}

/**
 * Mot de passe du compte de service Active Directory (bind LDAP).
 *
 * La table `ad_config` ne stocke que l'empreinte MD5 de ce mot de passe
 * (vérification/traçabilité) : un hash MD5 est à sens unique et ne peut donc
 * pas servir à authentifier la connexion LDAP elle-même, qui nécessite le
 * mot de passe en clair. Celui-ci doit donc rester ici, en dehors de la base
 * de données, idéalement dans un fichier hors de la racine web publique.
 *
 * Laissez la valeur vide ('') pour désactiver la connexion AD : la borne
 * fonctionnera alors uniquement en saisie manuelle.
 */
define('AD_BIND_PASSWORD', '');

