<?php

// رسائل أخطاء الـ API العامة بالكود (06 القسم 1). أكواد الدومين بتتضاف هنا مع كل feature.
return [
    'UNAUTHENTICATED' => 'Please sign in to continue.',
    'INVALID_CREDENTIALS' => 'Wrong email or password.',
    'ACCOUNT_SUSPENDED' => 'This account is suspended. Please contact support.',
    'INVALID_CODE' => 'That code is not correct. Check your email and try again.',
    'CODE_EXPIRED' => 'This code has expired. Request a new one.',
    'CODE_ATTEMPTS_EXCEEDED' => 'Too many wrong attempts. Request a new code.',
    'RESET_TOKEN_INVALID' => 'This request has expired. Start again and request a new code.',
    'EMAIL_ALREADY_VERIFIED' => 'Your email is already verified.',
    'EMAIL_NOT_VERIFIED' => 'Please confirm your email first.',
    'ALREADY_REVIEWED' => 'You already reviewed this place. You can edit your review.',
    'DAILY_LIMIT_REACHED' => 'You reached today\'s limit. Try again tomorrow.',
    'FORBIDDEN' => 'You are not allowed to do this.',
    'PLACE_NOT_AVAILABLE' => 'This place is not available right now.',
    'NOT_FOUND' => 'Not found.',
    'VALIDATION_ERROR' => 'The given data was invalid.',
    'TOO_MANY_REQUESTS' => 'Too many requests. Please try again shortly.',
    'SERVER_ERROR' => 'Something went wrong. Please try again.',
    'ERROR' => 'Something went wrong.',
];
