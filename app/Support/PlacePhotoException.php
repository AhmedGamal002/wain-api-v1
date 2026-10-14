<?php

namespace App\Support;

use RuntimeException;

/**
 * رفض عملية على صور المكان (نوع أو حجم أو مقاس أو عدد غلط). الرسالة بالعربي وجاهزة للعرض في اللوحة.
 */
final class PlacePhotoException extends RuntimeException {}
