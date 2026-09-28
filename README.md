# Borne de support informatique — Enquête de satisfaction

Application PHP / MySQL pour une borne tactile (écran Philips 242B9T ou équivalent) :
- Formulaire de nouvelle demande (nom, n° agent, service, motif) avec horodatage automatique.
- Liste en temps réel des demandes en attente, à droite de l'écran.
- Clôture d'une demande en touchant l'un des 3 visages (satisfait / neutre / insatisfait).
- Clavier virtuel tactile intégré en bas d'écran (aucun clavier physique requis).

## 1. Prérequis

- Serveur web avec PHP 8.0+ (extension PDO MySQL activée)
- MySQL / MariaDB
- Navigateur en plein écran (mode kiosque) sur l'écran tactile

## 2. Installation

1. Copier le dossier `kiosk_support/` sur votre serveur web (ex. `/var/www/html/kiosk_support`).
2. Créer la base de données :
   ```bash
   mysql -u root -p < schema.sql
   ```
3. Modifier `config.php` avec vos identifiants MySQL réels :
   ```php
   $DB_HOST = 'localhost';
   $DB_NAME = 'support_kiosk';
   $DB_USER = 'votre_utilisateur';
   $DB_PASS = 'votre_mot_de_passe';
   ```
4. Ouvrir `http://votre-serveur/kiosk_support/index.php` sur l'écran tactile.

## 3. Mode kiosque sur l'écran tactile

Sur le PC relié à l'écran Philips 242B9T, lancez le navigateur en plein écran verrouillé, par exemple avec Chrome :

```bash
chrome --kiosk --touch-events=enabled http://votre-serveur/kiosk_support/index.php
```

Le Philips 242B9T est un écran tactile 10 points USB (plug-and-play sous Windows/Linux) : aucune
calibration logicielle n'est en général nécessaire, il suffit de le déclarer comme écran principal
d'affichage du PC qui exécute le navigateur.

## 4. Fonctionnement

1. **Nouvelle demande (panneau gauche)** : l'utilisateur touche un champ, le clavier virtuel apparaît
   en bas d'écran, il saisit son nom, son numéro d'agent, son service, sélectionne le motif puis
   touche **VALIDER**. La demande est enregistrée avec l'heure exacte (`date_creation`).
2. **Liste des demandes en attente (panneau droit)** : rafraîchie automatiquement toutes les 4
   secondes. Chaque ligne affiche le nom, le n° agent, le service, le motif et l'heure d'arrivée.
3. **Clôture / satisfaction** : une fois son problème résolu, l'utilisateur revient sur la borne,
   repère sa ligne dans la liste et touche l'un des trois visages :
   - 🟢 vert = satisfait
   - 🟡 jaune = neutre
   - 🔴 rouge = insatisfait

   La demande passe alors en statut `cloture`, avec la date de clôture et le niveau de satisfaction
   enregistrés.

## 5. Structure des fichiers

```
kiosk_support/
├── schema.sql                 Création de la base de données
├── config.php                 Connexion PDO à MySQL
├── index.php                  Page principale (formulaire + liste + clavier)
├── stats.php                  Page de statistiques mensuelles
├── ad_test.php                Page de diagnostic de la connexion Active Directory
├── includes/
│   ├── functions.php          Fonction de clôture automatique (18h)
│   ├── ldap_auth.php          Recherche Active Directory (LDAP), repli automatique si indisponible
│   └── ldap_diagnostics.php   Diagnostic pas-à-pas de la connexion AD (pour ad_test.php)
├── cron/
│   └── auto_close.php         Script à lancer via cron à 18h (filet de sécurité)
├── api/
│   ├── submit_request.php     Enregistre une nouvelle demande
│   ├── get_pending.php        Retourne les demandes en attente (JSON) + déclenche la clôture auto
│   ├── close_request.php      Clôture une demande avec la satisfaction (manuelle)
│   ├── get_stats_periods.php  Liste des années/mois disponibles en base
│   ├── get_stats.php          Statistiques + détail des demandes pour une période
│   ├── lookup_agent.php       Recherche du nom d'un agent dans l'AD à partir de son numéro
│   └── ad_status.php          Indique si l'AD est joignable (pour l'ordre des champs)
└── assets/
    ├── css/style.css          Styles de la borne (mise en page compacte)
    ├── css/stats.css          Styles de la page de statistiques
    ├── js/app.js              Clavier virtuel, formulaire, liste de services, liste temps réel
    └── js/stats.js            Sélecteur de période, synthèse satisfaction, tableau triable
```

