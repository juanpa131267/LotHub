<?php

/*
| Mensajes de validación en español para las reglas usadas en el proyecto.
| Las reglas no listadas caen al inglés (APP_FALLBACK_LOCALE=en).
| :attribute se reemplaza por el nombre legible que define cada controlador.
*/

return [
    'accepted'       => 'Debes aceptar :attribute.',
    'alpha_dash'     => ':Attribute solo puede contener letras, números, guiones y guion bajo.',
    'array'          => ':Attribute no es válido.',
    'between'        => ['numeric' => ':Attribute debe estar entre :min y :max.'],
    'boolean'        => ':Attribute debe ser sí o no.',
    'confirmed'      => 'La confirmación de :attribute no coincide.',
    'date'           => ':Attribute no es una fecha válida.',
    'digits'         => ':Attribute debe tener :digits dígitos.',
    'email'          => ':Attribute debe ser un correo electrónico válido.',
    'exists'         => 'El valor seleccionado en :attribute no es válido.',
    'gt'             => ['numeric' => ':Attribute debe ser mayor que :value.'],
    'in'             => 'El valor seleccionado en :attribute no es válido.',
    'integer'        => ':Attribute debe ser un número entero.',
    'max'            => ['numeric' => ':Attribute no puede ser mayor que :max.', 'string' => ':Attribute no puede superar :max caracteres.'],
    'min'            => ['numeric' => ':Attribute debe ser al menos :min.', 'string' => ':Attribute debe tener al menos :min caracteres.'],
    'numeric'        => ':Attribute debe ser un número.',
    'regex'          => 'El formato de :attribute no es válido.',
    'required'       => ':Attribute es obligatorio.',
    'string'         => ':Attribute debe ser texto.',
    'unique'         => 'Ya existe un registro con ese valor de :attribute.',
    'password'       => [
        'letters' => ':Attribute debe contener al menos una letra.',
        'numbers' => ':Attribute debe contener al menos un número.',
    ],

    'attributes' => [],
];
