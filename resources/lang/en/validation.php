<?php

return [
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute must be text.',
    'email' => 'Enter a valid email address.',
    'confirmed' => 'The password confirmation does not match.',
    'boolean' => 'The :attribute field must be true or false.',
    'min' => [
        'string' => 'The :attribute must be at least :min characters.',
    ],
    'max' => [
        'string' => 'The :attribute must not exceed :max characters.',
    ],
    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'remember' => 'remember me',
        'token' => 'reset token',
    ],
];
