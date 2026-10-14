<?php

namespace App\Support;

use RuntimeException;

/**
 * الملف نفسه مش صالح للاستيراد (فاضي أو ترميز غلط أو ناقص أعمدة أو أكتر من 500 سطر): الرسالة بتتعرض للمستخدم
 * زي ما هي، ومفيش معاينة. غلط سطر بعينه مش استثناء: بيتسجّل على السطر.
 */
final class PlaceCsvException extends RuntimeException {}
