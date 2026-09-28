(function () {
    'use strict';

    // ============================================================
    // Génération des icônes smiley (SVG) - identiques à la borne (app.js)
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

    const questionEl = document.getElementById('reponse-question');
    const merciEl = document.getElementById('reponse-merci');
    const erreurEl = document.getElementById('reponse-erreur');
    const commentOverlay = document.getElementById('comment-overlay');
    const commentIconEl = document.getElementById('comment-icon');
    const commentInput = document.getElementById('comment_input');
    const token = questionEl.dataset.token;

    let pendingSatisfaction = null;
    let envoiEnCours = false;

    questionEl.querySelectorAll('.smiley-btn').forEach(btn => {
        btn.innerHTML = smileySVG(btn.dataset.type);
    });

    // Même comportement que la borne : "satisfait" clôture immédiatement,
    // "neutre" / "insatisfait" proposent de préciser le motif (facultatif).
    questionEl.addEventListener('click', (e) => {
        const btn = e.target.closest('.smiley-btn');
        if (!btn) return;
        const type = btn.dataset.type;

        if (type === 'satisfait') {
            envoyerReponse(type, null);
        } else {
            openCommentModal(type);
        }
    });

    function openCommentModal(satisfaction) {
        pendingSatisfaction = satisfaction;
        commentInput.value = '';
        commentIconEl.innerHTML = smileySVG(satisfaction);
        commentOverlay.classList.remove('hidden');
        commentInput.focus();
    }

    function closeCommentModal() {
        commentOverlay.classList.add('hidden');
        pendingSatisfaction = null;
    }

    document.getElementById('btn-comment-skip').addEventListener('click', () => {
        if (!pendingSatisfaction) return;
        const satisfaction = pendingSatisfaction;
        closeCommentModal();
        envoyerReponse(satisfaction, null);
    });

    document.getElementById('btn-comment-submit').addEventListener('click', () => {
        if (!pendingSatisfaction) return;
        const satisfaction = pendingSatisfaction;
        const commentaire = commentInput.value.trim();
        closeCommentModal();
        envoyerReponse(satisfaction, commentaire || null);
    });

    document.getElementById('btn-comment-cancel').addEventListener('click', closeCommentModal);

    commentInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') document.getElementById('btn-comment-submit').click();
    });

    function afficherErreur(text) {
        erreurEl.textContent = text;
        erreurEl.className = 'form-message error';
    }

    function envoyerReponse(satisfaction, commentaire) {
        if (envoiEnCours) return;
        envoiEnCours = true;

        fetch('api/repondre_satisfaction.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: token, satisfaction: satisfaction, commentaire: commentaire })
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                document.getElementById('reponse-merci-icon').innerHTML = smileySVG(satisfaction);
                questionEl.classList.add('hidden');
                merciEl.classList.remove('hidden');
            } else {
                envoiEnCours = false;
                afficherErreur(res.message || 'Erreur lors de l\'enregistrement de votre avis.');
            }
        })
        .catch(() => {
            envoiEnCours = false;
            afficherErreur('Erreur de connexion au serveur.');
        });
    }
})();
