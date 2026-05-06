<?php
/**
 * Prototype : Synthèse comptable (transformation onglet Excel -> page web PHP)
 *
 * --- INTÉGRATION DOLIBARR (notes pour la suite) ---
 *
 * Ce fichier est volontairement autonome pour ce prototype. Pour devenir un
 * module Dolibarr, il faudra :
 *
 * 1. Créer un module dans htdocs/custom/cmexp/ avec :
 *      - core/modules/modCmexp.class.php   (déclaration du module)
 *      - admin/setup.php                   (page d'admin)
 *      - sql/llx_cmexp_synthese.sql        (table dédiée)
 *      - sql/llx_cmexp_synthese.key.sql    (index/clés)
 *      - class/synthese.class.php          (objet métier hérité de CommonObject)
 *      - synthese_card.php                 (équivalent de ce index.php)
 *      - synthese_list.php                 (liste des dossiers)
 *
 * 2. Remplacer ici :
 *      - la lecture/écriture JSON par un objet Dolibarr (CommonObject::fetch / create / update)
 *      - le rattachement à un tiers via $object->fk_soc (table llx_societe)
 *      - les droits via $user->rights->cmexp->synthese->lire / creer / supprimer
 *      - le menu via une entrée dans modCmexp::$menu
 *      - l'inclusion de main.inc.php pour récupérer $db, $user, $langs, $conf
 *      - llxHeader() / llxFooter() au lieu du <html> brut
 *
 * 3. La table SQL dédiée pourra reprendre la structure JSON ci-dessous,
 *    avec un champ rowid, fk_soc, fk_user_creat, datec, tms, et les blocs
 *    métier (sérialisés JSON dans un TEXT, ou colonnes typées si besoin de filtrer).
 *
 * Pour ce prototype : stockage simple dans data/synthese.json.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// 1. CONFIGURATION ET CHEMINS
// -----------------------------------------------------------------------------

const DATA_FILE = __DIR__ . '/data/synthese.json';

// -----------------------------------------------------------------------------
// 2. UTILITAIRES MÉTIER
// -----------------------------------------------------------------------------

/**
 * Retourne une valeur sûre depuis un tableau imbriqué (évite les warnings).
 * En Dolibarr cette fonction sera remplacée par GETPOST() / propriétés objet.
 */
function syntheseGet(array $data, string $key, $default = ''): mixed
{
    return $data[$key] ?? $default;
}

/**
 * Charge la dernière synthèse sauvegardée (ou un squelette vide).
 * En Dolibarr : $object->fetch($id) sur la table llx_cmexp_synthese.
 */
function syntheseLoad(): array
{
    if (!is_file(DATA_FILE)) {
        return syntheseEmpty();
    }
    $raw = file_get_contents(DATA_FILE);
    if ($raw === false || $raw === '') {
        return syntheseEmpty();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return syntheseEmpty();
    }
    return array_merge(syntheseEmpty(), $decoded);
}

/**
 * Sauvegarde la synthèse. En Dolibarr : $object->update($user) avec les droits.
 */
function syntheseSave(array $data): bool
{
    if (!is_dir(dirname(DATA_FILE))) {
        @mkdir(dirname(DATA_FILE), 0775, true);
    }
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return $payload !== false && file_put_contents(DATA_FILE, $payload) !== false;
}

/**
 * Squelette d'une synthèse vide (sert à l'init et à fusionner les nouvelles clés).
 */
