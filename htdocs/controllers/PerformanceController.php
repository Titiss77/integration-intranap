<?php

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/PerformanceModel.php';

class PerformanceController
{
    /**
     * Retourne la saison sportive actuelle.
     *
     * Septembre 2026 -> 2026-2027
     * Janvier 2027   -> 2026-2027
     */
    private function getCurrentSeason()
    {
        $annee = (int)date('Y');
        $mois = (int)date('n');

        $anneeDebut =
            $mois >= 9
                ? $annee
                : $annee - 1;

        return
            $anneeDebut .
            '-' .
            ($anneeDebut + 1);
    }

    /**
     * Calcule les positions temporaires par :
     *
     * - épreuve
     * - catégorie
     *
     * La position n'est jamais enregistrée en BDD.
     *
     * Une égalité de temps donne la même position.
     *
     * Exemple :
     *
     * 00:20.00 -> 1
     * 00:21.00 -> 2
     * 00:21.00 -> 2
     * 00:22.00 -> 4
     */
    private function calculateQualificationPositions(
        $lignes_bdd
    ) {
        $groupes = [];

        foreach (
            $lignes_bdd as $ligne
        ) {

            $categorie =
                $ligne['categorie'];

            $epreuve =
                $ligne['epreuve'];

            $key =
                $categorie .
                '|' .
                $epreuve;

            if (
                !isset(
                    $groupes[$key]
                )
            ) {
                $groupes[$key] = [];
            }

            $groupes[$key][] = $ligne;
        }

        $positions = [];

        foreach (
            $groupes as $key => $nageurs
        ) {

            usort(
                $nageurs,
                function ($a, $b) {

                    $tempsA =
                        $this->timeToSeconds(
                            $a['temps']
                        );

                    $tempsB =
                        $this->timeToSeconds(
                            $b['temps']
                        );

                    if (
                        $tempsA ===
                        $tempsB
                    ) {
                        return 0;
                    }

                    return
                        $tempsA <=>
                        $tempsB;
                }
            );

            $position = 0;
            $temps_precedent = null;

            foreach (
                $nageurs as $index => $nageur
            ) {

                $temps_actuel =
                    $this->timeToSeconds(
                        $nageur['temps']
                    );

                /*
                 * Même temps = même position.
                 */
                if (
                    $temps_precedent === null ||
                    $temps_actuel !==
                    $temps_precedent
                ) {

                    $position =
                        $index + 1;
                }

                $positions[
                    $nageur['nageur_id'] .
                    '|' .
                    $nageur['epreuve']
                ] = $position;

                $temps_precedent =
                    $temps_actuel;
            }
        }

        return $positions;
    }

    /**
     * Détermine si une performance est qualifiante.
     *
     * Règle :
     *
     * 1. Si un temps de référence ou une position existe,
     *    l'un ou l'autre critère suffit.
     *
     * 3. Sinon :
     *    pas de qualification définie.
     */
    private function isQualified(
        $categorie,
        $epreuve,
        $temps,
        $position,
        $grille_qualifs
    ) {

        if (
            !isset(
                $grille_qualifs[
                    $categorie
                ][
                    $epreuve
                ]
            )
        ) {
            return null;
        }

        $regle =
            $grille_qualifs[
                $categorie
            ][
                $epreuve
            ];

        $a_temps_de_ref =
            $regle['temps_de_ref'] !== null &&
            $regle['temps_de_ref'] !== '';

        $a_position =
            $regle['position'] !== null &&
            $regle['position'] > 0;

        if (!$a_temps_de_ref && !$a_position) {
            return null;
        }

        $qualifie_au_temps =
            $a_temps_de_ref &&
            $this->timeToSeconds($temps) <=
                $this->timeToSeconds($regle['temps_de_ref']);

        $qualifie_a_la_position =
            $a_position &&
            $position !== null &&
            $position <= (int)$regle['position'];

        return $qualifie_au_temps || $qualifie_a_la_position;
    }

