<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Regles metier Skillhub
    |--------------------------------------------------------------------------
    |
    | Parametres des regles fonctionnelles, regroupes ici pour qu'un changement
    | de seuil ne demande pas de toucher au code des commandes ou des controleurs.
    |
    */

    'inactivity' => [

        /*
         * Q1 - Gestion de l'inactivite des comptes.
         *
         * Duree sans activite au-dela de laquelle un compte est desactive et
         * ses acces revoques. Le defaut correspond aux 6 mois demandes.
         */
        'purge_after_days' => (int) env('USER_INACTIVITY_DAYS', 180),

        /*
         * Desinscription automatique des apprenants inactifs.
         *
         * Duree sans activite au-dela de laquelle un apprenant est retire de
         * ses formations en cours. Regle distincte de la purge de comptes :
         * elle ne touche qu'aux inscriptions, jamais au compte lui-meme.
         */
        'unenroll_after_days' => (int) env('ENROLLMENT_INACTIVITY_DAYS', 30),

        /*
         * Nombre de comptes traites par lot lors de la purge. Un lot borne
         * la memoire consommee et la duree des transactions sur une grosse base.
         */
        'chunk_size' => (int) env('USER_INACTIVITY_CHUNK', 200),
    ],

    'enrollment' => [

        /*
         * Nombre maximum de formations suivies simultanement par un apprenant.
         */
        'max_active' => (int) env('LEARNER_MAX_ACTIVE_ENROLLMENTS', 5),
    ],

];