function syntheseEmpty(): array
{
    return [
        // En-tête dossier
        'entreprise' => '',
        'numero_dossier' => '',
        'cycle' => '',
        'date_cloture' => '',
        'denomination_note' => '',
        'derniere_maj' => date('Y-m-d'),

        // Données principales (matrices N à N-5)
        'donnees_principales' => [
            'resultat_comptable' => array_fill(0, 6, ''),
            'resultat_fiscal' => array_fill(0, 6, ''),
            'ca_ht' => array_fill(0, 6, ''),
            'taux_marge' => array_fill(0, 6, ''),
            'investissements' => array_fill(0, 6, ''),
            'emprunts' => array_fill(0, 6, ''),
        ],

        // Suivi administratif
        'plaquette_finalisee' => '',
        'cloture_effectuee' => '',
        'juridique_signe' => '',

        // Notes
        'notes_a_reporter' => '',
        'note_synthese_n1' => '',

        // Note de synthèse
        'faits_significatifs' => '',
        'points_attention_ec' => '',
        'documents_a_signer' => '',

        // Revue analytique : 4 lignes x 10 périodes
        'revue_analytique' => [
            'ca' => array_fill(0, 10, ''),
            'marge' => array_fill(0, 10, ''),
            'ecart' => array_fill(0, 10, ''),
            'evolution' => array_fill(0, 10, ''),
        ],

        // Prévisionnel
        'previsionnel' => [
            'ca' => ['prev' => '', 'reel' => ''],
            'marge' => ['prev' => '', 'reel' => ''],
            'taux' => ['prev' => '', 'reel' => ''],
            'cf' => ['prev' => '', 'reel' => ''],
            'impots' => ['prev' => '', 'reel' => ''],
            'ms' => ['prev' => '', 'reel' => ''],
        ],

        // Aides reçues
        'aides' => [
            'indemnite_assurance' => '',
            'chomage_partiel' => '',
            'autre_aide_1' => '',
            'autre_aide_2' => '',
        ],

        // CA / marge
        'variation_ca' => '',
        'variation_marge_points' => '',
        'variation_marge_valeur' => '',
        'cause_volume' => false,
        'cause_prix' => false,
        'cause_destockage' => false,
        'cause_typologie' => false,
        'cause_duree' => false,
        'cause_autre' => false,
        'commentaire_ca_marge' => '',

        // CA N+1 (3 mois)
        'ca_n1' => [
            ['mois' => 'Janvier N+1', 'realise' => '', 'reference' => '', 'commentaire' => ''],
            ['mois' => 'Février N+1', 'realise' => '', 'reference' => '', 'commentaire' => ''],
            ['mois' => 'Mars N+1', 'realise' => '', 'reference' => '', 'commentaire' => ''],
        ],

        // Perspectives
        'perspectives_n1' => '',

        // Charges externes
        'honoraires_impayes' => '',
        'fin_bail' => '',
        'energie_hausse' => '',
        'aide_disponible' => '',
        'hausse_proportionnelle' => '',
        'transfert_charge' => '',
        'location_6mois_cout_annuel' => '',
        'commentaire_charges' => '',

        // Masse salariale
        'masse_salariale' => [
            'salaires_bruts' => ['n' => '', 'n1' => ''],
            'charges_sociales' => ['n' => '', 'n1' => ''],
            'interim' => ['n' => '', 'n1' => ''],
            'ca_ref' => ['n' => '', 'n1' => ''],
        ],

        // Dirigeant
        'fin_chomage_dirigeant' => '',
        'remuneration_souhaitee' => '',
        'cout_remuneration' => '',
        'commentaire_dirigeant' => '',

        // TNS
        'tns_regularisation' => '',
        'tns_impact_n1' => '',
        'tns_sante' => '',
        'tns_prevoyance' => '',
        'tns_retraite' => '',
        'tns_commentaire' => '',

        // Impôt
        'impot_montant' => '',
        'impot_commentaire' => '',

        // Divers
        'divers' => '',
        'points_marquants' => '',

        // Investissement / emprunt
        'invest_nouveaux' => '',
        'invest_emprunt' => '',
        'invest_nature' => '',

        // Fournisseurs / clients
        'fournisseurs_clients' => '',

        // Trésorerie
        'tresorerie_baisse' => '',
        'tresorerie_parts' => '',
        'tresorerie_cat' => '',
        'tresorerie_commentaire' => '',

        // Stocks
        'stocks' => '',

        // CAF
        'caf' => [
            'resultat_avant_is' => '',
            'is' => '',
            'dotations' => '',
            'vnc_675' => '',
            'reprises' => '',
            'cession_775' => '',
            'qp_subventions' => '',
            'emprunt_capital_n' => '',
        ],

        // CA supplémentaire
        'ca_suppl_montant' => '',
        'ca_suppl_commentaire' => '',
    ];
}

// -----------------------------------------------------------------------------
// 3. TRAITEMENT POST (Enregistrement)
// -----------------------------------------------------------------------------
//
// En Dolibarr, on remplacera ce bloc par :
//   if ($action === 'add' || $action === 'update') {
//       if ($user->rights->cmexp->synthese->creer) { $object->update($user); }
//   }
//
$flashMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $payload = $_POST;
    unset($payload['action']);
    // Force la date de mise à jour
    $payload['derniere_maj'] = date('Y-m-d H:i');
    // Cases à cocher : présentes uniquement si cochées
    foreach (['cause_volume', 'cause_prix', 'cause_destockage', 'cause_typologie', 'cause_duree', 'cause_autre'] as $chk) {
        $payload[$chk] = isset($_POST[$chk]);
    }
    if (syntheseSave($payload)) {
        $flashMessage = 'Synthèse enregistrée avec succès.';
    } else {
        $flashMessage = 'Erreur : enregistrement impossible.';
    }
}