## 5bis. Nouveautés

### Clôture automatique à 18h00
Toute demande encore "en attente" est automatiquement clôturée dès 18h00 (statut `cloture`,
`type_cloture = 'automatique'`, `satisfaction` restant NULL car l'utilisateur n'a pas répondu).
Ceci est déclenché :
- automatiquement à chaque rafraîchissement de la liste (`api/get_pending.php`, toutes les 4s),
  tant que la borne reste allumée ;
- en filet de sécurité, via une tâche cron quotidienne à 18h00 :
  ```
  0 18 * * *  php /var/www/html/kiosk_support/cron/auto_close.php >> /var/log/kiosk_auto_close.log 2>&1
  ```

### Détail de la demande (facultatif)
À côté des trois choix de motif (Problème matériel / Problème logiciel / Autre), un bloc de texte
libre **"Détail (facultatif)"** permet à l'utilisateur de préciser sa demande, avec accès au
clavier virtuel. Le texte est enregistré dans la colonne `detail_demande` (1000 caractères max,
vide si non renseigné) et s'affiche dans le tableau de la page de statistiques, colonne
**Détail de la demande** (triable, texte tronqué avec info-bulle si trop long, "—" si vide).

### Motif d'insatisfaction
Lorsque l'utilisateur clôture une demande en touchant le visage 🟡 neutre ou 🔴 insatisfait (pas
🟢 satisfait), une popup s'ouvre pour lui permettre de préciser le motif, avec accès au clavier
virtuel :

- **Valider** : enregistre le commentaire saisi (ou aucun s'il est resté vide) et clôture la
  demande ;
- **Passer** : clôture la demande sans commentaire ;
- **Annuler la clôture** : referme la popup sans clôturer la demande, qui reste en attente.

Le commentaire est stocké dans la colonne `commentaire` (500 caractères max) et s'affiche dans le
tableau de la page de statistiques, colonne **Motif d'insatisfaction** (triable comme les autres
colonnes ; "—" si aucun commentaire n'a été saisi).

### Numéro d'agent
Le champ "Numéro d'agent" n'accepte que des chiffres, limité à 5 caractères (clavier virtuel et
validation côté formulaire).

### Champ Service
Le champ "Service" est désormais une liste déroulante avec recherche : l'utilisateur touche
le champ (placeholder "Rechercher un service..."), tape quelques lettres au clavier virtuel pour
filtrer, puis touche le service voulu dans la liste proposée :
Accueil, RH, Comptabilité, Prestations, Relation clients, Gestion du risque, Informatique,
Autre / non précisé.

### Mise en page compacte

### Connexion Active Directory (annuaire LDAP)
Le champ **Nom de l'utilisateur** peut être rempli automatiquement à partir de l'Active
Directory dès que l'utilisateur a saisi ses 5 chiffres de numéro d'agent. Le numéro d'agent
correspond aux **5 derniers caractères du nom d'utilisateur AD** (ex. `sAMAccountName` du type
`jdupont12345`) : la recherche se fait donc par **suffixe** (`(sAMAccountName=*12345)`), pas par
correspondance exacte.

**Ordre des champs** : dès le chargement de la page, la borne vérifie (`api/ad_status.php`) si la
connexion AD fonctionne (sans faire de recherche). Si c'est le cas, le champ **Numéro d'agent**
s'affiche en premier (avant le champ Nom), puisque c'est lui qui permet de retrouver le nom
automatiquement. Si l'AD est indisponible, l'ordre reste inchangé : Nom, puis Numéro d'agent, en
saisie manuelle.

**Résultats multiples** : si plusieurs comptes AD se terminent par le même numéro d'agent, celui
dont l'attribut agent (`agent_attribute`, ex. `sAMAccountName`) est **le plus court** est retenu
(les comptes plus longs ne correspondent en général que par coïncidence de suffixe). En cas
d'égalité de longueur entre plusieurs comptes, le résultat reste ambigu par sécurité, et
l'utilisateur passe en saisie manuelle.

- si l'AD est configuré, activé et joignable, et qu'un compte peut être retenu sans ambiguïté →
  le nom (`displayName` par défaut) s'affiche automatiquement, avec la mention
  "✓ Retrouvé automatiquement dans l'annuaire" ;
