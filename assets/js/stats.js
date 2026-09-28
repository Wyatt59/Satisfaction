(function () {
    'use strict';

    const MOIS_LABELS = [
        'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
        'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'
    ];

    const selectSite = document.getElementById('select-site');
    const selectYear = document.getElementById('select-year');
    const selectMonth = document.getElementById('select-month');
    const periodTotalEl = document.getElementById('period-total');
    const noDataEl = document.getElementById('no-data');
    const statsContentEl = document.getElementById('stats-content');
    const summaryCardsEl = document.getElementById('summary-cards');
    const tableBodyEl = document.getElementById('stats-table-body');
    const tableEl = document.getElementById('stats-table');

    let periodesParAnnee = {};   // { '2026': [1,2,3,...], ... }
    let currentDemandes = [];
    let currentSort = { key: 'date_creation', dir: 'asc' };
    let currentSiteId = 'all';

    // ============================================================
    // Icônes smiley (identiques à la borne + variante "blanche")
    // ============================================================
    function smileySVG(type) {
        const colors = {
            satisfait: '#1a9c63',
            neutre: '#e8b923',
            insatisfait: '#e0453f',
            non_cloturee: '#b7bfc9'
        };
        const color = colors[type] || '#999';

        let mouth = '';
        if (type === 'satisfait') {
            mouth = '<path d="M25 62 Q50 82 75 62" stroke="' + color + '" stroke-width="5" fill="none" stroke-linecap="round"/>';
        } else if (type === 'insatisfait') {
            mouth = '<path d="M25 75 Q50 55 75 75" stroke="' + color + '" stroke-width="5" fill="none" stroke-linecap="round"/>';
        } else {
            // neutre et non_cloturee : bouche neutre (ligne droite)
            mouth = '<line x1="28" y1="68" x2="72" y2="68" stroke="' + color + '" stroke-width="5" stroke-linecap="round"/>';
        }

        const eyes = type === 'non_cloturee'
            ? '<ellipse cx="34" cy="42" rx="6" ry="8" fill="none" stroke="' + color + '" stroke-width="3"/>' +
              '<ellipse cx="66" cy="42" rx="6" ry="8" fill="none" stroke="' + color + '" stroke-width="3"/>'
            : '<ellipse cx="34" cy="42" rx="6" ry="8" fill="' + color + '"/>' +
              '<ellipse cx="66" cy="42" rx="6" ry="8" fill="' + color + '"/>';

        return (
            '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">' +
            '<circle cx="50" cy="50" r="45" fill="#fff" stroke="' + color + '" stroke-width="6"/>' +
            eyes + mouth +
            '</svg>'
        );
    }

    function escapeHTML(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    // ============================================================
    // Chargement des sites disponibles (sélecteur "Tous les sites" / site)
    // ============================================================
    function loadSites() {
        fetch('api/get_sites.php')
            .then(r => r.json())
            .then(res => {
                let options = '<option value="all">Tous les sites</option>';
                if (res.success && res.sites && res.sites.length > 0) {
                    options += res.sites.map(s =>
                        '<option value="' + s.id + '">' + escapeHTML(s.nom_site) + '</option>'
                    ).join('');
                }
                selectSite.innerHTML = options;
            })
            .catch(() => {
                selectSite.innerHTML = '<option value="all">Tous les sites</option>';
            })
            .finally(loadPeriodes);
    }

    selectSite.addEventListener('change', () => {
        currentSiteId = selectSite.value;
        loadPeriodes();
    });

    // ============================================================
    // Chargement des périodes disponibles
    // ============================================================
    function loadPeriodes() {
        fetch('api/get_stats_periods.php?site_id=' + encodeURIComponent(currentSiteId))
            .then(r => r.json())
            .then(res => {
                if (!res.success || !res.periodes || res.periodes.length === 0) {
                    showNoData();
                    return;
                }

                periodesParAnnee = {};
                res.periodes.forEach(p => {
                    const annee = String(p.annee);
                    if (!periodesParAnnee[annee]) periodesParAnnee[annee] = [];
                    periodesParAnnee[annee].push(parseInt(p.mois, 10));
                });

                const annees = Object.keys(periodesParAnnee).sort((a, b) => b - a);
                selectYear.innerHTML = annees.map(a => '<option value="' + a + '">' + a + '</option>').join('');

                populateMonthsForYear(annees[0]);
                selectYear.value = annees[0];

                loadStats();
            })
            .catch(() => showNoData());
    }

    function populateMonthsForYear(annee) {
        const mois = (periodesParAnnee[annee] || []).slice().sort((a, b) => b - a);
        selectMonth.innerHTML = mois.map(m =>
            '<option value="' + m + '">' + MOIS_LABELS[m - 1] + '</option>'
        ).join('');
    }

    selectYear.addEventListener('change', () => {
        populateMonthsForYear(selectYear.value);
        loadStats();
    });
    selectMonth.addEventListener('change', loadStats);

    // ============================================================
    // Chargement des statistiques pour la période sélectionnée
    // ============================================================
    function loadStats() {
        const year = selectYear.value;
        const month = selectMonth.value;
        if (!year || !month) {
            showNoData();
            return;
        }

        fetch('api/get_stats.php?year=' + encodeURIComponent(year) + '&month=' + encodeURIComponent(month) +
              '&site_id=' + encodeURIComponent(currentSiteId))
            .then(r => r.json())
            .then(res => {
                if (!res.success || res.total === 0) {
                    showNoData();
                    periodTotalEl.textContent = '';
                    return;
                }

                statsContentEl.classList.remove('hidden');
                noDataEl.classList.add('hidden');

                periodTotalEl.innerHTML = '<strong>' + res.total + '</strong> demande(s) sur la période';

                renderSummary(res.counts, res.percentages, res.total);

                currentDemandes = res.demandes;
                applySortAndRender();
            })
            .catch(() => showNoData());
    }

    function showNoData() {
        statsContentEl.classList.add('hidden');
        noDataEl.classList.remove('hidden');
    }

    // ============================================================
    // Cartes de synthèse (visages + proportions)
    // ============================================================
    function renderSummary(counts, percentages, total) {
        const items = [
            { type: 'satisfait', label: 'Satisfait' },
            { type: 'neutre', label: 'Neutre' },
            { type: 'insatisfait', label: 'Insatisfait' },
            { type: 'non_cloturee', label: 'Non clôturée' }
        ];

        summaryCardsEl.innerHTML = items.map(item => {
            const pct = percentages[item.type] != null ? percentages[item.type] : 0;
            const count = counts[item.type] != null ? counts[item.type] : 0;
            return (
                '<div class="summary-card">' +
                    '<div class="summary-icon">' + smileySVG(item.type) + '</div>' +
                    '<div class="summary-pct">' + pct + ' %</div>' +
                    '<div class="summary-count">' + count + ' / ' + total + '</div>' +
                    '<div class="summary-label">' + item.label + '</div>' +
                '</div>'
            );
        }).join('');
    }

    // ============================================================
    // Tableau triable
    // ============================================================
    const satisfactionLabels = {
        satisfait: 'Satisfait',
        neutre: 'Neutre',
        insatisfait: 'Insatisfait'
    };

    function satisfactionSortValue(row) {
        // ordre logique pour le tri : insatisfait < neutre < satisfait < non clôturée
        const order = { insatisfait: 0, neutre: 1, satisfait: 2 };
        return row.satisfaction ? order[row.satisfaction] : 3;
    }

    function typeClotureSortValue(row) {
        if (row.statut === 'en_attente') return 0;
        return row.type_cloture === 'automatique' ? 1 : 2;
    }

    function durationMinutes(row) {
        if (!row.date_cloture) return null;
        if (row.type_cloture === 'automatique') return null;
        const start = new Date(row.date_creation.replace(' ', 'T'));
        const end = new Date(row.date_cloture.replace(' ', 'T'));
        if (isNaN(start.getTime()) || isNaN(end.getTime())) return null;
        return Math.max(0, Math.round((end.getTime() - start.getTime()) / 60000));
    }

    function formatDuration(minutes) {
        if (minutes === null) return '—';
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        if (h > 0) return h + 'h ' + m + 'min';
        return m + 'min';
    }

    function getSortValue(row, key) {
        switch (key) {
            case 'satisfaction': return satisfactionSortValue(row);
            case 'type_cloture_label': return typeClotureSortValue(row);
            case 'date_creation': return new Date(row.date_creation).getTime();
            case 'nom_utilisateur': return (row.nom_utilisateur || '').toLowerCase();
            case 'service': return (row.service || '').toLowerCase();
            case 'detail_demande': return (row.detail_demande || '').toLowerCase();
            case 'duree': {
                const d = durationMinutes(row);
                return d === null ? Infinity : d;
            }
            case 'commentaire': return (row.commentaire || '').toLowerCase();
            default: return '';
        }
    }

    function applySortAndRender() {
        const { key, dir } = currentSort;
        const sorted = currentDemandes.slice().sort((a, b) => {
            const va = getSortValue(a, key);
            const vb = getSortValue(b, key);
            if (va < vb) return dir === 'asc' ? -1 : 1;
            if (va > vb) return dir === 'asc' ? 1 : -1;
            return 0;
        });
        renderTable(sorted);
        updateSortArrows();
    }

    function renderTable(rows) {
        tableBodyEl.innerHTML = rows.map(row => {
            const badgeClass = row.statut === 'en_attente'
                ? 'badge-attente'
                : (row.type_cloture === 'automatique' ? 'badge-automatique' : 'badge-manuelle');

            const dateStr = formatDate(row.date_creation);
            const dureeStr = formatDuration(durationMinutes(row));

            const satHTML = row.satisfaction
                ? '<span class="sat-dot sat-' + row.satisfaction + '"></span>' + satisfactionLabels[row.satisfaction]
                : '<span class="sat-dot sat-none"></span>Non clôturée';

            return (
                '<tr>' +
                    '<td><span class="badge ' + badgeClass + '">' + escapeHTML(row.type_cloture_label) + '</span></td>' +
                    '<td><div class="sat-cell">' + satHTML + '</div></td>' +
                    '<td>' + dateStr + '</td>' +
                    '<td>' + escapeHTML(row.nom_utilisateur) + '</td>' +
                    '<td>' + escapeHTML(row.service) + '</td>' +
                    '<td class="comment-cell"' + (row.detail_demande ? ' title="' + escapeHTML(row.detail_demande) + '"' : '') + '>' +
                        (row.detail_demande ? escapeHTML(row.detail_demande) : '<span class="muted-cell">—</span>') +
                    '</td>' +
                    '<td>' + dureeStr + '</td>' +
                    '<td class="comment-cell"' + (row.commentaire ? ' title="' + escapeHTML(row.commentaire) + '"' : '') + '>' +
                        (row.commentaire ? escapeHTML(row.commentaire) : '<span class="muted-cell">—</span>') +
                    '</td>' +
                '</tr>'
            );
        }).join('');
    }

    function formatDate(isoLike) {
        const d = new Date(isoLike.replace(' ', 'T'));
        if (isNaN(d.getTime())) return escapeHTML(isoLike);
        const pad = n => String(n).padStart(2, '0');
        return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() +
               ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function updateSortArrows() {
        tableEl.querySelectorAll('thead th').forEach(th => {
            const arrow = th.querySelector('.sort-arrow');
            if (th.getAttribute('data-key') === currentSort.key) {
                arrow.textContent = currentSort.dir === 'asc' ? '▲' : '▼';
            } else {
                arrow.textContent = '';
            }
        });
    }

    tableEl.querySelectorAll('thead th').forEach(th => {
        th.addEventListener('click', () => {
            const key = th.getAttribute('data-key');
            if (currentSort.key === key) {
                currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort = { key: key, dir: 'asc' };
            }
            applySortAndRender();
        });
    });

    loadSites();
})();
