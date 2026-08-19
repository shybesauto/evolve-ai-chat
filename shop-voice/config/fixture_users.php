<?php
declare(strict_types=1);

/**
 * Sample shop users. In live mode these come from Shopmonkey's `user` object,
 * so the ids here stand in for real Shopmonkey user ids (§7).
 *
 * cert_number carries the state certification MCL 257.1313b requires on the
 * invoice for both diagnosis and repair — it is not decoration.
 */
return [
    [
        'id' => 'usr_russ',
        'name' => 'Russ',
        'email' => 'russ@shybesautomotive.example',
        'role' => 'admin',
        'cert_number' => 'MI-A1-448210',
    ],
    [
        'id' => 'usr_tech_a',
        'name' => 'Danny Cole',
        'email' => 'danny@shybesautomotive.example',
        'role' => 'tech',
        'cert_number' => 'MI-A5-771903',
    ],
    [
        'id' => 'usr_tech_b',
        'name' => 'Marisol Reyes',
        'email' => 'marisol@shybesautomotive.example',
        'role' => 'tech',
        'cert_number' => 'MI-A8-330551',
    ],
];
