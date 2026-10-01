<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ldap_auth.php';

/**
 * Diagnostic pas-à-pas du canal Zoom Room (indexzoom.php), pour test_mail.php :
 * environnement PHP, structure de la base, configuration (lienzoomroom,
 * MAIL_FROM, APP_BASE_URL), paramètres d'envoi d'e-mail de php.ini et test
 * d'enregistrement d'une demande (annulé aussitôt).
 *
 * Chaque étape vaut 'ok', 'warn' (fonctionne, mais à vérifier) ou 'ko'
 * (bloquant). Usage d'administration/débogage uniquement.
 */
function runMailDiagnostics(PDO $pdo): array
{
    $sections = [];
    $current = null;

    $section = function (string $titre) use (&$sections, &$current) {
        $sections[] = ['titre' => $titre, 'steps' => []];
        $current = count($sections) - 1;
    };
    $addStep = function (string $label, string $level, string $detail = '') use (&$sections, &$current) {
        $sections[$current]['steps'][] = ['label' => $label, 'level' => $level, 'detail' => $detail];
    };

    // ------------------------------------------------------------
    $section('Environnement PHP');

    $addStep('Version de PHP : ' . PHP_VERSION, PHP_VERSION_ID >= 80000 ? 'ok' : 'ko',
        PHP_VERSION_ID >= 80000 ? '' : 'PHP 8.0 minimum est requis.');

    $mbOk = extension_loaded('mbstring');
    $addStep('Extension PHP "mbstring" chargée', $mbOk ? 'ok' : 'ko',
        $mbOk ? '' : 'Nécessaire pour encoder l\'objet de l\'e-mail (mb_encode_mimeheader). Activez extension=mbstring dans php.ini puis redémarrez le serveur web.');

    $ldapOk = extension_loaded('ldap');
    $addStep('Extension PHP "ldap" chargée', $ldapOk ? 'ok' : 'ko',
        $ldapOk ? '' : 'Sans elle, l\'adresse e-mail du demandeur ne peut pas être lue dans l\'AD (voir ad_test.php).');

    $desactivees = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    $mailOk = function_exists('mail') && !in_array('mail', $desactivees, true);
    $addStep('Fonction mail() disponible', $mailOk ? 'ok' : 'ko',
        $mailOk ? '' : 'mail() est désactivée (directive disable_functions de php.ini).');

    // ------------------------------------------------------------
    $section('Base de données (migrations « canal Zoom Room » de schema.sql)');

    // [table, colonne, définition attendue, contrôle du type, migration si absente]
    $colonnes = [
        ['demandes', 'canal', "ENUM('borne','zoomroom') NOT NULL DEFAULT 'borne'",
            fn(array $c) => str_contains($c['COLUMN_TYPE'], "'borne'") && str_contains($c['COLUMN_TYPE'], "'zoomroom'"),
            "ALTER TABLE demandes ADD COLUMN canal ENUM('borne','zoomroom') NOT NULL DEFAULT 'borne' AFTER site_id;"],
        ['demandes', 'token_satisfaction', 'CHAR(64) NULL',
            fn(array $c) => (int)$c['CHARACTER_MAXIMUM_LENGTH'] >= 64,
            'ALTER TABLE demandes ADD COLUMN token_satisfaction CHAR(64) NULL AFTER canal; ALTER TABLE demandes ADD UNIQUE KEY uq_token_satisfaction (token_satisfaction);'],
        ['demandes', 'email_demandeur', 'VARCHAR(255) NULL',
            fn(array $c) => (int)$c['CHARACTER_MAXIMUM_LENGTH'] >= 255,
            'ALTER TABLE demandes ADD COLUMN email_demandeur VARCHAR(255) NULL AFTER token_satisfaction;'],
        ['ad_config', 'lienzoomroom', 'VARCHAR(500) NULL',
            fn(array $c) => (int)$c['CHARACTER_MAXIMUM_LENGTH'] >= 500,
            'ALTER TABLE ad_config ADD COLUMN lienzoomroom VARCHAR(500) NULL AFTER service_attribute;'],
    ];
    $colonnesOk = true;
    foreach ($colonnes as [$table, $colonne, $definition, $typeOk, $migration]) {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute([':t' => $table, ':c' => $colonne]);
        $infos = $stmt->fetch();

        if (!$infos) {
            $colonnesOk = false;
            $addStep("Colonne {$table}.{$colonne}", 'ko', 'Colonne absente : exécutez dans phpMyAdmin : ' . $migration);
        } elseif (!$typeOk($infos)) {
            $colonnesOk = false;
            $addStep("Colonne {$table}.{$colonne} : {$infos['COLUMN_TYPE']}", 'ko',
                "Type ou taille incorrects (attendu : {$definition}). Exécutez dans phpMyAdmin : "
                . "ALTER TABLE {$table} MODIFY {$colonne} {$definition};");
        } else {
            $addStep("Colonne {$table}.{$colonne} : {$infos['COLUMN_TYPE']}", 'ok');
        }
    }

    if ($colonnesOk) {
        // Même requête que api/submit_request_zoom.php, dans une transaction
        // annulée : rien n'est conservé en base.
        try {
            $pdo->beginTransaction();
            $site = getSiteZoomRoom($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO demandes (nom_utilisateur, numero_agent, service, motif, detail_demande, date_creation, statut,
                                       site_id, canal, token_satisfaction, email_demandeur)
                 VALUES ("Test", "00000", "Test", "autre", NULL, NOW(), "en_attente",
                         :site_id, "zoomroom", :token, NULL)'
            );
            $stmt->execute([':site_id' => $site['id'], ':token' => bin2hex(random_bytes(32))]);
            $pdo->rollBack();
            $addStep('Test d\'enregistrement d\'une demande Zoom (annulé aussitôt)', 'ok',
                'Site utilisé : ' . SITE_ZOOMROOM_NOM . ' (id ' . $site['id'] . ').');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $addStep('Test d\'enregistrement d\'une demande Zoom', 'ko', $e->getMessage());
        }
    } else {
        $addStep('Test d\'enregistrement d\'une demande Zoom', 'ko', 'Non exécuté : colonnes manquantes ou incorrectes (voir ci-dessus).');
    }

    // ------------------------------------------------------------
    $section('Configuration du canal Zoom');

    $lienBrut = null;
    try {
        $lienBrut = trim((string)($pdo->query('SELECT lienzoomroom FROM ad_config WHERE id = 1')->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        // colonne absente : déjà signalé ci-dessus
    }
    if ($lienBrut === null) {
        $addStep('Lien de redirection ad_config.lienzoomroom', 'ko', 'Colonne absente (voir la section Base de données).');
    } elseif ($lienBrut === '') {
        $addStep('Lien de redirection ad_config.lienzoomroom', 'warn',
            'Non renseigné : après validation, indexzoom.php affichera un message de confirmation sans redirection.');
    } elseif (getLienZoomRoom($pdo) === null) {
        $addStep('Lien de redirection ad_config.lienzoomroom : ' . $lienBrut, 'ko',
            'Ce n\'est pas une URL valide : elle doit commencer par http:// ou https://.');
    } else {
        $addStep('Lien de redirection ad_config.lienzoomroom : ' . $lienBrut, 'ok');
    }

    $mailFrom = defined('MAIL_FROM') ? (string)MAIL_FROM : '';
    if ($mailFrom === '') {
        $addStep('Adresse d\'expédition MAIL_FROM (config.php)', 'warn',
            'Non définie : l\'e-mail partira de no-reply@<nom du serveur>, souvent refusé par le serveur de messagerie. Ajoutez define(\'MAIL_FROM\', \'...\'); dans config.php.');
    } elseif (!filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
        $addStep('Adresse d\'expédition MAIL_FROM : ' . $mailFrom, 'ko', 'Adresse e-mail invalide.');
    } else {
        $addStep('Adresse d\'expédition MAIL_FROM : ' . $mailFrom, 'ok');
    }

    $baseUrl = defined('APP_BASE_URL') ? (string)APP_BASE_URL : '';
    $baseUrlCalculee = getAppBaseUrlDepuisPage();
    if ($baseUrl === '') {
        $addStep('URL publique APP_BASE_URL (config.php)', 'warn',
            'Non définie : le lien de l\'e-mail sera déduit de l\'adresse utilisée sur le poste Zoom, par exemple '
            . $baseUrlCalculee . '/reponsesatisfaction.php?token=… Vérifiez qu\'elle est accessible depuis le poste des agents, sinon définissez APP_BASE_URL.');
    } elseif (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $addStep('URL publique APP_BASE_URL : ' . $baseUrl, 'ko', 'URL invalide (ex. https://support.exemple.fr/satisfaction).');
    } else {
        $addStep('URL publique APP_BASE_URL : ' . $baseUrl, 'ok',
            'Lien envoyé : ' . rtrim($baseUrl, '/') . '/reponsesatisfaction.php?token=…');
    }

    // ------------------------------------------------------------
    $section('Envoi des demandes à GLPI (index.php et indexzoom.php)');

    $colonnesGlpi = [
        'mailglpi'      => 'ALTER TABLE ad_config ADD COLUMN mailglpi VARCHAR(255) NULL AFTER lienzoomroom;',
        'mailglpiactif' => 'ALTER TABLE ad_config ADD COLUMN mailglpiactif TINYINT(1) NOT NULL DEFAULT 0 AFTER mailglpi;',
    ];
    $colonnesGlpiOk = true;
    foreach ($colonnesGlpi as $colonne => $migration) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_config' AND COLUMN_NAME = :c"
        );
        $stmt->execute([':c' => $colonne]);
        $existe = (int)$stmt->fetchColumn() > 0;
        $colonnesGlpiOk = $colonnesGlpiOk && $existe;
        $addStep("Colonne ad_config.{$colonne}", $existe ? 'ok' : 'ko',
            $existe ? '' : 'Colonne absente : exécutez dans phpMyAdmin : ' . $migration);
    }

    if ($colonnesGlpiOk) {
        $cfgGlpi = $pdo->query('SELECT mailglpi, mailglpiactif FROM ad_config WHERE id = 1')->fetch() ?: [];
        $actif = (int)($cfgGlpi['mailglpiactif'] ?? 0) === 1;
        $adresseGlpi = trim((string)($cfgGlpi['mailglpi'] ?? ''));

        $addStep('Envoi à GLPI activé (ad_config.mailglpiactif = 1)', $actif ? 'ok' : 'warn',
            $actif ? '' : 'Désactivé : aucun e-mail n\'est envoyé à GLPI. Passez mailglpiactif à 1 pour l\'activer.');

        if ($adresseGlpi === '') {
            $addStep('Adresse GLPI (ad_config.mailglpi)', $actif ? 'ko' : 'warn', 'Non renseignée.');
        } elseif (!filter_var($adresseGlpi, FILTER_VALIDATE_EMAIL)) {
            $addStep('Adresse GLPI (ad_config.mailglpi) : ' . $adresseGlpi, 'ko', 'Adresse e-mail invalide.');
        } else {
            $addStep('Adresse GLPI (ad_config.mailglpi) : ' . $adresseGlpi, 'ok',
                'Expéditeur : adresse de l\'agent lue dans l\'AD (à défaut : MAIL_FROM).');
        }
    }

    // ------------------------------------------------------------
    $section('Envoi d\'e-mail (php.ini)');

    if (PHP_OS_FAMILY === 'Windows') {
        $smtp = (string)ini_get('SMTP');
        $port = (int)ini_get('smtp_port');
        $addStep('Serveur SMTP (directives SMTP / smtp_port) : ' . $smtp . ':' . $port,
            $smtp !== '' && $smtp !== 'localhost' ? 'ok' : 'warn',
            $smtp === 'localhost' || $smtp === ''
                ? 'Valeur par défaut « localhost » : sous Windows, indiquez dans php.ini le serveur de messagerie de l\'entreprise (SMTP = smtp.exemple.fr, smtp_port = 25), puis redémarrez le serveur web.'
                : '');

        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($smtp !== '' ? $smtp : 'localhost', $port > 0 ? $port : 25, $errno, $errstr, 5);
        if ($sock) {
            stream_set_timeout($sock, 5);
            $banniere = trim((string)fgets($sock, 512));
            @fwrite($sock, "QUIT\r\n");
            fclose($sock);
            $addStep('Connexion au serveur SMTP', str_starts_with($banniere, '220') ? 'ok' : 'warn',
                'Réponse du serveur : ' . ($banniere !== '' ? $banniere : '(aucune)'));
        } else {
            $addStep('Connexion au serveur SMTP', 'ko',
                "Impossible de joindre {$smtp}:{$port} ({$errno} {$errstr}). Vérifiez le nom du serveur, le port et le pare-feu.");
        }

        $sendmailFrom = (string)ini_get('sendmail_from');
        $addStep('Directive sendmail_from : ' . ($sendmailFrom !== '' ? $sendmailFrom : '(vide)'), 'ok',
            'Sous Windows, l\'en-tête From de MAIL_FROM est utilisé ; sendmail_from sert de repli.');
    } else {
        $sendmailPath = (string)ini_get('sendmail_path');
        $binaire = (string)strtok($sendmailPath, ' ');
        $binaireOk = $binaire !== '' && trouverExecutable($binaire);
        $addStep('Programme d\'envoi (sendmail_path) : ' . ($sendmailPath !== '' ? $sendmailPath : '(vide)'),
            $binaireOk ? 'ok' : 'ko',
            $binaireOk ? '' : 'Programme introuvable ou non exécutable : installez un agent d\'envoi (postfix, msmtp, ssmtp...) ou corrigez sendmail_path.');
    }

    return $sections;
}

/**
 * Équivalent de getAppBaseUrl() pour une page située à la racine de
 * l'application (test_mail.php), et non dans api/.
 */
function getAppBaseUrlDepuisPage(): string
{
    $https  = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir    = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

    return $scheme . '://' . $host . $dir;
}

/**
 * Indique si le programme existe et est exécutable : chemin absolu, ou nom
 * recherché dans les répertoires du PATH.
 */
function trouverExecutable(string $programme): bool
{
    if (str_contains($programme, '/')) {
        return is_executable($programme);
    }
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
        if ($dir !== '' && is_executable($dir . '/' . $programme)) {
            return true;
        }
    }
    return false;
}