    public function index()
    {
        $pdo = Database::getConnection();

        $model =
            new PerformanceModel($pdo);

        $saisons_disponibles =
            $model->getSaisons();

        $saison_param = $_GET['saison'] ?? 'all';
        $saison_selectionnee = is_string($saison_param) ? $saison_param : 'all';
        if ($saison_selectionnee !== 'all' &&
            !in_array($saison_selectionnee, $saisons_disponibles, true)) {
            $saison_selectionnee = 'all';
        }

        $mode_affichage = ($_GET['affichage'] ?? 'meilleures') === 'toutes'
            ? 'toutes'
            : 'meilleures';

        $lignes_bdd =
            $model->getPerformances(
                $saison_selectionnee,
                $mode_affichage
            );

        $grille_qualifs =
            $model->getGrilleQualifs(
                $saison_selectionnee === 'all'
                    ? $this->getCurrentSeason()
                    : $saison_selectionnee
            );

        /*
         * ------------------------------------------------------------
         * POSITIONS TEMPORAIRES
         * ------------------------------------------------------------
         *
         * Elles servent uniquement aux qualifications.
         *
         * Aucun classement n'est sauvegardé.
         */
        $lignes_pour_qualification = $mode_affichage === 'toutes'
            ? $model->getPerformances($saison_selectionnee, 'meilleures')
            : $lignes_bdd;

        $positions_qualification =
            $this->calculateQualificationPositions(
                $lignes_pour_qualification
            );

        $categories_actuelles = [];

        if (
            'all' ===
            $saison_selectionnee
        ) {

            $categories_actuelles =
                $model->getCategoriesActuelles();
        }

        $profils_nageurs = [];
        $epreuves_trouvees = [];
        $categories_disponibles = [];
        $performances_par_epreuve = [];

        if (
            !empty($lignes_bdd)
        ) {

            foreach (
                $lignes_bdd as $ligne
            ) {

                $nageur_id =
                    $ligne['nageur_id'];

                if (
                    'all' ===
                    $saison_selectionnee &&
                    isset(
                        $categories_actuelles[
                            $nageur_id
                        ]
                    )
                ) {

                    $categorie_a_afficher =
                        $categories_actuelles[
                            $nageur_id
                        ]['nom_categorie'];

                    $libelle_a_afficher =
                        $categories_actuelles[
                            $nageur_id
                        ]['libelle'];

                } else {

                    $categorie_a_afficher =
                        $ligne['categorie'];

                    $libelle_a_afficher =
                        $ligne['categorie_libelle'] .
                        ' (en ' .
                        $saison_selectionnee .
                        ')';
                }

                if (
                    !isset(
                        $categories_disponibles[
                            $categorie_a_afficher
                        ]
                    )
                ) {

                    $categories_disponibles[
                        $categorie_a_afficher
                    ] =
                        $libelle_a_afficher;
                }

                if (
                    !isset(
                        $profils_nageurs[
                            $nageur_id
                        ]
                    )
                ) {

                    $profils_nageurs[
                        $nageur_id
                    ] = [

                        'nageur_id' =>
                            $nageur_id,

                        'nom' =>
                            $ligne['nom'],

                        'prenom' =>
                            $ligne['prenom'],

                        'categorie' =>
                            $categorie_a_afficher,

                        'categorie_libelle' =>
                            $libelle_a_afficher,

                        'chronos' => []
                    ];
                }

                $temps_nageur =
                    $ligne['temps'];

                /*
                 * Position temporaire.
                 *
                 * Elle est utilisée uniquement
                 * pour la qualification.
                 */
                $position =
                    null;

                $position_key =
                    $nageur_id .
                    '|' .
                    $ligne['epreuve'];

                if (
                    isset(
                        $positions_qualification[
                            $position_key
                        ]
                    )
                ) {

                    $position =
                        $positions_qualification[
                            $position_key
                        ];
                }

                $est_qualifie =
                    $this->isQualified(
                        $categorie_a_afficher,
                        $ligne['epreuve'],
                        $temps_nageur,
                        $position,
                        $grille_qualifs
                    );

                $chronos_existants = $profils_nageurs[$nageur_id]['chronos'];
                $chronometre_actuel = $chronos_existants[$ligne['epreuve']] ?? null;
                if ($chronometre_actuel === null ||
                    $this->timeToSeconds($temps_nageur) < $this->timeToSeconds($chronometre_actuel['temps'])) {
                    $profils_nageurs[$nageur_id]['chronos'][$ligne['epreuve']] = [

                    'temps' =>
                        $temps_nageur,

                    'date' =>
                        $ligne['date_perf'],

                    'lieu' =>
                        $ligne['lieu'],

                    'est_qualifie' =>
                        $est_qualifie
                    ];
                }

                if (
                    !in_array(
                        $ligne['epreuve'],
                        $epreuves_trouvees
                    )
                ) {

                    $epreuves_trouvees[] =
                        $ligne['epreuve'];
                }

                if (
                    !isset(
                        $performances_par_epreuve[
                            $ligne['epreuve']
                        ]
                    )
                ) {

                    $performances_par_epreuve[
                        $ligne['epreuve']
                    ] = [];
                }

                $performances_par_epreuve[
                    $ligne['epreuve']
                ][] = [

                    'nageur_id' =>
                        $nageur_id,

                    'nom' =>
                        $ligne['nom'],

                    'prenom' =>
                        $ligne['prenom'],

                    'categorie' =>
                        $categorie_a_afficher,

                    'temps' =>
                        $temps_nageur,

                    'date_perf' =>
                        $ligne['date_perf'],

                    'lieu' =>
                        $ligne['lieu'],

                    'est_qualifie' =>
                        $est_qualifie
                ];
            }
        }

        $ordre_categories_officiel = [
            'FPO',
            'HPO',
            'FBE',
            'HBE',
            'FMI',
            'HMI',
            'FCA',
            'HCA',
            'FJU',
            'HJU',
            'FSE',
            'HSE',
            'F35+',
            'H35+',
            'F45+',
            'H45+',
            'F55+',
            'H55+'
        ];

        $categories_triees = [];

        foreach (
            $ordre_categories_officiel
            as $code_cat
        ) {

            if (
                isset(
                    $categories_disponibles[
                        $code_cat
                    ]
                )
            ) {

                $categories_triees[
                    $code_cat
                ] =
                    $categories_disponibles[
                        $code_cat
                    ];
            }
        }

        foreach (
            $categories_disponibles
            as $code_cat => $libelle
        ) {

            if (
                !isset(
                    $categories_triees[
                        $code_cat
                    ]
                )
            ) {

                $categories_triees[
                    $code_cat
                ] = $libelle;
            }
        }

        $categories_disponibles =
            $categories_triees;

        $ordre_officiel = [
            '25SF',
            '50SF',
            '100SF',
            '200SF',
            '400SF',
            '800SF',
            '1500SF',
            '1850SF',
            '25AP',
            '50AP',
            '100IS',
            '800IS',
            '200IS',
            '400IS',
            '50BI',
            '100BI',
            '200BI',
            '400BI'
        ];

        $colonnes_epreuves =
            array_intersect(
                $ordre_officiel,
                $epreuves_trouvees
            );

        $statistiques = [

            'total_nageurs' =>
                count(
                    $profils_nageurs
                ),

            'total_performances' => count($lignes_bdd),

            'nageurs_qualifies' =>
                [],

            'total_qualifications' =>
                0,

            'filles' =>
                0,

            'garcons' =>
                0
        ];

        foreach (
            $profils_nageurs
            as $nageur_id => $infos
        ) {

            $est_qualifie_nageur =
                false;

            $epreuves_qualif = [];

            $premiere_lettre =
                substr(
                    $infos['categorie'],
                    0,
                    1
                );

            if (
                'F' ===
                $premiere_lettre
            ) {

                ++$statistiques['filles'];

            } elseif (
                'H' ===
                $premiere_lettre
            ) {

                ++$statistiques['garcons'];
            }

            foreach (
                $infos['chronos']
                as $epreuve => $perf
            ) {

                if (
                    true ===
                    $perf['est_qualifie']
                ) {

                    $est_qualifie_nageur =
                        true;

                    $epreuves_qualif[] =
                        $epreuve;

                    ++$statistiques[
                        'total_qualifications'
                    ];
                }
            }

            if (
                $est_qualifie_nageur
            ) {

                $statistiques[
                    'nageurs_qualifies'
                ][] = [

                    'nom' =>
                        $infos['nom'],

                    'prenom' =>
                        $infos['prenom'],

                    'categorie' =>
                        $infos['categorie_libelle'],

                    'epreuves' =>
                        implode(
                            ', ',
                            $epreuves_qualif
                        )
                ];
            }
        }

        /*
         * Variables utilisées par dashboard.php.
         */
        $annees_disponibles =
            $saisons_disponibles;

        $annee_selectionnee =
            $saison_selectionnee;

        require_once
            __DIR__ .
            '/../views/dashboard.php';
    }

