<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Statistiques mensuelles - Support informatique</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="alternate icon" href="assets/img/favicon.ico">
<link rel="stylesheet" href="assets/css/stats.css">
</head>
<body>

<div class="stats-wrapper">

    <header class="stats-header">
        <h1>📊 Statistiques mensuelles</h1>
        <div class="header-links">
            <a href="confidentialite.php" class="btn-back">🔒 Confidentialité</a>
            <a href="ad_test.php" class="btn-back">🧪 Test AD</a>
            <a href="parametres.php" class="btn-back">⚙️ Paramètres</a>
            <a href="index.php" class="btn-back">← Retour à la borne</a>
        </div>
    </header>

    <div class="period-selector">
        <div class="period-field">
            <label for="select-site">Site</label>
            <select id="select-site"><option value="all">Tous les sites</option></select>
        </div>
        <div class="period-field">
            <label for="select-year">Année</label>
            <select id="select-year"></select>
        </div>
        <div class="period-field">
            <label for="select-month">Mois</label>
            <select id="select-month"></select>
        </div>
        <div class="period-total" id="period-total"></div>
    </div>

    <div id="no-data" class="no-data hidden">
        Aucune donnée disponible pour cette période.
    </div>

    <div id="stats-content">

        <div class="summary-cards" id="summary-cards"></div>

        <div class="table-card">
            <table class="stats-table" id="stats-table">
                <colgroup>
                    <col style="width: 11%">
                    <col style="width: 10%">
                    <col style="width: 12%">
                    <col style="width: 12%">
                    <col style="width: 10%">
                    <col style="width: 18%">
                    <col style="width: 9%">
                    <col style="width: 18%">
                </colgroup>
                <thead>
                    <tr>
                        <th data-key="type_cloture_label">Type de clôture <span class="sort-arrow"></span></th>
                        <th data-key="satisfaction">Satisfaction <span class="sort-arrow"></span></th>
                        <th data-key="date_creation">Date de création <span class="sort-arrow"></span></th>
                        <th data-key="nom_utilisateur">Nom utilisateur <span class="sort-arrow"></span></th>
                        <th data-key="service">Service <span class="sort-arrow"></span></th>
                        <th data-key="detail_demande">Détail de la demande <span class="sort-arrow"></span></th>
                        <th data-key="duree">Durée de l'intervention <span class="sort-arrow"></span></th>
                        <th data-key="commentaire">Motif d'insatisfaction <span class="sort-arrow"></span></th>
                    </tr>
                </thead>
                <tbody id="stats-table-body"></tbody>
            </table>
        </div>

    </div>

</div>

<script src="assets/js/stats.js"></script>
</body>
</html>
