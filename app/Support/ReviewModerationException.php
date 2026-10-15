<?php

namespace App\Support;

use RuntimeException;

/**
 * قرار على تقييم مش مسموح (اتاخد قرار قبل كده، أو سبب ناقص). الرسالة بالعربي وجاهزة تتعرض في اللوحة.
 */
final class ReviewModerationException extends RuntimeException {}
