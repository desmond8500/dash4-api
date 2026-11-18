<?php

/*
| Messages de validation en français (règles utilisées par l'API Dash4).
| :attribute est remplacé par le nom du champ (voir `attributes` en bas de fichier).
*/

return [
    'array' => 'Le champ :attribute doit être une liste.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'date' => 'Le champ :attribute n\'est pas une date valide.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'dimensions' => 'L\'image :attribute est trop grande (4000 × 4000 pixels au plus).',
    'enum' => 'La valeur du champ :attribute n\'est pas valide.',
    'exists' => 'La valeur du champ :attribute n\'existe pas.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'image' => 'Le champ :attribute doit être une image.',
    'in' => 'La valeur du champ :attribute n\'est pas valide.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'max' => [
        'file' => 'Le fichier :attribute ne peut pas dépasser :max Ko.',
        'array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
        'numeric' => 'Le champ :attribute ne peut pas dépasser :max.',
        'string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
    ],
    'mimes' => 'Le fichier :attribute doit être de type : :values.',
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min élément(s).',
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'password' => [
        'letters' => 'Le mot de passe doit contenir au moins une lettre.',
        'mixed' => 'Le mot de passe doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le mot de passe doit contenir au moins un chiffre.',
        'symbols' => 'Le mot de passe doit contenir au moins un symbole.',
        'uncompromised' => 'Ce mot de passe est apparu dans une fuite de données : choisissez-en un autre.',
    ],
    'prohibited' => 'Le champ :attribute ne peut pas être modifié.',
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être un texte.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'uploaded' => 'Le fichier :attribute n\'a pas pu être envoyé.',
    'uuid' => 'Le champ :attribute doit être un identifiant UUID valide.',

    'attributes' => [
        'address' => 'adresse',
        'description' => 'description',
        'email' => 'e-mail',
        'firstname' => 'prénom',
        'job_title' => 'fonction',
        'logo' => 'logo',
        'lastname' => 'nom',
        'name' => 'nom',
        'password' => 'mot de passe',
        'phone' => 'téléphone',
        'roles' => 'profils',
        'status' => 'statut',
        'type' => 'type',
    ],
];
