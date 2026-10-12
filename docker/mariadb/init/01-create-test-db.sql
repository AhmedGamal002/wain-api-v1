-- قاعدة بيانات التستات (phpunit بيستخدمها). بتتعمل مرة واحدة عند أول تشغيل للـ volume.
CREATE DATABASE IF NOT EXISTS wain_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON wain_test.* TO 'wain'@'%';
FLUSH PRIVILEGES;
