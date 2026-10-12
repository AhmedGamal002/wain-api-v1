<?php

// رسائل الـ validation بالعربي للحقول اللي الـ API بيستخدمها. بتتضاف قواعد جديدة هنا مع كل feature
// (التطبيق بيعرض كل رسالة تحت الحقل نفسه، معيار قبول F2).
return [
    'accepted' => 'لازم توافق على :attribute.',
    'confirmed' => ':attribute مش مطابق للتأكيد.',
    'email' => 'اكتب إيميل صحيح.',
    'max' => [
        'string' => ':attribute لازم يكون أقل من :max حرف.',
    ],
    'min' => [
        'string' => ':attribute لازم يكون :min حروف على الأقل.',
    ],
    'required' => ':attribute مطلوب.',
    'string' => ':attribute لازم يكون نص.',
    'unique' => ':attribute مستخدم قبل كده.',

    'password' => [
        'letters' => ':attribute لازم يحتوي على حرف واحد على الأقل.',
        'numbers' => ':attribute لازم يحتوي على رقم واحد على الأقل.',
        'mixed' => ':attribute لازم يحتوي على حرف كبير وحرف صغير.',
        'symbols' => ':attribute لازم يحتوي على رمز واحد على الأقل.',
        'uncompromised' => ':attribute ظهر في تسريب بيانات، اختار غيره.',
    ],

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'الإيميل',
        'password' => 'الباسورد',
        'password_confirmation' => 'تأكيد الباسورد',
        'terms_accepted' => 'الشروط والخصوصية',
    ],
];
