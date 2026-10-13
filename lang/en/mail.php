<?php

// Code emails (password reset and email verification).
return [
    'brand' => 'Wain',
    'password_reset' => [
        'subject' => 'Your password reset code',
        'heading' => 'Reset your password',
    ],
    'email_verify' => [
        'subject' => 'Your email verification code',
        'heading' => 'Verify your email',
    ],
    'code_intro' => 'Enter this code in the app:',
    'valid_for' => 'The code is valid for :minutes minutes.',
    'ignore' => 'If you did not ask for this code you can ignore this message, your account is safe.',
];