    public function getHistoryApi()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $nageur_param = $_GET['nageur_id'] ?? null;
        $nageur_id = is_scalar($nageur_param) ? filter_var($nageur_param, FILTER_VALIDATE_INT) : false;
        $epreuve = is_string($_GET['epreuve'] ?? null) ? trim($_GET['epreuve']) : '';
        $categorie = is_string($_GET['categorie'] ?? null) ? trim($_GET['categorie']) : '';
        $saison_selectionnee = is_string($_GET['saison'] ?? null) ? $_GET['saison'] : 'all';
        if (!$nageur_id || $nageur_id < 1 || $epreuve === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Paramètres invalides.']);
            return;
        }

        $pdo =
            Database::getConnection();

        $model =
            new PerformanceModel($pdo);

        $saisons_disponibles = $model->getSaisons();
        if ($saison_selectionnee !== 'all' &&
            !in_array($saison_selectionnee, $saisons_disponibles, true)) {
            $saison_selectionnee = 'all';
        }

        $history =
            $model->getHistorique(
                $nageur_id,
                $epreuve,
                $saison_selectionnee
            );

        $data = [];

        foreach (
            $history as $h
        ) {

            $data[] = [

                'date' =>
                    $h['date_perf'],

                'temps_str' =>
                    $h['temps'],

                'temps_sec' =>
                    $this->timeToSeconds(
                        $h['temps']
                    ),

                'lieu' =>
                    $h['lieu']
            ];
        }

