# Introduction

API تطبيق وين (Wain): الأماكن والتصنيفات والحساب. العقد بين الـ backend والموبايل.

<aside>
    <strong>Base URL</strong>: <code>http://localhost</code>
</aside>

    الـ API ده هو العقد بين الـ backend والموبايل. أي تغيير في شكل الـ response أو البارامترات بيتكتب هنا
    الأول (في الـ PR نفسه) وبيتقال لمعلوم، والملفات دي بتتولّد بـ `php artisan scribe:generate`.

    - **المسار الأساسي:** `/api/v1`، JSON فقط.
    - **الدخول:** `Authorization: Bearer <token>` (Sanctum، صلاحية 30 يوم). الـ endpoints العامة بتقبل token اختياري.
    - **اللغة:** هيدر `Accept-Language: ar` (الافتراضي) أو `en`. الحقل الإنجليزي الفاضي بيرجع العربي.
    - **الأخطاء:** `{ "message": "...", "code": "PLACE_NOT_AVAILABLE", "errors": { } }`. `errors` في 422 بس. الأكواد في `06-Data-Model-and-API.md`.
    - **الأرقام:** اللاتينية (4.3، 150). الوقت بتوقيت القاهرة، والتخزين UTC.

