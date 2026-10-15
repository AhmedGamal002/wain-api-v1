<?php

namespace App\Support;

use RuntimeException;

/**
 * إجراء على مستخدم مش مسموح (حالة غلط أو سبب ناقص أو آخر أدمن). الرسالة بالعربي وجاهزة تتعرض في اللوحة.
 */
final class UserModerationException extends RuntimeException {}
