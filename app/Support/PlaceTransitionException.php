<?php

namespace App\Support;

use RuntimeException;

/**
 * انتقال حالة مكان مش مسموح (حالة غلط أو شرط نشر ناقص أو سبب ناقص). الرسالة بالعربي وجاهزة تتعرض في اللوحة.
 */
final class PlaceTransitionException extends RuntimeException {}