        $temps_ref_sec = null;
        $temps_ref_str = null;

        if (
            !empty($categorie)
        ) {

            $grille =
                $model->getGrilleQualifs(
                    $saison_selectionnee === 'all'
                        ? $this->getCurrentSeason()
                        : $saison_selectionnee
                );

            if (
                isset(
                    $grille[
                        $categorie
                    ][$epreuve]
                )
            ) {

                $temps_ref_str =
                    $grille[
                        $categorie
                    ][$epreuve
                    ]['temps_de_ref'];

                if (
                    $temps_ref_str !== null &&
                    $temps_ref_str !== ''
                ) {

                    $temps_ref_sec =
                        $this->timeToSeconds(
                            $temps_ref_str
                        );
                }
            }
        }

        header(
            'Content-Type: application/json'
        );

        echo json_encode([
            'history' =>
                $data,

            'temps_ref_sec' =>
                $temps_ref_sec,

            'temps_ref_str' =>
                $temps_ref_str
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function exportCsv()
    {
        $pdo =
            Database::getConnection();

        $model =
            new PerformanceModel($pdo);

        $saison_param = $_GET['saison'] ?? 'all';
        $saison_selectionnee = is_string($saison_param) ? $saison_param : 'all';
        $saisons_disponibles = $model->getSaisons();
        if ($saison_selectionnee !== 'all' &&
            !in_array($saison_selectionnee, $saisons_disponibles, true)) {
            $saison_selectionnee = 'all';
        }

        $mode_affichage = ($_GET['affichage'] ?? 'meilleures') === 'toutes'
            ? 'toutes'
            : 'meilleures';

        $lignes_bdd =
            $model->getPerformances(
                $saison_selectionnee,
                $mode_affichage
            );

        $grille_qualifs =
            $model->getGrilleQualifs(
                $saison_selectionnee === 'all'
                    ? $this->getCurrentSeason()
                    : $saison_selectionnee
            );

        /*
         * Les positions sont calculées uniquement
         * pour déterminer les qualifications.
         */
        $lignes_pour_qualification = $mode_affichage === 'toutes'
            ? $model->getPerformances($saison_selectionnee, 'meilleures')
            : $lignes_bdd;

        $positions_qualification =
            $this->calculateQualificationPositions(
                $lignes_pour_qualification
            );

        $nom_saison =
            (
                'all' ===
                $saison_selectionnee
            )
                ? 'toutes_saisons'
                : $saison_selectionnee;

        $filename =
            "export_performances_{$nom_saison}_" .
            date('Ymd_His') .
            '.csv';

        header(
            'Content-Type: text/csv; charset=utf-8'
        );

        header(
            'Content-Disposition: attachment; filename="' .
            $filename .
            '"'
        );

        $output =
            fopen(
                'php://output',
                'w'
            );

        fputs(
            $output,
            "\xEF\xBB\xBF"
        );

        fputcsv(
            $output,
            [
                'Nom',
                'Prénom',
                'Date de naissance',
                'Catégorie',
                'Épreuve',
                'Temps',
                'Date',
                'Lieu',
                'Qualifié ?'
            ],
            ';'
        );

        $categories_actuelles = [];

        if (
            'all' ===
            $saison_selectionnee
        ) {

            $categories_actuelles =
                $model->getCategoriesActuelles();
        }

        if (
            !empty($lignes_bdd)
        ) {

            foreach (
                $lignes_bdd as $ligne
            ) {

                $nageur_id =
                    $ligne['nageur_id'];

                if (
                    'all' ===
                    $saison_selectionnee &&
                    isset(
                        $categories_actuelles[
                            $nageur_id
                        ]
                    )
                ) {

                    $categorie =
                        $categories_actuelles[
                            $nageur_id
                        ]['nom_categorie'];

                } else {

                    $categorie =
                        $ligne['categorie'];
                }

                $position =
                    null;

                $position_key =
                    $nageur_id .
                    '|' .
                    $ligne['epreuve'];

                if (
                    isset(
                        $positions_qualification[
                            $position_key
                        ]
                    )
                ) {

                    $position =
                        $positions_qualification[
                            $position_key
                        ];
                }

                $qualification =
                    $this->isQualified(
                        $categorie,
                        $ligne['epreuve'],
                        $ligne['temps'],
                        $position,
                        $grille_qualifs
                    );

                $est_qualifie = $qualification === null
                    ? 'Non défini'
                    : ($qualification ? 'Oui' : 'Non');

                $row = [
                        $ligne['nom'],
                        $ligne['prenom'],
                        $ligne['date_naissance'],
                        $categorie,
                        $ligne['epreuve'],
                        $ligne['temps'],
                        $ligne['date_perf'],
                        $ligne['lieu'],
                        $est_qualifie
                    ];
                fputcsv(
                    $output,
                    array_map([$this, 'protectCsvFormula'], $row),
                    ';'
                );
            }
        }

        fclose($output);
    }

    private function protectCsvFormula($value)
    {
        $value = (string)$value;
        return preg_match('/^[\t\r ]*[=+@-]/u', $value)
            ? "'" . $value
            : $value;
    }

    private function timeToSeconds(
        $timeStr
    ) {
        if (
            $timeStr === null ||
            $timeStr === ''
        ) {
            return PHP_FLOAT_MAX;
        }

        $parts =
            explode(
                ':',
                str_replace(
                    ',',
                    '.',
                    $timeStr
                )
            );

        if (
            2 === count($parts)
        ) {

            return
                ($parts[0] * 60) +
                (float)$parts[1];
        }

        return (float)$parts[0];
    }
}