- si l'AD est configuré mais qu'aucun compte ne correspond, ou que plusieurs comptes de même
  longueur d'attribut sont trouvés → un message discret invite l'utilisateur à saisir son nom
  manuellement ;
- **si l'AD n'est pas configuré, ou injoignable pour n'importe quelle raison** (extension LDAP
  absente, mauvaise configuration, hôte injoignable, échec d'authentification...) → **le
  formulaire se comporte exactement comme avant, en saisie manuelle**, sans aucun message
  d'erreur affiché à l'utilisateur.

#### Configuration

1. Dans la table `ad_config` (une seule ligne, id=1), renseignez :

   ```sql
   UPDATE ad_config SET
       actif             = 1,
       host              = 'ad.mondomaine.local',
       port              = 389,           -- 636 si use_tls / LDAPS
       use_tls           = 0,
       base_dn           = 'DC=mondomaine,DC=local',
       bind_dn           = 'CN=svc-kiosk,OU=Comptes de service,DC=mondomaine,DC=local',
       bind_password_md5 = MD5('mot_de_passe_du_compte_de_service'),
       agent_attribute   = 'sAMAccountName',  -- attribut AD dont les 5 derniers caractères = numéro d'agent
       name_attribute    = 'displayName'      -- attribut AD contenant le nom complet
   WHERE id = 1;
   ```

2. Dans `config.php`, renseignez le mot de passe **en clair** du même compte de service dans la
   constante `AD_BIND_PASSWORD` :

   ```php
   define('AD_BIND_PASSWORD', 'mot_de_passe_du_compte_de_service');
   ```

   ⚠️ **Important** : un hash MD5 est à sens unique et ne peut pas servir à authentifier une
   connexion LDAP, qui nécessite le mot de passe en clair. La colonne `bind_password_md5` en base
   ne sert donc qu'à **vérifier la cohérence** entre la base et `config.php` (si les deux ne
   correspondent pas, la connexion AD est automatiquement désactivée par sécurité) — ce n'est pas
   elle qui authentifie la connexion. Le mot de passe réel doit rester dans `config.php`, à
   protéger comme n'importe quel secret (permissions fichier restrictives, fichier placé hors de
   la racine web si possible, `.gitignore`, etc.).

3. Vérifiez que l'extension PHP `ldap` est installée et activée (`php -m | grep ldap`).

4. Pour la production, il est recommandé d'activer `use_tls` (ou d'utiliser LDAPS sur le port
   636) afin que le mot de passe du compte de service ne transite jamais en clair sur le réseau.

#### Page de test (`ad_test.php`)

Une page de diagnostic dédiée permet de vérifier la connexion AD étape par étape (extension LDAP,
configuration en base, mot de passe, connexion réseau, TLS, bind, recherche) et de tester la
recherche d'un numéro d'agent en particulier, avec le filtre LDAP exact utilisé et les comptes
trouvés (DN, attribut agent, nom complet).

Accessible via `http://votre-serveur/kiosk_support/ad_test.php`, ou depuis le lien "🧪 Test AD"
en haut de la page de statistiques.

⚠️ Cette page affiche des informations de configuration technique (hôte, base DN, compte de
service...) — jamais le mot de passe. **Pensez à la protéger par une authentification, à en
restreindre l'accès réseau, ou à la supprimer** une fois la connexion AD validée en production.


Les espacements (marges, hauteurs de champs, clavier) ont été réduits afin que le formulaire
"Nouvelle demande" et le clavier virtuel soient tous les deux visibles simultanément à l'écran,
sans avoir à faire défiler la page.

### Page de statistiques mensuelles (`stats.php`)
Accessible via le petit lien discret "📊" en haut à droite de la borne, ou directement via
`http://votre-serveur/kiosk_support/stats.php`.

- Deux listes déroulantes **Année** / **Mois** en haut de page, peuplées automatiquement à partir
  des enregistrements réellement présents en base (`api/get_stats_periods.php`) : seules les
  périodes contenant des demandes apparaissent.
- 4 visages avec la proportion de chacun sur la période sélectionnée :
  🟢 satisfait, 🟡 neutre, 🔴 insatisfait, et ⚪ **non clôturée** (demandes encore en attente ou
  clôturées automatiquement à 18h sans réponse de satisfaction).
