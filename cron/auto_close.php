<?php
declare(strict_types=1);

/**
 * À exécuter via une tâche cron, par exemple tous les jours à 18h00 :
 *
 *   0 18 * * *  php /var/www/html/kiosk_support/cron/auto_close.php >> /var/log/kiosk_auto_close.log 2>&1
 *
 * L'auto-clôture est aussi déclenchée automatiquement à chaque rafraîchissement
 * de la liste des demandes (api/get_pending.php) tant que la borne est allumée ;
 * ce script sert de filet de sécurité si la borne était éteinte à 18h.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$nb = autoCloseOverdueRequests($pdo);

echo date('Y-m-d H:i:s') . " - {$nb} demande(s) clôturée(s) automatiquement." . PHP_EOL;
