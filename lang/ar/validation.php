<?php

// رسائل الـ validation بالعربي للحقول اللي الـ API بيستخدمها. بتتضاف قواعد جديدة هنا مع كل feature
// (التطبيق بيعرض كل رسالة تحت الحقل نفسه، معيار قبول F2).
return [
    'accepted' => 'لازم توافق على :attribute.',
    'array' => ':attribute لازم يكون قايمة.',
    'boolean' => ':attribute لازم يكون صح أو غلط.',
    'between' => [
        'numeric' => ':attribute لازم يكون بين :min و:max.',
        'string' => ':attribute لازم يكون من :min لـ :max حرف.',
        'array' => ':attribute لازم يكون فيه من :min لـ :max عنصر.',
        'file' => ':attribute لازم يكون من :min لـ :max كيلوبايت.',
    ],
    'confirmed' => ':attribute مش مطابق للتأكيد.',
    'email' => 'اكتب إيميل صحيح.',
    'exists' => ':attribute المختار مش موجود.',
    'in' => ':attribute المختار مش صحيح.',
    'integer' => ':attribute لازم يكون رقم صحيح.',
    'max' => [
        'numeric' => ':attribute لازم يكون :max أو أقل.',
        'string' => ':attribute لازم يكون أقل من :max حرف.',
        'array' => ':attribute لازم ما يزيدش عن :max عنصر.',
        'file' => ':attribute لازم يكون :max كيلوبايت أو أقل.',
    ],
    'min' => [
        'numeric' => ':attribute لازم يكون :min أو أكتر.',
        'string' => ':attribute لازم يكون :min حروف على الأقل.',
        'array' => ':attribute لازم يكون فيه :min عنصر على الأقل.',
        'file' => ':attribute لازم يكون :min كيلوبايت على الأقل.',
    ],
    'numeric' => ':attribute لازم يكون رقم.',
    'required' => ':attribute مطلوب.',
    'required_if' => ':attribute مطلوب مع الترتيب بالأقرب.',
    'required_with' => ':attribute مطلوب مع :values.',
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
        'area' => 'المنطقة',
        'categories' => 'التصنيفات',
        'categories.*' => 'التصنيف',
        'sort' => 'الترتيب',
        'lat' => 'خط العرض',
        'lng' => 'خط الطول',
        'page' => 'الصفحة',
        'per_page' => 'عدد النتايج في الصفحة',
    ],
];
