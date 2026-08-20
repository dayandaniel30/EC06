<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit etre une chaine de caracteres.',
    'email' => "Le champ :attribute doit etre une adresse email valide.",
    'min' => [
        'string' => 'Le champ :attribute doit contenir au moins :min caracteres.',
    ],
    'max' => [
        'string' => 'Le champ :attribute ne doit pas depasser :max caracteres.',
    ],
    'unique' => 'Le champ :attribute est deja utilise.',
    'in' => 'La valeur selectionnee pour :attribute est invalide.',

    'attributes' => [
        'name' => 'nom',
        'email' => 'email',
        'password' => 'mot de passe',
        'role' => 'role',
    ],
];
