<?php

/*
| Solo se sobrescriben las claves que el framework no lee del .env.
| El resto de config/app.php se toma de los valores por defecto de Laravel.
*/

return [
    'timezone' => env('APP_TIMEZONE', 'America/Bogota'),
];