// Données à afficher
$data = syntheseLoad();
// Si on vient de POST, afficher les valeurs envoyées (sinon les valeurs disque)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $data = array_merge($data, $_POST);
}

/**
 * Helper d'affichage : échappe une valeur, remplace null par chaîne vide.
 */
function h($value): string
{
    if ($value === null || $value === false) {
        return '';
    }
    if (is_array($value)) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Récupère une valeur dans une structure imbriquée pour les name="a[b][c]".
 */
function v(array $data, string ...$path): string
{
    $current = $data;
    foreach ($path as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return '';
        }
        $current = $current[$key];
    }
    if (is_array($current)) {
        return '';
    }
    return h($current);
}

$annees = ['N', 'N-1', 'N-2', 'N-3', 'N-4', 'N-5'];
$periodes = range(1, 10);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Synthèse comptable — Prototype</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<!--
    En Dolibarr, le wrapper <body>...<main> sera remplacé par :
        llxHeader('', $title, '', '', 0, 0, [...], [...]);
        print dol_get_fiche_head($head, 'synthese', $title, -1, $object->picto);
    et fermé par :
        print dol_get_fiche_end();
        llxFooter();
-->
<main class="page">

    <header class="page-header">
        <h1>Note de contrôle — SYNTHÈSE</h1>
        <p class="subtitle">Prototype web inspiré de l'onglet Excel « Synthèse »</p>
    </header>

    <?php if ($flashMessage !== ''): ?>
        <div class="flash"><?= h($flashMessage) ?></div>
    <?php endif; ?>

    <form method="post" action="" id="synthese-form" autocomplete="off">
        <input type="hidden" name="action" value="save">

        <!-- ======================================================= -->
        <!-- 1. EN-TÊTE DOSSIER                                      -->
        <!-- ======================================================= -->
        <!-- Dolibarr : ces champs deviendront un objet Synthese rattaché à fk_soc (tiers). -->
        <section class="card card-header-dossier">
            <h2>1. En-tête dossier</h2>
            <div class="grid grid-3">
                <label>Entreprise
                    <input type="text" name="entreprise" value="<?= v($data, 'entreprise') ?>">
                </label>
                <label>N° de dossier
                    <input type="text" name="numero_dossier" value="<?= v($data, 'numero_dossier') ?>">
                </label>
                <label>Cycle
                    <input type="text" name="cycle" value="<?= v($data, 'cycle') ?>">
                </label>
                <label>Date de clôture
                    <input type="date" name="date_cloture" value="<?= v($data, 'date_cloture') ?>">
                </label>
                <label>Dénomination de la note de contrôle
                    <input type="text" name="denomination_note" value="<?= v($data, 'denomination_note') ?>">
                </label>
                <label>Dernière mise à jour le
                    <input type="text" name="derniere_maj" value="<?= v($data, 'derniere_maj') ?>" readonly>
                </label>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 2. DONNÉES PRINCIPALES                                  -->
        <!-- ======================================================= -->
        <section class="card card-bleu">
            <h2>2. Données principales</h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Indicateur</th>
                        <?php foreach ($annees as $a): ?>
                            <th><?= h($a) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $ligneDp = [
                        'resultat_comptable' => 'Résultat comptable',
                        'resultat_fiscal' => 'Résultat fiscal',
                        'ca_ht' => 'Chiffre d’affaires HT',
                        'taux_marge' => 'Taux de marge brute (%)',
                        'investissements' => 'Investissements réalisés',
                        'emprunts' => 'Emprunts souscrits',
                    ];
                    foreach ($ligneDp as $key => $libelle): ?>
                        <tr>
                            <th scope="row"><?= h($libelle) ?></th>
                            <?php for ($i = 0; $i < 6; $i++): ?>
                                <td>
                                    <input type="number" step="any"
                                           name="donnees_principales[<?= $key ?>][<?= $i ?>]"
                                           value="<?= v($data, 'donnees_principales', $key, (string)$i) ?>">
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 3. SUIVI ADMINISTRATIF                                  -->
        <!-- ======================================================= -->
        <section class="card card-beige">
            <h2>3. Suivi administratif</h2>
            <div class="grid grid-3">
                <label>Dernière plaquette finalisée
                    <input type="date" name="plaquette_finalisee" value="<?= v($data, 'plaquette_finalisee') ?>">
                </label>
                <label>Dernière clôture effectuée
                    <input type="date" name="cloture_effectuee" value="<?= v($data, 'cloture_effectuee') ?>">
                </label>
                <label>Dernier juridique signé
                    <input type="date" name="juridique_signe" value="<?= v($data, 'juridique_signe') ?>">
                </label>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 4. NOTES À REPORTER                                     -->
        <!-- ======================================================= -->
        <section class="card">
            <h2>4. Notes à reporter</h2>
            <div class="grid grid-2">
                <label>Notes à reporter
                    <textarea name="notes_a_reporter" rows="5"><?= v($data, 'notes_a_reporter') ?></textarea>
                </label>
                <label>Note de synthèse N-1
                    <textarea name="note_synthese_n1" rows="5"><?= v($data, 'note_synthese_n1') ?></textarea>
                </label>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 5. NOTE DE SYNTHÈSE                                     -->
        <!-- ======================================================= -->
        <section class="card card-bleu">
            <h2>5. Note de synthèse</h2>
            <label>Faits significatifs de l’exercice
                <textarea name="faits_significatifs" rows="6"><?= v($data, 'faits_significatifs') ?></textarea>
            </label>
            <label>Points d’attention pour l’Expert-Comptable
                <textarea name="points_attention_ec" rows="6"><?= v($data, 'points_attention_ec') ?></textarea>
            </label>
            <label>Documents à faire signer
                <textarea name="documents_a_signer" rows="4"><?= v($data, 'documents_a_signer') ?></textarea>
            </label>
        </section>

        <!-- ======================================================= -->
        <!-- 6. REVUE ANALYTIQUE                                     -->
        <!-- ======================================================= -->
        <section class="card card-beige">
            <h2>6. Revue analytique</h2>
            <p class="hint">Le pourcentage d’évolution est calculé automatiquement. Si la référence est 0, la mention « Non calculable » s’affiche.</p>
            <div class="table-wrap">
                <table class="data-table" id="table-revue">
                    <thead>
                    <tr>
                        <th>Indicateur</th>
                        <?php foreach ($periodes as $p): ?>
                            <th>P<?= $p ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $ligneRevue = [
                        'ca' => 'Chiffre d’affaires',
                        'marge' => 'Marge',
                        'ecart' => 'Écart',
                        'evolution' => '% d’évolution',
                    ];
                    foreach ($ligneRevue as $key => $libelle):
                        $isAuto = ($key === 'evolution');
                        ?>
                        <tr<?= $isAuto ? ' class="row-calc"' : '' ?>>
                            <th scope="row"><?= h($libelle) ?></th>
                            <?php for ($i = 0; $i < 10; $i++): ?>
                                <td>
                                    <?php if ($isAuto): ?>
                                        <output data-revue-evolution="<?= $i ?>">—</output>
                                    <?php else: ?>
                                        <input type="number" step="any"
                                               data-revue="<?= $key ?>"
                                               data-index="<?= $i ?>"
                                               name="revue_analytique[<?= $key ?>][<?= $i ?>]"
                                               value="<?= v($data, 'revue_analytique', $key, (string)$i) ?>">
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 7. PRÉVISIONNEL                                         -->
        <!-- ======================================================= -->
        <section class="card card-bleu">
            <h2>7. Prévisionnel</h2>
            <div class="table-wrap">
                <table class="data-table" id="table-previsionnel">
                    <thead>
                    <tr>
                        <th>Indicateur</th>
                        <th>Prévisionnel</th>
                        <th>Réel</th>
                        <th>Écart</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $lignesPrev = [
                        'ca' => 'CA',
                        'marge' => 'Marge',
                        'taux' => 'Taux',
                        'cf' => 'CF',
                        'impots' => 'Impôts',
                        'ms' => 'MS',
                    ];
                    foreach ($lignesPrev as $key => $libelle): ?>
                        <tr>
                            <th scope="row"><?= h($libelle) ?></th>
                            <td>
                                <input type="number" step="any"
                                       data-prev="<?= $key ?>" data-col="prev"
                                       name="previsionnel[<?= $key ?>][prev]"
                                       value="<?= v($data, 'previsionnel', $key, 'prev') ?>">
                            </td>
                            <td>
                                <input type="number" step="any"
                                       data-prev="<?= $key ?>" data-col="reel"
                                       name="previsionnel[<?= $key ?>][reel]"
                                       value="<?= v($data, 'previsionnel', $key, 'reel') ?>">
                            </td>
                            <td><output data-prev-ecart="<?= $key ?>">0</output></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 8. AIDES RÉCEPTIONNÉES                                  -->
        <!-- ======================================================= -->
        <section class="card card-beige">
            <h2>8. Aides réceptionnées</h2>
            <div class="table-wrap">
                <table class="data-table" id="table-aides">
                    <tbody>
                    <?php
                    $aides = [
                        'indemnite_assurance' => 'Indemnité assurance',
                        'chomage_partiel' => 'Chômage partiel',
                        'autre_aide_1' => 'Autre aide 1',
                        'autre_aide_2' => 'Autre aide 2',
                    ];
                    foreach ($aides as $key => $libelle): ?>
                        <tr>
                            <th scope="row"><?= h($libelle) ?></th>
                            <td>
                                <input type="number" step="any"
                                       data-aide
                                       name="aides[<?= $key ?>]"
                                       value="<?= v($data, 'aides', $key) ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="row-calc">
                        <th scope="row">Total</th>
                        <td><output id="aides-total">0</output></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 9. CHIFFRE D’AFFAIRES / MARGE                           -->
        <!-- ======================================================= -->
        <section class="card card-bleu">
            <h2>9. Chiffre d’affaires / marge</h2>
            <div class="grid grid-3">
                <label>Hausse / baisse du CA de
                    <input type="text" name="variation_ca" value="<?= v($data, 'variation_ca') ?>">
                </label>
                <label>Hausse / baisse de la marge en points de
                    <input type="text" name="variation_marge_points" value="<?= v($data, 'variation_marge_points') ?>">
                </label>
                <label>Hausse / baisse de la marge en valeur de
                    <input type="text" name="variation_marge_valeur" value="<?= v($data, 'variation_marge_valeur') ?>">
                </label>
            </div>
            <fieldset class="checkboxes">
                <legend>Causes</legend>
                <?php
                $causes = [
                    'cause_volume' => 'Hausse / baisse du volume',
                    'cause_prix' => 'Hausse / baisse des prix',
                    'cause_destockage' => 'Liquidation, solde, déstockage',
                    'cause_typologie' => 'Nouvelle typologie de client',
                    'cause_duree' => 'Exercice plus court ou plus long',
                    'cause_autre' => 'Autre cause',
                ];
                foreach ($causes as $key => $libelle):
                    $checked = !empty($data[$key]) ? ' checked' : ''; ?>
                    <label class="checkbox">
                        <input type="checkbox" name="<?= $key ?>" value="1"<?= $checked ?>>
                        <?= h($libelle) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <label>Commentaire
                <textarea name="commentaire_ca_marge" rows="4"><?= v($data, 'commentaire_ca_marge') ?></textarea>
            </label>
        </section>

        <!-- ======================================================= -->
        <!-- 10. CA RÉALISÉ EN N+1                                   -->
        <!-- ======================================================= -->
        <section class="card">
            <h2>10. Chiffre d’affaires réalisé en N+1</h2>
            <div class="table-wrap">
                <table class="data-table" id="table-ca-n1">
                    <thead>
                    <tr>
                        <th>Mois</th>
                        <th>CA réalisé</th>
                        <th>CA de référence (N)</th>
                        <th>Variation vs N</th>
                        <th>Commentaire</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($data['ca_n1'] ?? []) as $idx => $row): ?>
                        <tr>
                            <td>
                                <input type="text" name="ca_n1[<?= $idx ?>][mois]"
                                       value="<?= h($row['mois'] ?? '') ?>">
                            </td>
                            <td>
                                <input type="number" step="any"
                                       data-can1="realise" data-row="<?= $idx ?>"
                                       name="ca_n1[<?= $idx ?>][realise]"
                                       value="<?= h($row['realise'] ?? '') ?>">
                            </td>
                            <td>
                                <input type="number" step="any"
                                       data-can1="reference" data-row="<?= $idx ?>"
                                       name="ca_n1[<?= $idx ?>][reference]"
                                       value="<?= h($row['reference'] ?? '') ?>">
                            </td>
                            <td><output data-can1-variation="<?= $idx ?>">—</output></td>
                            <td>
                                <input type="text" name="ca_n1[<?= $idx ?>][commentaire]"
                                       value="<?= h($row['commentaire'] ?? '') ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 11. PERSPECTIVES N+1                                    -->
        <!-- ======================================================= -->
        <section class="card card-bleu">
            <h2>11. Perspectives N+1</h2>
            <textarea name="perspectives_n1" rows="6"><?= v($data, 'perspectives_n1') ?></textarea>
        </section>

        <!-- ======================================================= -->
        <!-- 12. CHARGES EXTERNES                                    -->
        <!-- ======================================================= -->
        <section class="card card-beige">
            <h2>12. Charges externes</h2>
            <div class="grid grid-2">
                <label>Solde des honoraires impayés
                    <input type="number" step="any" name="honoraires_impayes" value="<?= v($data, 'honoraires_impayes') ?>">
                </label>
                <label>Fin du bail
                    <input type="date" name="fin_bail" value="<?= v($data, 'fin_bail') ?>">
                </label>
                <label>Énergie : concerné par la hausse à venir ?
                    <input type="text" name="energie_hausse" value="<?= v($data, 'energie_hausse') ?>">
                </label>
                <label>Aide disponible ?
                    <input type="text" name="aide_disponible" value="<?= v($data, 'aide_disponible') ?>">
                </label>
                <label>Hausse proportionnelle au CA ?
                    <input type="text" name="hausse_proportionnelle" value="<?= v($data, 'hausse_proportionnelle') ?>">
                </label>
                <label>Charges compensées par transfert de charge ?
                    <input type="text" name="transfert_charge" value="<?= v($data, 'transfert_charge') ?>">
                </label>
                <label>Location sur 6 mois : coût sur 1 année
                    <input type="number" step="any" name="location_6mois_cout_annuel" value="<?= v($data, 'location_6mois_cout_annuel') ?>">
                </label>
            </div>
            <label>Commentaire général
                <textarea name="commentaire_charges" rows="4"><?= v($data, 'commentaire_charges') ?></textarea>
            </label>
        </section>

        <!-- ======================================================= -->
        <!-- 13. MASSE SALARIALE                                     -->
        <!-- ======================================================= -->
        <section class="card">
            <h2>13. Masse salariale</h2>
            <div class="table-wrap">
                <table class="data-table" id="table-ms">
                    <thead>
                    <tr><th>Poste</th><th>N</th><th>N-1</th></tr>
                    </thead>
                    <tbody>
                    <?php
                    $msLignes = [
                        'salaires_bruts' => 'Salaires bruts',
                        'charges_sociales' => 'Charges sociales',
                        'interim' => 'Intérim',
                    ];
                    foreach ($msLignes as $key => $libelle): ?>
                        <tr>
                            <th scope="row"><?= h($libelle) ?></th>
                            <td><input type="number" step="any" data-ms="<?= $key ?>" data-col="n"
                                       name="masse_salariale[<?= $key ?>][n]"
                                       value="<?= v($data, 'masse_salariale', $key, 'n') ?>"></td>
                            <td><input type="number" step="any" data-ms="<?= $key ?>" data-col="n1"
                                       name="masse_salariale[<?= $key ?>][n1]"
                                       value="<?= v($data, 'masse_salariale', $key, 'n1') ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row">CA de référence</th>
                        <td><input type="number" step="any" data-ms="ca_ref" data-col="n"
                                   name="masse_salariale[ca_ref][n]"
                                   value="<?= v($data, 'masse_salariale', 'ca_ref', 'n') ?>"></td>
                        <td><input type="number" step="any" data-ms="ca_ref" data-col="n1"
                                   name="masse_salariale[ca_ref][n1]"
                                   value="<?= v($data, 'masse_salariale', 'ca_ref', 'n1') ?>"></td>
                    </tr>
                    <tr class="row-calc">
                        <th scope="row">Total</th>
                        <td><output data-ms-total="n">0</output></td>
                        <td><output data-ms-total="n1">0</output></td>
                    </tr>
                    <tr class="row-calc">
                        <th scope="row">% MS / CA</th>
                        <td><output data-ms-ratio="n">—</output></td>
                        <td><output data-ms-ratio="n1">—</output></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 14. DIRIGEANT                                           -->
        <!-- ======================================================= -->
        <section class="card card-bleu">
            <h2>14. Dirigeant</h2>
            <div class="grid grid-3">
                <label>Date de fin de chômage du dirigeant
                    <input type="date" name="fin_chomage_dirigeant" value="<?= v($data, 'fin_chomage_dirigeant') ?>">
                </label>
                <label>Rémunération souhaitée nette
                    <input type="number" step="any" name="remuneration_souhaitee" value="<?= v($data, 'remuneration_souhaitee') ?>">
                </label>
                <label>Coût de cette rémunération pour l’entreprise
                    <input type="number" step="any" name="cout_remuneration" value="<?= v($data, 'cout_remuneration') ?>">
                </label>
            </div>
            <label>Commentaire
                <textarea name="commentaire_dirigeant" rows="4"><?= v($data, 'commentaire_dirigeant') ?></textarea>
            </label>
        </section>

        <!-- ======================================================= -->
        <!-- 15. TNS                                                 -->
        <!-- ======================================================= -->
        <section class="card card-beige">
            <h2>15. TNS</h2>
            <div class="grid grid-2">
                <label>Régularisation des cotisations obligatoires à prévoir
                    <input type="number" step="any" name="tns_regularisation" value="<?= v($data, 'tns_regularisation') ?>">
                </label>
                <label>Impact régularisation N-1 sur le résultat
                    <input type="number" step="any" name="tns_impact_n1" value="<?= v($data, 'tns_impact_n1') ?>">
                </label>
                <label>Cotisations facultatives santé
                    <input type="number" step="any" name="tns_sante" value="<?= v($data, 'tns_sante') ?>">
                </label>
                <label>Cotisations facultatives prévoyance
                    <input type="number" step="any" name="tns_prevoyance" value="<?= v($data, 'tns_prevoyance') ?>">
                </label>
                <label>Cotisations facultatives retraite
                    <input type="number" step="any" name="tns_retraite" value="<?= v($data, 'tns_retraite') ?>">
                </label>
            </div>
            <label>Commentaire
                <textarea name="tns_commentaire" rows="4"><?= v($data, 'tns_commentaire') ?></textarea>
            </label>
        </section>

        <!-- ======================================================= -->
        <!-- 16. IMPÔT                                               -->
        <!-- ======================================================= -->
        <section class="card">
            <h2>16. Impôt</h2>
            <div class="grid grid-2">
                <label>Montant estimé de l’impôt
                    <input type="number" step="any" name="impot_montant" value="<?= v($data, 'impot_montant') ?>">
                </label>
            </div>
            <label>Commentaire
                <textarea name="impot_commentaire" rows="4"><?= v($data, 'impot_commentaire') ?></textarea>
            </label>
        </section>

        <!-- ======================================================= -->
        <!-- 17. DIVERS                                              -->
        <!-- ======================================================= -->
        <section class="card">
            <h2>17. Divers</h2>
            <textarea name="divers" rows="5"><?= v($data, 'divers') ?></textarea>
        </section>

        <!-- ======================================================= -->
        <!-- 18. POINTS IMPORTANTS / MARQUANTS                       -->
        <!-- ======================================================= -->
        <section class="card card-bleu">
            <h2>18. Points importants / marquants de l’exercice</h2>
            <textarea name="points_marquants" rows="8"><?= v($data, 'points_marquants') ?></textarea>
        </section>

        <!-- ======================================================= -->
        <!-- 19. INVESTISSEMENT / EMPRUNT                            -->
        <!-- ======================================================= -->
        <section class="card card-beige">
            <h2>19. Investissement / emprunt</h2>
            <div class="grid grid-3">
                <label>Nouveaux investissements
                    <input type="number" step="any" id="invest-nouveaux"
                           name="invest_nouveaux" value="<?= v($data, 'invest_nouveaux') ?>">
                </label>
                <label>Emprunt
                    <input type="number" step="any" id="invest-emprunt"
                           name="invest_emprunt" value="<?= v($data, 'invest_emprunt') ?>">
                </label>
                <label>Écart
                    <output id="invest-ecart">0</output>
                </label>
            </div>
            <p>Nature du financement :
                <output id="invest-nature-auto" class="auto-info">—</output>
            </p>
            <label>Précision sur la nature du financement
                <input type="text" name="invest_nature" value="<?= v($data, 'invest_nature') ?>">
            </label>
        </section>

        <!-- ======================================================= -->
        <!-- 20. FOURNISSEURS / CLIENTS                              -->
        <!-- ======================================================= -->
        <section class="card">
            <h2>20. Fournisseurs / clients</h2>
            <textarea name="fournisseurs_clients" rows="5"><?= v($data, 'fournisseurs_clients') ?></textarea>
        </section>

        <!-- ======================================================= -->
        <!-- 21. TRÉSORERIE                                          -->
        <!-- ======================================================= -->
        <section class="card card-bleu">
            <h2>21. Trésorerie</h2>
            <div class="grid grid-3">
                <label>Baisse de la trésorerie
                    <input type="number" step="any" name="tresorerie_baisse" value="<?= v($data, 'tresorerie_baisse') ?>">
                </label>
                <label>Achat de parts sociales
                    <input type="number" step="any" name="tresorerie_parts" value="<?= v($data, 'tresorerie_parts') ?>">
                </label>
                <label>Placement sur CAT
                    <input type="number" step="any" name="tresorerie_cat" value="<?= v($data, 'tresorerie_cat') ?>">
                </label>
            </div>
            <label>Commentaire
                <textarea name="tresorerie_commentaire" rows="4"><?= v($data, 'tresorerie_commentaire') ?></textarea>
            </label>
        </section>

        <!-- ======================================================= -->
        <!-- 22. STOCKS                                              -->
        <!-- ======================================================= -->
        <section class="card">
            <h2>22. Stocks</h2>
            <textarea name="stocks" rows="5"><?= v($data, 'stocks') ?></textarea>
        </section>

        <!-- ======================================================= -->
        <!-- 23. CALCUL DE LA CAF                                    -->
        <!-- ======================================================= -->
        <section class="card card-beige">
            <h2>23. Calcul de la CAF</h2>
            <div class="table-wrap">
                <table class="data-table" id="table-caf">
                    <tbody>
                    <?php
                    $cafSaisie = [
                        'resultat_avant_is' => 'Résultat avant IS',
                        'is' => 'IS',
                        'dotations' => 'Dotations',
                        'vnc_675' => 'VNC 675',
                        'reprises' => 'Reprises',
                        'cession_775' => 'Cession 775',
                        'qp_subventions' => 'Quote-part subventions',
                        'emprunt_capital_n' => 'Emprunt capital de N',
                    ];
                    foreach ($cafSaisie as $key => $libelle): ?>
                        <tr>
                            <th scope="row"><?= h($libelle) ?></th>
                            <td>
                                <input type="number" step="any"
                                       data-caf="<?= $key ?>"
                                       name="caf[<?= $key ?>]"
                                       value="<?= v($data, 'caf', $key) ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="row-calc">
                        <th scope="row">Résultat net (= Résultat avant IS − IS)</th>
                        <td><output id="caf-resultat-net">0</output></td>
                    </tr>
                    <tr class="row-calc">
                        <th scope="row">CAF</th>
                        <td><output id="caf-total">0</output></td>
                    </tr>
                    <tr class="row-calc">
                        <th scope="row">Écart (CAF − Emprunt capital de N)</th>
                        <td><output id="caf-ecart">0</output></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ======================================================= -->
        <!-- 24. CA SUPPLÉMENTAIRE À FAIRE                           -->
        <!-- ======================================================= -->
        <section class="card card-bleu">
            <h2>24. CA supplémentaire à faire</h2>
            <div class="grid grid-2">
                <label>Montant de CA supplémentaire à faire
                    <input type="number" step="any" name="ca_suppl_montant" value="<?= v($data, 'ca_suppl_montant') ?>">
                </label>
            </div>
            <label>Commentaire
                <textarea name="ca_suppl_commentaire" rows="4"><?= v($data, 'ca_suppl_commentaire') ?></textarea>
            </label>
            <p class="warning">
                Attention : si le chiffre d’affaires augmente, certaines charges peuvent également
                augmenter proportionnellement.
            </p>
        </section>

        <!-- ======================================================= -->
        <!-- BARRE D'ACTIONS                                         -->
        <!-- ======================================================= -->
        <!-- En Dolibarr : remplacer par les boutons standards <a class="butAction"> et le contrôle des droits utilisateur. -->
        <div class="actions no-print">
            <button type="submit" class="btn btn-primary">Enregistrer</button>
            <button type="reset" class="btn btn-secondary" id="btn-reset">Réinitialiser</button>
            <button type="button" class="btn btn-secondary" id="btn-print">Imprimer</button>
        </div>
    </form>

</main>

<script src="assets/app.js"></script>
</body>
</html>
