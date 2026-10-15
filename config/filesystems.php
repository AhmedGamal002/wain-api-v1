<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Cloudflare R2 (S3 compatible): النسخ الاحتياطي لقاعدة البيانات (`backup:database`). bucket خاص منفصل
        // عن bucket الصور (أسبوع 9). الـ endpoint `https://<account-id>.r2.cloudflarestorage.com` والـ region `auto`.
        'backups' => [
            'driver' => 's3',
            'key' => env('BACKUP_R2_KEY'),
            'secret' => env('BACKUP_R2_SECRET'),
            'region' => 'auto',
            'bucket' => env('BACKUP_R2_BUCKET'),
            'endpoint' => env('BACKUP_R2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        // صور الأماكن على R2 (أسبوع 9، 03 القسم 4.5). `photos` هي النسخ (thumb وmedium وlarge)، وbucket عام
        // (r2.dev أو دومين مخصّص في `PLACE_PHOTOS_R2_URL`). `photo_originals` الأصل، وbucket خاص من غير رابط.
        // مفيش `visibility` عن قصد: R2 ما بيعرفش ACL، والعام عام من إعداد الـ bucket نفسه. `throw` لازم يبقى true:
        // بدونه `put()` بيرجّع false ساكت والصورة بتبقى «جاهزة» وملفها مش موجود. مفاتيح الأصل بتاخد مفاتيح النسخ
        // لو مفيش غيرها (token واحد على الـ bucketين). تفعيلهم بـ `PLACE_PHOTOS_DISK=photos` و
        // `PLACE_PHOTOS_ORIGINAL_DISK=photo_originals` ثم `php artisan photos:migrate`.
        'photos' => [
            'driver' => 's3',
            'key' => env('PLACE_PHOTOS_R2_KEY'),
            'secret' => env('PLACE_PHOTOS_R2_SECRET'),
            'region' => 'auto',
            'bucket' => env('PLACE_PHOTOS_R2_BUCKET'),
            'endpoint' => env('PLACE_PHOTOS_R2_ENDPOINT'),
            'url' => env('PLACE_PHOTOS_R2_URL'),
            'use_path_style_endpoint' => true,
            // مسار النسخة فيه id الصورة فمش بيتغيّر لنفس المحتوى: شهر في الـ CDN وعلى الجهاز
            'options' => ['CacheControl' => 'public, max-age=2592000'],
            'throw' => true,
            'report' => false,
        ],

        'photo_originals' => [
            'driver' => 's3',
            'key' => env('PLACE_ORIGINALS_R2_KEY', env('PLACE_PHOTOS_R2_KEY')),
            'secret' => env('PLACE_ORIGINALS_R2_SECRET', env('PLACE_PHOTOS_R2_SECRET')),
            'region' => 'auto',
            'bucket' => env('PLACE_ORIGINALS_R2_BUCKET'),
            'endpoint' => env('PLACE_ORIGINALS_R2_ENDPOINT', env('PLACE_PHOTOS_R2_ENDPOINT')),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