- Un tableau listant toutes les demandes de la période, avec les colonnes **Type de clôture**
  (manuelle / automatique / en attente), **Satisfaction**, **Date de création**,
  **Nom utilisateur**, **Service**, **Durée de l'intervention** (temps écoulé entre la création et
  la clôture, ex. "1h 25min" ; affichée uniquement pour les clôtures **manuelles** — "—" pour les
  demandes encore en attente et pour les clôtures **automatiques** à 18h, qui ne reflètent pas un
  temps de traitement réel) — chaque colonne est triable (ordre croissant/décroissant) en cliquant
  sur son en-tête.

Cette page utilise `api/get_stats.php?year=YYYY&month=MM` pour récupérer les données de la
période choisie.

## 6. Table `demandes`

| Colonne          | Description                                            |
|------------------|---------------------------------------------------------|
| id               | Identifiant unique                                       |
| nom_utilisateur  | Nom saisi par l'utilisateur                              |
| numero_agent     | Numéro d'agent                                           |
| service          | Service de l'utilisateur                                |
| motif            | `materiel`, `logiciel` ou `autre`                        |
| date_creation    | Horodatage de la demande                                 |
| date_cloture     | Horodatage de la clôture (NULL tant qu'en attente)       |
| satisfaction     | `satisfait`, `neutre`, `insatisfait` (NULL si en attente)|
| statut           | `en_attente` ou `cloture`                                |

Cette table permet ensuite de bâtir facilement un tableau de bord de statistiques
(nombre de demandes par service, taux de satisfaction, temps moyen de traitement, etc.).

## 7. Sécurité / prochaines étapes possibles

- Ajouter une authentification pour un éventuel écran d'administration/statistiques.
- Restreindre l'accès réseau à la borne (pare-feu / VLAN dédié).
- Ajouter une purge automatique (cron) des demandes clôturées anciennes si besoin d'archivage.

## Canal Zoom Room (`indexzoom.php` / `reponsesatisfaction.php`)

Pour les demandes d'intervention passant par le canal Zoom Room (au domicile ou au bureau de l'agent) :

1. **`indexzoom.php`** : même formulaire que `index.php`, sans la liste « Demandes en attente ».
   La demande est enregistrée sur le site **ZoomRoom** (créé automatiquement dans `sites`,
   `ip_prefix = 'zoomroom'`) avec `canal = 'zoomroom'`.
2. À la validation (`api/submit_request_zoom.php`), l'adresse e-mail du demandeur est récupérée dans
   l'AD (attribut `mail`, à partir du numéro d'agent) et un e-mail lui est envoyé avec un lien unique
   vers `reponsesatisfaction.php?token=...`. La page redirige ensuite vers l'URL renseignée dans
   `ad_config.lienzoomroom` (si elle est vide, un simple message de confirmation s'affiche).
3. **`reponsesatisfaction.php`** : le demandeur touche l'un des 3 visages, comme sur la borne
   (motif facultatif pour neutre / insatisfait). La demande est alors clôturée
   (`api/repondre_satisfaction.php`). Un lien ne peut servir qu'une fois.

Les demandes Zoom Room ne sont pas clôturées automatiquement à 17h mais après 7 jours sans réponse.

Configuration :
- exécuter les migrations « canal Zoom Room » en fin de `schema.sql` et renseigner `ad_config.lienzoomroom` ;
- dans `config.php`, ajouter `MAIL_FROM` (adresse d'expédition) et `APP_BASE_URL` (URL publique de
  l'application, recommandé) — voir `config.example.php` ;
- PHP doit pouvoir envoyer des e-mails via `mail()` (`SMTP`/`smtp_port` sous Windows, `sendmail_path` sous Linux).

#### Page de test (`test_mail.php`)

Vérifie pas à pas tout ce dont `indexzoom.php` a besoin : extensions PHP (`mbstring`, `ldap`), colonnes
de la base (avec la requête `ALTER TABLE` à exécuter si l'une manque), essai d'enregistrement d'une
demande (annulé aussitôt), `lienzoomroom`, `MAIL_FROM`, `APP_BASE_URL` et paramètres d'envoi de
`php.ini` (connexion au serveur SMTP sous Windows). Elle permet aussi de retrouver l'adresse e-mail
d'un agent dans l'AD et d'envoyer un e-mail de test. Accessible via le lien « ✉️ Test e-mail » de la
page Statistiques. À protéger ou supprimer après la mise en service, comme `ad_test.php`.
