-- ============================================================
-- Base de données : Borne de support / Enquête de satisfaction
-- ============================================================

CREATE DATABASE IF NOT EXISTS support_kiosk
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE support_kiosk;

CREATE TABLE IF NOT EXISTS demandes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nom_utilisateur VARCHAR(100)   NOT NULL,
    numero_agent    VARCHAR(50)    NOT NULL,
    service         VARCHAR(100)   NOT NULL,
    motif           ENUM('materiel','logiciel','autre') NOT NULL,
    detail_demande  VARCHAR(1000) NULL,
    date_creation   DATETIME       NOT NULL,
    date_cloture    DATETIME       NULL,
    satisfaction    ENUM('satisfait','neutre','insatisfait') NULL,
    commentaire     VARCHAR(500) NULL,
    statut          ENUM('en_attente','cloture') NOT NULL DEFAULT 'en_attente',
    type_cloture    ENUM('manuelle','automatique') NULL,
    site_id         INT NULL,
    canal           ENUM('borne','zoomroom') NOT NULL DEFAULT 'borne',
    token_satisfaction CHAR(64) NULL,
    email_demandeur VARCHAR(255) NULL,

    UNIQUE KEY uq_token_satisfaction (token_satisfaction),
    INDEX idx_statut (statut),
    INDEX idx_date_creation (date_creation),
    INDEX idx_site (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Sites (multi-sites) : rattachement d'une borne à un site
-- ============================================================
-- Chaque site est identifié par les deux premiers octets de l'adresse IP
-- des bornes qui s'y trouvent (ex : "192.168" pour toutes les IP en
-- 192.168.x.x). La colonne `nom_site` est volontairement laissée vide par
-- l'application : une ligne est créée automatiquement (nom_site = NULL) dès
-- qu'une borne inconnue visite la page parametres.php, et c'est ensuite à un
-- administrateur de renseigner le nom du site à la main, via phpMyAdmin.
-- Tant que nom_site n'est pas renseigné, la borne concernée reste bloquée
-- (voir index.php).
CREATE TABLE IF NOT EXISTS sites (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ip_prefix     VARCHAR(15)  NOT NULL UNIQUE,
    nom_site      VARCHAR(100) NULL,
    date_creation DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Configuration Active Directory (annuaire LDAP)
-- ============================================================
-- Une seule ligne (id=1). "actif" à 0 par défaut : tant que l'AD n'est pas
-- configuré et activé, la borne fonctionne en saisie manuelle (comportement
-- inchangé). bind_password_md5 est l'empreinte MD5 du mot de passe du compte
-- de service AD, stockée ici à titre de vérification/traçabilité ; le mot de
-- passe en clair, nécessaire à la connexion LDAP elle-même, doit être défini
-- dans config.php (constante AD_BIND_PASSWORD) — un hash MD5 seul ne permet
-- pas d'authentifier une connexion LDAP.
-- affichage_stat contient le nom (CN) du groupe AD dont les membres sont
-- autorisés à voir l'icône Statistiques sur la borne (ex. "IT-Support").
-- Laissé à NULL par défaut : dans ce cas, l'icône ne s'affiche pour personne
-- (comportement sécurisé par défaut, à configurer explicitement via phpMyAdmin).
-- service_attribute contient le nom de l'attribut AD utilisé pour préremplir
-- automatiquement le champ "Service" de la demande (ex. "ExtensionName"). Seuls
-- les caractères situés après le dernier séparateur "<>" de la valeur de cet
-- attribut sont utilisés (ex. "Direction Générale<>Support Informatique" ->
-- "Support Informatique"). Si l'attribut est absent, vide, ou que la valeur
-- obtenue après extraction est vide, le champ Service reste géré comme
-- aujourd'hui (sélection manuelle par l'utilisateur, aucun préremplissage).
CREATE TABLE IF NOT EXISTS ad_config (
    id                 TINYINT PRIMARY KEY DEFAULT 1,
    actif              TINYINT(1)   NOT NULL DEFAULT 0,
    host               VARCHAR(255) NOT NULL DEFAULT '',
    port               INT          NOT NULL DEFAULT 389,
    use_tls            TINYINT(1)   NOT NULL DEFAULT 0,
    base_dn            VARCHAR(255) NOT NULL DEFAULT '',
    bind_dn            VARCHAR(255) NOT NULL DEFAULT '',
    bind_password_md5  CHAR(32)     NOT NULL DEFAULT '',
    agent_attribute    VARCHAR(100) NOT NULL DEFAULT 'sAMAccountName',
    name_attribute     VARCHAR(100) NOT NULL DEFAULT 'displayName',
    affichage_stat     VARCHAR(100) NULL,
    service_attribute  VARCHAR(100) NOT NULL DEFAULT 'ExtensionName',
    lienzoomroom       VARCHAR(500) NULL,
    mailglpi           VARCHAR(255) NULL,
    mailglpiactif      TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ad_config (id, actif, host, port, use_tls, base_dn, bind_dn, bind_password_md5, agent_attribute, name_attribute, affichage_stat, service_attribute)
VALUES (1, 0, '', 389, 0, '', '', '', 'sAMAccountName', 'displayName', NULL, 'ExtensionName')
ON DUPLICATE KEY UPDATE id = id;

-- Migration pour une installation déjà existante (si la table `ad_config` existe déjà
-- avec l'ancien attribut par défaut 'employeeID') :
-- UPDATE ad_config SET agent_attribute = 'sAMAccountName' WHERE id = 1 AND agent_attribute = 'employeeID';

-- Migration pour une installation déjà existante (si la table `demandes` existe déjà
-- sans la colonne `detail_demande`) :
-- ALTER TABLE demandes ADD COLUMN detail_demande VARCHAR(1000) NULL AFTER motif;

-- Migration pour une installation déjà existante (si la table `demandes` existe déjà
-- sans la colonne `commentaire`) :
-- ALTER TABLE demandes ADD COLUMN commentaire VARCHAR(500) NULL AFTER satisfaction;

-- Migration pour une installation déjà existante (si la table `demandes` existe déjà
-- sans la colonne `type_cloture`) :
-- ALTER TABLE demandes ADD COLUMN type_cloture ENUM('manuelle','automatique') NULL AFTER statut;

-- Migration pour une installation déjà existante (si la table `demandes` existe déjà
-- sans la colonne `site_id`, nécessaire au multi-site) :
-- ALTER TABLE demandes ADD COLUMN site_id INT NULL AFTER type_cloture;
-- ALTER TABLE demandes ADD INDEX idx_site (site_id);

-- Migration pour une installation déjà existante (si la table `sites` n'existe pas
-- encore) : voir la définition CREATE TABLE IF NOT EXISTS sites plus haut, à exécuter
-- telle quelle sur une base existante.

-- Migration pour une installation déjà existante (si la table `ad_config` existe déjà
-- sans la colonne `affichage_stat`, nécessaire à l'affichage conditionnel de l'icône
-- Statistiques) :
-- ALTER TABLE ad_config ADD COLUMN affichage_stat VARCHAR(100) NULL AFTER name_attribute;

-- Migration pour une installation déjà existante (si la table `ad_config` existe déjà
-- sans la colonne `service_attribute`, nécessaire au préremplissage automatique du
-- champ Service à partir de l'Active Directory) :
-- ALTER TABLE ad_config ADD COLUMN service_attribute VARCHAR(100) NOT NULL DEFAULT 'ExtensionName' AFTER affichage_stat;

-- Migration pour une installation ayant déjà reçu une version antérieure de cette
-- fonctionnalité (colonne service_attribute déjà présente avec la valeur 'department') :
-- mettre à jour vers l'attribut réellement utilisé par l'entreprise ('ExtensionName') :
-- UPDATE ad_config SET service_attribute = 'ExtensionName' WHERE id = 1 AND service_attribute = 'department';

-- ============================================================
-- Canal Zoom Room (indexzoom.php / reponsesatisfaction.php)
-- ============================================================
-- Les demandes saisies depuis indexzoom.php (intervention à distance via Zoom
-- Room, au domicile ou au bureau de l'agent) sont rattachées au site
-- "ZoomRoom" (créé automatiquement au premier usage, ip_prefix = 'zoomroom')
-- et ont canal = 'zoomroom'. Un e-mail contenant un lien unique
-- (token_satisfaction) vers reponsesatisfaction.php est envoyé à l'adresse du
-- demandeur récupérée dans l'AD (attribut "mail"). Après validation,
-- indexzoom.php redirige vers l'URL renseignée dans ad_config.lienzoomroom.
-- Ces demandes ne sont pas clôturées automatiquement à 17h (l'agent répond
-- plus tard depuis son e-mail) mais au bout de 7 jours sans réponse.

-- Migration pour une installation déjà existante (canal Zoom Room) :
-- ALTER TABLE demandes ADD COLUMN canal ENUM('borne','zoomroom') NOT NULL DEFAULT 'borne' AFTER site_id;
-- ALTER TABLE demandes ADD COLUMN token_satisfaction CHAR(64) NULL AFTER canal;
-- ALTER TABLE demandes ADD COLUMN email_demandeur VARCHAR(255) NULL AFTER token_satisfaction;
-- ALTER TABLE demandes ADD UNIQUE KEY uq_token_satisfaction (token_satisfaction);
-- ALTER TABLE ad_config ADD COLUMN lienzoomroom VARCHAR(500) NULL AFTER service_attribute;
-- UPDATE ad_config SET lienzoomroom = 'https://intranet.exemple.fr/page-apres-zoom' WHERE id = 1;

-- ============================================================
-- Envoi des demandes de la borne à GLPI (index.php)
-- ============================================================
-- À la validation d'une demande sur la borne, si ad_config.mailglpiactif = 1,
-- un e-mail est envoyé à l'adresse ad_config.mailglpi (collecteur GLPI). Son
-- expéditeur est l'adresse de l'agent lue dans l'AD (à défaut : MAIL_FROM de
-- config.php) ; le corps commence par la source, ex. "(SAS Lille)".

-- Migration pour une installation déjà existante (envoi à GLPI) :
-- ALTER TABLE ad_config ADD COLUMN mailglpi VARCHAR(255) NULL AFTER lienzoomroom;
-- ALTER TABLE ad_config ADD COLUMN mailglpiactif TINYINT(1) NOT NULL DEFAULT 0 AFTER mailglpi;
-- UPDATE ad_config SET mailglpi = 'glpi@exemple.fr', mailglpiactif = 1 WHERE id = 1;

-- Exemple de compte MySQL dédié (à adapter / sécuriser en production)
-- CREATE USER 'kiosk_user'@'localhost' IDENTIFIED BY 'change_moi';
-- GRANT SELECT, INSERT, UPDATE ON support_kiosk.* TO 'kiosk_user'@'localhost';
-- FLUSH PRIVILEGES;
