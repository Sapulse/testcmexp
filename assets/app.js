/* ============================================================
 * Synthèse comptable — calculs dynamiques côté client
 *
 * Toutes les fonctions évitent les valeurs interdites :
 *  - jamais NaN, undefined, null, #DIV/0!, #VALEUR!
 *  - une référence vide ou égale à 0 affiche "Non calculable"
 *
 * Pour Dolibarr : ce script reste compatible, il pourra être
 * inclus via $conf->modules_parts['js'].
 * ============================================================ */

(function () {
    'use strict';

    // ---------- Helpers numériques ---------------------------------------

    /** Convertit une valeur en nombre fini ; renvoie 0 si vide/invalide. */
    function num(value) {
        if (value === null || value === undefined || value === '') return 0;
        const n = Number(String(value).replace(',', '.'));
        return Number.isFinite(n) ? n : 0;
    }

    /** Indique si une valeur de saisie est "vide" (ne peut servir de référence). */
    function isEmpty(value) {
        if (value === null || value === undefined) return true;
        const s = String(value).trim();
        return s === '' || !Number.isFinite(Number(s.replace(',', '.')));
    }

    /** Formate un nombre pour l'affichage (2 décimales si non entier). */
    function fmt(n, decimals) {
        if (!Number.isFinite(n)) return '0';
        const d = decimals === undefined ? 2 : decimals;
        if (Number.isInteger(n) && d === 2) return n.toLocaleString('fr-FR');
        return n.toLocaleString('fr-FR', {
            minimumFractionDigits: d,
            maximumFractionDigits: d,
        });
    }

    /**
     * Pourcentage d'évolution sécurisé.
     * Renvoie une chaîne déjà prête à afficher.
     */
    function safePercent(reference, valeur) {
        if (isEmpty(reference) || num(reference) === 0) return 'Non calculable';
        const pct = ((num(valeur) - num(reference)) / num(reference)) * 100;
        if (!Number.isFinite(pct)) return 'Non calculable';
        return fmt(pct, 1) + ' %';
    }

    /** Récupère un input par sélecteur, utilisable comme référence. */
    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

    // ---------- Bloc 6 : Revue analytique --------------------------------
    // Pour chaque période, l'évolution se calcule par rapport à la période
    // précédente sur la ligne CA. Pour la première période : "Non calculable".
    function recalcRevue() {
        for (let i = 0; i < 10; i++) {
            const cur = $('input[data-revue="ca"][data-index="' + i + '"]');
            const out = $('output[data-revue-evolution="' + i + '"]');
            if (!out) continue;
            if (i === 0) { out.textContent = '—'; continue; }
            const prev = $('input[data-revue="ca"][data-index="' + (i - 1) + '"]');
            if (!cur || !prev) { out.textContent = '—'; continue; }
            if (isEmpty(prev.value) && isEmpty(cur.value)) { out.textContent = '—'; continue; }
            out.textContent = safePercent(prev.value, cur.value);
        }
    }

    // ---------- Bloc 7 : Prévisionnel ------------------------------------
    function recalcPrevisionnel() {
        $$('output[data-prev-ecart]').forEach(out => {
            const key = out.dataset.prevEcart;
            const prev = $('input[data-prev="' + key + '"][data-col="prev"]');
            const reel = $('input[data-prev="' + key + '"][data-col="reel"]');
            const ecart = num(reel && reel.value) - num(prev && prev.value);
            out.textContent = fmt(ecart);
        });
    }

    // ---------- Bloc 8 : Aides -------------------------------------------
    function recalcAides() {
        const total = $$('input[data-aide]').reduce((s, el) => s + num(el.value), 0);
        const out = $('#aides-total');
        if (out) out.textContent = fmt(total);
    }

    // ---------- Bloc 10 : CA réalisé en N+1 ------------------------------
    function recalcCaN1() {
        $$('output[data-can1-variation]').forEach(out => {
            const idx = out.dataset.can1Variation;
            const realise = $('input[data-can1="realise"][data-row="' + idx + '"]');
            const reference = $('input[data-can1="reference"][data-row="' + idx + '"]');
            if (!realise || !reference) { out.textContent = '—'; return; }
            if (isEmpty(realise.value) && isEmpty(reference.value)) {
                out.textContent = '—';
                return;
            }
            out.textContent = safePercent(reference.value, realise.value);
        });
    }

    // ---------- Bloc 13 : Masse salariale --------------------------------
    function recalcMasseSalariale() {
        ['n', 'n1'].forEach(col => {
            const sb = num(($('input[data-ms="salaires_bruts"][data-col="' + col + '"]') || {}).value);
            const cs = num(($('input[data-ms="charges_sociales"][data-col="' + col + '"]') || {}).value);
            const it = num(($('input[data-ms="interim"][data-col="' + col + '"]') || {}).value);
            const ca = ($('input[data-ms="ca_ref"][data-col="' + col + '"]') || {}).value;
            const total = sb + cs + it;
            const totalOut = $('output[data-ms-total="' + col + '"]');
            if (totalOut) totalOut.textContent = fmt(total);
            const ratioOut = $('output[data-ms-ratio="' + col + '"]');
            if (!ratioOut) return;
            if (isEmpty(ca) || num(ca) === 0) {
                ratioOut.textContent = 'Non calculable';
            } else {
                ratioOut.textContent = fmt((total / num(ca)) * 100, 2) + ' %';
            }
        });
    }

    // ---------- Bloc 19 : Investissement / emprunt -----------------------
    function recalcInvestissement() {
        const inv = num(($('#invest-nouveaux') || {}).value);
        const emp = num(($('#invest-emprunt') || {}).value);
        const ecart = inv - emp;
        const ecartOut = $('#invest-ecart');
        if (ecartOut) ecartOut.textContent = fmt(ecart);
        const natOut = $('#invest-nature-auto');
        if (natOut) {
            if (inv === 0 && emp === 0) {
                natOut.textContent = '—';
            } else if (ecart > 0) {
                natOut.textContent = 'Autofinancement (partie non couverte par l’emprunt)';
            } else if (ecart === 0) {
                natOut.textContent = 'Financé (emprunt = investissement)';
            } else {
                natOut.textContent = 'Financé (emprunt > investissement)';
            }
        }
    }

    // ---------- Bloc 23 : Calcul de la CAF -------------------------------
    function recalcCaf() {
        const get = (k) => num(($('input[data-caf="' + k + '"]') || {}).value);
        const resultatAvantIs = get('resultat_avant_is');
        const is = get('is');
        const resultatNet = resultatAvantIs - is;
        const dotations = get('dotations');
        const vnc = get('vnc_675');
        const reprises = get('reprises');
        const cession = get('cession_775');
        const qpSubv = get('qp_subventions');
        const empruntCap = get('emprunt_capital_n');

        const caf = resultatNet + dotations + vnc - reprises - cession - qpSubv;
        const ecart = caf - empruntCap;

        const rn = $('#caf-resultat-net');
        const tot = $('#caf-total');
        const ec = $('#caf-ecart');
        if (rn) rn.textContent = fmt(resultatNet);
        if (tot) tot.textContent = fmt(caf);
        if (ec) ec.textContent = fmt(ecart);
    }

    // ---------- Recalcul global ------------------------------------------
    function recalcAll() {
        recalcRevue();
        recalcPrevisionnel();
        recalcAides();
        recalcCaN1();
        recalcMasseSalariale();
        recalcInvestissement();
        recalcCaf();
    }

    // ---------- Câblage des évènements -----------------------------------

    document.addEventListener('input', (e) => {
        const t = e.target;
        if (!t || t.tagName !== 'INPUT') return;
        // On limite les recalculs aux blocs touchés pour rester rapide.
        if (t.matches('[data-revue]')) recalcRevue();
        else if (t.matches('[data-prev]')) recalcPrevisionnel();
        else if (t.matches('[data-aide]')) recalcAides();
        else if (t.matches('[data-can1]')) recalcCaN1();
        else if (t.matches('[data-ms]')) recalcMasseSalariale();
        else if (t.matches('[data-caf]')) recalcCaf();
        else if (t.id === 'invest-nouveaux' || t.id === 'invest-emprunt') recalcInvestissement();
    });

    // Bouton Imprimer
    const btnPrint = document.getElementById('btn-print');
    if (btnPrint) btnPrint.addEventListener('click', () => window.print());

    // Bouton Réinitialiser : on relance le calcul après reset.
    const form = document.getElementById('synthese-form');
    if (form) {
        form.addEventListener('reset', () => setTimeout(recalcAll, 0));
    }

    // Calcul initial au chargement (sur les valeurs préremplies depuis PHP).
    document.addEventListener('DOMContentLoaded', recalcAll);
    // Au cas où le script se charge après le DOMContentLoaded :
    if (document.readyState !== 'loading') recalcAll();
})();
