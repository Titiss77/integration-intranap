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

    public function index()
    {
        $pdo = Database::getConnection();

        $model =
            new PerformanceModel($pdo);

        $saisons_disponibles =
            $model->getSaisons();

        $saison_selectionnee =
            isset($_GET['saison'])
                ? $_GET['saison']
                : 'all';

        $lignes_bdd =
            $model->getPerformances(
                $saison_selectionnee
            );

        $grille_qualifs =
            $model->getGrilleQualifs();

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

                $est_qualifie = null;

                /*
                 * Qualification uniquement
                 * sur le temps de référence.
                 *
                 * Aucun classement n'est utilisé.
                 */
                if (
                    isset(
                        $grille_qualifs[
                            $categorie_a_afficher
                        ][
                            $ligne['epreuve']
                        ]
                    )
                ) {

                    $temps_ref =
                        $grille_qualifs[
                            $categorie_a_afficher
                        ][
                            $ligne['epreuve']
                        ];

                    $sec_nageur =
                        $this->timeToSeconds(
                            $temps_nageur
                        );

                    $sec_ref =
                        $this->timeToSeconds(
                            $temps_ref
                        );

                    $est_qualifie =
                        (
                            $sec_nageur <=
                            $sec_ref
                        );
                }

                $profils_nageurs[
                    $nageur_id
                ]['chronos'][
                    $ligne['epreuve']
                ] = [

                    'temps' =>
                        $temps_nageur,

                    'date' =>
                        $ligne['date_perf'],

                    'lieu' =>
                        $ligne['lieu'],

                    'est_qualifie' =>
                        $est_qualifie
                ];

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

            'total_performances' =>
                0,

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

                ++$statistiques[
                    'total_performances'
                ];

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
        $nageur_id =
            $_GET['nageur_id'] ?? 0;

        $epreuve =
            $_GET['epreuve'] ?? '';

        $categorie =
            $_GET['categorie'] ?? '';

        $pdo =
            Database::getConnection();

        $model =
            new PerformanceModel($pdo);

        $history =
            $model->getHistorique(
                $nageur_id,
                $epreuve
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

        usort(
            $data,
            function ($a, $b) {

                $da =
                    implode(
                        '',
                        array_reverse(
                            explode(
                                '/',
                                $a['date']
                            )
                        )
                    );

                $db =
                    implode(
                        '',
                        array_reverse(
                            explode(
                                '/',
                                $b['date']
                            )
                        )
                    );

                return strcmp(
                    $da,
                    $db
                );
            }
        );

        $temps_ref_sec = null;
        $temps_ref_str = null;

        if (
            !empty($categorie)
        ) {

            $grille =
                $model->getGrilleQualifs();

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
                    ][$epreuve];

                $temps_ref_sec =
                    $this->timeToSeconds(
                        $temps_ref_str
                    );
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
        ]);
    }

    public function exportCsv()
    {
        $pdo =
            Database::getConnection();

        $model =
            new PerformanceModel($pdo);

        $saison_selectionnee =
            isset($_GET['saison'])
                ? $_GET['saison']
                : 'all';

        $lignes_bdd =
            $model->getPerformances(
                $saison_selectionnee
            );

        $grille_qualifs =
            $model->getGrilleQualifs();

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

                $est_qualifie =
                    'Non';

                if (
                    isset(
                        $grille_qualifs[
                            $categorie
                        ][
                            $ligne['epreuve']
                        ]
                    )
                ) {

                    if (
                        $this->timeToSeconds(
                            $ligne['temps']
                        )
                        <=
                        $this->timeToSeconds(
                            $grille_qualifs[
                                $categorie
                            ][
                                $ligne['epreuve']
                            ]
                        )
                    ) {

                        $est_qualifie =
                            'Oui';
                    }
                }

                fputcsv(
                    $output,
                    [
                        $ligne['nom'],
                        $ligne['prenom'],
                        $ligne['date_naissance'],
                        $categorie,
                        $ligne['epreuve'],
                        $ligne['temps'],
                        $ligne['date_perf'],
                        $ligne['lieu'],
                        $est_qualifie
                    ],
                    ';'
                );
            }
        }

        fclose($output);
    }

    private function timeToSeconds(
        $timeStr
    ) {
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