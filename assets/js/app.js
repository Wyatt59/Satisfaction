(function () {
    'use strict';

    // ============================================================
    // Génération des icônes smiley (SVG) - vert / jaune / rouge
    // ============================================================
    function smileySVG(type) {
        const colors = {
            satisfait: '#1a9c63',
            neutre: '#e8b923',
            insatisfait: '#e0453f'
        };
        const color = colors[type] || '#999';

        let mouth = '';
        if (type === 'satisfait') {
            mouth = '<path d="M25 62 Q50 82 75 62" stroke="' + color + '" stroke-width="5" fill="none" stroke-linecap="round"/>';
        } else if (type === 'neutre') {
            mouth = '<line x1="28" y1="68" x2="72" y2="68" stroke="' + color + '" stroke-width="5" stroke-linecap="round"/>';
        } else {
            mouth = '<path d="M25 75 Q50 55 75 75" stroke="' + color + '" stroke-width="5" fill="none" stroke-linecap="round"/>';
        }

        return (
            '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">' +
            '<circle cx="50" cy="50" r="45" fill="#fff" stroke="' + color + '" stroke-width="6"/>' +
            '<ellipse cx="34" cy="42" rx="6" ry="8" fill="' + color + '"/>' +
            '<ellipse cx="66" cy="42" rx="6" ry="8" fill="' + color + '"/>' +
            mouth +
            '</svg>'
        );
    }

    // ============================================================
    // Formulaire de nouvelle demande
    // ============================================================
    const nomInput = document.getElementById('nom_utilisateur');
    const agentInput = document.getElementById('numero_agent');
    const statsLinkEl = document.getElementById('stats-link');
    const serviceInput = document.getElementById('service');
    const serviceSuggestionsEl = document.getElementById('service-suggestions');
    const nomAdStatusEl = document.getElementById('nom-ad-status');
    const serviceAdStatusEl = document.getElementById('service-ad-status');
    const detailInput = document.getElementById('detail_demande');
    const commentInput = document.getElementById('comment_input');
    const btnValider = document.getElementById('btn-valider');
    const formMessage = document.getElementById('form-message');

    // Mode Zoom Room (indexzoom.php) : pas de liste des demandes en attente,
    // enregistrement via une API dédiée puis redirection éventuelle.
    const zoomMode = document.body.dataset.mode === 'zoom';

    // Clavier virtuel absent (indexzoom.php) : saisie au clavier physique.
    const keyboardEl = document.getElementById('virtual-keyboard');
    const submitUrl = zoomMode ? 'api/submit_request_zoom.php' : 'api/submit_request.php';

    // commentInput n'existe pas sur indexzoom.php (pas de clôture sur place)
    const textInputs = [nomInput, agentInput, serviceInput, detailInput, commentInput].filter(Boolean);
    let nomAutoFilled = false;
    let serviceAutoFilled = false;

    const SERVICES = [
        'Accueil',
        'RH',
        'Comptabilité',
        'Prestations',
        'Relation clients',
        'Gestion du risque',
        'Informatique',
        'Autre / non précisé'
    ];

    let activeInput = null;

    function setActiveInput(input) {
        textInputs.forEach(i => i.classList.remove('active-field'));
        activeInput = input;
        input.classList.add('active-field');
        const label = input.dataset.label || (input.previousElementSibling ? input.previousElementSibling.textContent : '');
        if (keyboardEl) {
            document.getElementById('kb-target-label').textContent = label;
            showKeyboard();
        }

        if (input === serviceInput) {
            renderServiceSuggestions(serviceInput.value);
        } else {
            hideServiceSuggestions();
        }
    }

    textInputs.forEach(input => {
        input.addEventListener('click', () => setActiveInput(input));
    });

    function renderServiceSuggestions(filterText) {
        const filter = (filterText || '').trim().toLowerCase();
        const matches = SERVICES.filter(s => s.toLowerCase().includes(filter));

        if (matches.length === 0) {
            serviceSuggestionsEl.innerHTML = '<div class="suggestion-empty">Aucun service trouvé</div>';
        } else {
            serviceSuggestionsEl.innerHTML = matches.map(s =>
                '<button type="button" class="suggestion-item">' + escapeHTML(s) + '</button>'
            ).join('');
        }
        serviceSuggestionsEl.classList.remove('hidden');
    }

    function hideServiceSuggestions() {
        serviceSuggestionsEl.classList.add('hidden');
    }

    serviceSuggestionsEl.addEventListener('click', (e) => {
        const item = e.target.closest('.suggestion-item');
        if (!item) return;
        serviceInput.value = item.textContent;
        hideServiceSuggestions();
    });

    function getSelectedMotif() {
        const checked = document.querySelector('input[name="motif"]:checked');
        return checked ? checked.value : '';
    }

    function resetForm() {
        textInputs.forEach(i => i.value = '');
        document.querySelector('input[name="motif"][value="materiel"]').checked = true;
        activeInput = null;
        textInputs.forEach(i => i.classList.remove('active-field'));
        hideKeyboard();
        hideServiceSuggestions();
        nomAutoFilled = false;
        serviceAutoFilled = false;
        setAdStatus('');
        setServiceAdStatus('');
    }

    function showFormMessage(text, type) {
        formMessage.textContent = text;
        formMessage.className = 'form-message ' + type;
        setTimeout(() => {
            formMessage.textContent = '';
            formMessage.className = 'form-message';
        }, 3500);
    }

    btnValider.addEventListener('click', () => {
        const payload = {
            nom_utilisateur: nomInput.value.trim(),
            numero_agent: agentInput.value.trim(),
            service: serviceInput.value.trim(),
            motif: getSelectedMotif(),
            detail: detailInput.value.trim()
        };

        if (!payload.nom_utilisateur || !payload.numero_agent || !payload.service) {
            showFormMessage('Veuillez remplir tous les champs avant de valider.', 'error');
            return;
        }

        if (!/^[0-9]{5}$/.test(payload.numero_agent)) {
            showFormMessage('Le numéro d\'agent doit comporter exactement 5 chiffres.', 'error');
            return;
        }

        btnValider.disabled = true;

        fetch(submitUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                // Réponse non JSON : erreur PHP côté serveur
                return { success: false, message: 'Erreur du serveur (HTTP ' + r.status + ')' +
                    (zoomMode ? ' : vérifiez la configuration sur test_mail.php.' : '.') };
            }
        }))
        .then(res => {
            if (res.success && zoomMode) {
                resetForm();
                if (res.redirect) {
                    window.location.href = res.redirect;
                    return;
                }
                showFormMessage(res.mail_envoye
                    ? 'Demande enregistrée. Un e-mail vous a été envoyé pour donner votre avis.'
                    : 'Demande enregistrée.', 'success');
            } else if (res.success) {
                showFormMessage('Demande enregistrée. Merci de revenir sur la borne après votre rendez-vous.', 'success');
                resetForm();
                loadPendingList();
            } else {
                showFormMessage(res.message || 'Erreur lors de l\'enregistrement.', 'error');
            }
            btnValider.disabled = false;
        })
        .catch(() => {
            btnValider.disabled = false;
            showFormMessage('Erreur de connexion au serveur.', 'error');
        });
    });

    // ============================================================
    // Liste des demandes en attente (polling)
    // ============================================================
    const pendingListEl = document.getElementById('pending-list');

    function renderPendingList(demandes) {
        if (!demandes || demandes.length === 0) {
            pendingListEl.innerHTML = '<div class="empty-state">Aucune demande en attente</div>';
            return;
        }

        pendingListEl.innerHTML = demandes.map(d => {
            const nom = escapeHTML(d.nom_utilisateur);
            const agent = escapeHTML(d.numero_agent);
            const service = escapeHTML(d.service);
            const motif = escapeHTML(d.motif_label);
            const heure = escapeHTML(d.heure);

            return (
                '<div class="demande-row" data-id="' + d.id + '">' +
                    '<div class="demande-infos">' +
                        '<div class="demande-nom">' + nom + ' <span style="color:#9aa4b0;font-weight:400;">(agent ' + agent + ')</span></div>' +
                        '<div class="demande-details">' + service + ' &middot; ' + motif + '</div>' +
                        '<div class="demande-heure">Arrivée : ' + heure + '</div>' +
                    '</div>' +
                    '<div class="demande-actions">' +
                        smileyButton(d.id, 'satisfait') +
                        smileyButton(d.id, 'neutre') +
                        smileyButton(d.id, 'insatisfait') +
                    '</div>' +
                '</div>'
            );
        }).join('');
    }

    function smileyButton(id, type) {
        return '<button type="button" class="smiley-btn" data-id="' + id + '" data-type="' + type + '">' +
               smileySVG(type) + '</button>';
    }

    function escapeHTML(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    let pendingClose = null; // { id, satisfaction } en attente de commentaire

    if (pendingListEl) pendingListEl.addEventListener('click', (e) => {
        const btn = e.target.closest('.smiley-btn');
        if (!btn) return;
        const id = btn.getAttribute('data-id');
        const type = btn.getAttribute('data-type');

        if (type === 'satisfait') {
            closeRequest(id, type, null);
        } else {
            openCommentModal(id, type);
        }
    });

    function onClick(id, handler) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', handler);
    }

    const commentOverlay = document.getElementById('comment-overlay');
    const commentIconEl = document.getElementById('comment-icon');

    function openCommentModal(id, satisfaction) {
        pendingClose = { id, satisfaction };
        commentInput.value = '';
        commentIconEl.innerHTML = smileySVG(satisfaction);
        commentOverlay.classList.remove('hidden');
        setActiveInput(commentInput);
    }

    function closeCommentModal() {
        commentOverlay.classList.add('hidden');
        hideKeyboard();
        pendingClose = null;
    }

    onClick('btn-comment-skip', () => {
        if (!pendingClose) return;
        const { id, satisfaction } = pendingClose;
        closeCommentModal();
        closeRequest(id, satisfaction, null);
    });

    onClick('btn-comment-submit', () => {
        if (!pendingClose) return;
        const { id, satisfaction } = pendingClose;
        const commentaire = commentInput.value.trim();
        closeCommentModal();
        closeRequest(id, satisfaction, commentaire || null);
    });

    onClick('btn-comment-cancel', () => {
        closeCommentModal();
    });

    function closeRequest(id, satisfaction, commentaire) {
        fetch('api/close_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, satisfaction: satisfaction, commentaire: commentaire || null })
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showCloseOverlay(satisfaction);
                loadPendingList();
            }
        });
    }

    const closeOverlay = document.getElementById('close-overlay');
    const closeOverlayIcon = document.getElementById('close-overlay-icon');

    function showCloseOverlay(type) {
        closeOverlayIcon.innerHTML = smileySVG(type);
        closeOverlay.classList.remove('hidden');
        setTimeout(() => closeOverlay.classList.add('hidden'), 1400);
    }

    function loadPendingList() {
        if (!pendingListEl) return;
        fetch('api/get_pending.php')
            .then(r => r.json())
            .then(res => {
                if (res.success) renderPendingList(res.demandes);
            })
            .catch(() => {});
    }

    if (pendingListEl) {
        loadPendingList();
        setInterval(loadPendingList, 4000);
    }

    // ============================================================
    // Clavier virtuel tactile (AZERTY)
    // ============================================================
    let shiftOn = false;

    const rowNumbers = ['1','2','3','4','5','6','7','8','9','0'];
    const row1 = ['a','z','e','r','t','y','u','i','o','p'];
    const row2 = ['q','s','d','f','g','h','j','k','l','m'];
    const row3 = ['w','x','c','v','b','n','-','\''];

    function buildKey(label, handler, extraClass) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'kb-key' + (extraClass ? ' ' + extraClass : '');
        btn.textContent = label;
        btn.addEventListener('click', handler);
        return btn;
    }

    function insertChar(ch) {
        if (!activeInput) return;

        if (activeInput === agentInput) {
            if (!/^[0-9]$/.test(ch)) return;
            if (agentInput.value.length >= 5) return;
        }

        activeInput.value += ch;
        refreshServiceSuggestionsIfNeeded();

        if (activeInput === agentInput) {
            handleAgentNumberChanged();
        }
    }

    function showStatsLink() {
        if (statsLinkEl) statsLinkEl.classList.remove('hidden');
    }

    function hideStatsLink() {
        if (statsLinkEl) statsLinkEl.classList.add('hidden');
    }

    function handleAgentNumberChanged() {
        if (nomAutoFilled) {
            nomInput.value = '';
            nomAutoFilled = false;
        }
        if (serviceAutoFilled) {
            serviceInput.value = '';
            serviceAutoFilled = false;
        }
        setServiceAdStatus('');
        setAdStatus('');
        // Repli fermé : dès que le numéro d'agent change, l'icône Statistiques
        // se masque tant que l'appartenance au groupe n'a pas été reconfirmée.
        hideStatsLink();

        // Numéro modifié : la recherche en cours (s'il y en a une) devient obsolète.
        adLookupSeq++;
        hideAdWait();

        if (agentInput.value.length === 5) {
            lookupAgent(agentInput.value);
        }
    }

    // ------------------------------------------------------------
    // Fenêtre d'attente pendant la recherche dans l'annuaire (AD)
    // ------------------------------------------------------------
    const adWaitOverlay = document.getElementById('ad-wait-overlay');
    const AD_WAIT_MIN_MS = 500;    // évite un simple flash si l'AD répond très vite
    const AD_WAIT_MAX_MS = 10000;  // au-delà : abandon et saisie manuelle
    let adLookupSeq = 0;           // identifie la recherche en cours
    let adDisponible = false;      // renseigné au chargement (api/ad_status.php)

    function showAdWait() {
        // AD désactivé ou injoignable : réponse immédiate, pas de fenêtre d'attente
        if (!adDisponible) return;
        if (adWaitOverlay) adWaitOverlay.classList.remove('hidden');
        btnValider.disabled = true;
    }

    function hideAdWait() {
        if (adWaitOverlay && !adWaitOverlay.classList.contains('hidden')) {
            adWaitOverlay.classList.add('hidden');
            btnValider.disabled = false;
        }
    }

    function setAdStatus(text, cssClass) {
        nomAdStatusEl.textContent = text;
        nomAdStatusEl.className = 'ad-status' + (cssClass ? ' ' + cssClass : '');
    }

    function setServiceAdStatus(text, cssClass) {
        serviceAdStatusEl.textContent = text;
        serviceAdStatusEl.className = 'ad-status' + (cssClass ? ' ' + cssClass : '');
    }

    // Si la connexion AD fonctionne, on affiche le champ "Numéro d'agent" en
    // premier (le nom sera ensuite rempli automatiquement à partir de celui-ci).
    // Si l'AD est indisponible, l'ordre reste inchangé (Nom, puis Numéro d'agent).
    function checkAdAvailabilityAndReorderFields() {
        fetch('api/ad_status.php')
            .then(r => r.json())
            .then(res => {
                if (res.success && res.available) {
                    adDisponible = true;
                    const fieldAgent = document.getElementById('field-agent');
                    const fieldNom = document.getElementById('field-nom');
                    if (fieldAgent && fieldNom && fieldAgent.parentNode) {
                        fieldAgent.parentNode.insertBefore(fieldAgent, fieldNom);
                    }
                }
            })
            .catch(() => {});
    }
    checkAdAvailabilityAndReorderFields();

    function lookupAgent(numeroAgent) {
        const seq = adLookupSeq;
        const debut = Date.now();
        const controller = window.AbortController ? new AbortController() : null;
        const abandon = setTimeout(() => { if (controller) controller.abort(); }, AD_WAIT_MAX_MS);

        showAdWait();

        fetch('api/lookup_agent.php?numero_agent=' + encodeURIComponent(numeroAgent),
              controller ? { signal: controller.signal } : {})
            .then(r => r.json())
            .then(res => {
                // numéro d'agent modifié entre-temps : on ignore la réponse obsolète
                if (seq !== adLookupSeq || agentInput.value !== numeroAgent) return;

                if (!res.success || !res.available) {
                    // AD indisponible : le formulaire reste en saisie manuelle, comme d'habitude
                    setAdStatus('');
                    return;
                }

                if (res.found && res.nom) {
                    nomInput.value = res.nom;
                    nomAutoFilled = true;
                    setAdStatus('✓ Retrouvé automatiquement dans l\'annuaire', 'found');
                } else {
                    setAdStatus('Agent non trouvé dans l\'annuaire, merci de saisir votre nom', 'not-found');
                }

                // Service : préremplissage automatique uniquement si l'AD renvoie une
                // valeur exploitable (voir includes/ldap_auth.php). Si res.service est
                // vide/null, on laisse le champ tel quel (comportement manuel actuel).
                if (res.service) {
                    serviceInput.value = res.service;
                    serviceAutoFilled = true;
                    setServiceAdStatus('✓ Rempli automatiquement depuis l\'annuaire', 'found');
                } else {
                    setServiceAdStatus('');
                }

                // Icône Statistiques : ne s'affiche que si l'agent retrouvé est
                // membre du groupe AD configuré (repli fermé géré côté serveur).
                if (res.afficher_stats) {
                    showStatsLink();
                }
            })
            .catch(() => {
                // AD trop lent (abandon après AD_WAIT_MAX_MS) ou erreur : saisie manuelle
                if (seq === adLookupSeq) setAdStatus('');
            })
            .finally(() => {
                clearTimeout(abandon);
                const reste = Math.max(0, AD_WAIT_MIN_MS - (Date.now() - debut));
                setTimeout(() => {
                    if (seq === adLookupSeq) hideAdWait();
                }, reste);
            });
    }

    function refreshServiceSuggestionsIfNeeded() {
        if (activeInput === serviceInput) {
            renderServiceSuggestions(serviceInput.value);
        }
    }

    function buildLetterRow(container, letters) {
        container.innerHTML = '';
        letters.forEach(letter => {
            const display = shiftOn ? letter.toUpperCase() : letter;
            container.appendChild(buildKey(display, () => insertChar(display)));
        });
    }

    function renderKeyboard() {
        const numContainer = document.getElementById('kb-row-numbers');
        numContainer.innerHTML = '';
        rowNumbers.forEach(n => numContainer.appendChild(buildKey(n, () => insertChar(n))));

        buildLetterRow(document.getElementById('kb-row-1'), row1);
        buildLetterRow(document.getElementById('kb-row-2'), row2);

        const row3Container = document.getElementById('kb-row-3');
        row3Container.innerHTML = '';
        row3Container.appendChild(buildKey('⇧', toggleShift, 'action' + (shiftOn ? ' primary' : '')));
        row3.forEach(letter => {
            const display = shiftOn ? letter.toUpperCase() : letter;
            row3Container.appendChild(buildKey(display, () => insertChar(display)));
        });
        row3Container.appendChild(buildKey('⌫', backspace, 'action danger'));

        const actionsRow = document.getElementById('kb-row-actions');
        actionsRow.innerHTML = '';
        actionsRow.appendChild(buildKey('Effacer', clearField, 'action danger'));
        actionsRow.appendChild(buildKey('Espace', () => insertChar(' '), 'action wide'));
        actionsRow.appendChild(buildKey('Fermer', hideKeyboard, 'action primary'));
    }

    function toggleShift() {
        shiftOn = !shiftOn;
        renderKeyboard();
    }

    function backspace() {
        if (!activeInput) return;
        activeInput.value = activeInput.value.slice(0, -1);
        refreshServiceSuggestionsIfNeeded();
        if (activeInput === agentInput) handleAgentNumberChanged();
    }

    function clearField() {
        if (!activeInput) return;
        activeInput.value = '';
        refreshServiceSuggestionsIfNeeded();
        if (activeInput === agentInput) handleAgentNumberChanged();
    }

    function showKeyboard() {
        if (keyboardEl) keyboardEl.classList.remove('hidden');
    }

    function hideKeyboard() {
        if (keyboardEl) keyboardEl.classList.add('hidden');
    }

    if (keyboardEl) {
        renderKeyboard();
    } else {
        // Saisie au clavier physique : mêmes règles que le clavier virtuel
        // (numéro d'agent limité à 5 chiffres, recherche AD, suggestions de service).
        textInputs.forEach(input => {
            input.addEventListener('focus', () => setActiveInput(input));
        });

        agentInput.addEventListener('input', () => {
            const chiffres = agentInput.value.replace(/[^0-9]/g, '').slice(0, 5);
            if (chiffres !== agentInput.value) agentInput.value = chiffres;
            handleAgentNumberChanged();
        });

        serviceInput.addEventListener('input', refreshServiceSuggestionsIfNeeded);
    }
})();
