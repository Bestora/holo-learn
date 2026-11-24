<?php

// ponytail: only the rules the auth and settings forms use; everything else falls back to English.
return [
    'accepted' => ':attribute muss akzeptiert werden.',
    'boolean' => ':attribute muss an oder aus sein.',
    'confirmed' => 'Die Bestätigung von :attribute passt nicht.',
    'current_password' => 'Das Passwort ist falsch.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'lowercase' => ':attribute darf nur Kleinbuchstaben enthalten.',
    'max' => [
        'string' => ':attribute darf höchstens :max Zeichen lang sein.',
    ],
    'min' => [
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'required' => ':attribute fehlt.',
    'string' => ':attribute muss Text sein.',
    'unique' => 'Diese :attribute ist schon vergeben.',
    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss Groß- und Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Zahl enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => 'Dieses :attribute ist in einem Datenleck aufgetaucht. Bitte wähle ein anderes.',
    ],

    'attributes' => [
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
        'current_password' => 'Aktuelles Passwort',
        'code' => 'Code',
        'recovery_code' => 'Wiederherstellungscode',
    ],
];
