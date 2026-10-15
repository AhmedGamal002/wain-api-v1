<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>دعوة للانضمام لفريق وين</title>
</head>
<body style="margin:0;padding:24px;background:#F7F5F0;font-family:Tahoma,Arial,sans-serif;color:#071E45;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border:1px solid #EFE6CC;border-radius:16px;padding:28px;">
        <p style="margin:0 0 4px;font-size:14px;color:#8A5E12;font-weight:bold;">وين</p>
        <h1 style="margin:0 0 16px;font-size:20px;">اتدعيت تنضم لفريق وين</h1>

        <p style="margin:0 0 12px;font-size:15px;line-height:1.7;">
            @if ($inviter !== '')
                {{ $inviter }} دعاك
            @else
                اتدعيت
            @endif
            تنضم للوحة التحكم بدور <strong>{{ $role }}</strong>.
        </p>

        <p style="margin:20px 0;text-align:center;">
            <a href="{{ $url }}" style="display:inline-block;padding:12px 28px;background:#E8A817;color:#071E45;border-radius:12px;font-size:16px;font-weight:bold;text-decoration:none;">قبول الدعوة</a>
        </p>

        <p style="margin:0 0 8px;font-size:14px;color:#5B6577;">الرابط صالح لمدة {{ $days }} أيام ويشتغل مرة واحدة.</p>
        <p style="margin:0;font-size:13px;color:#5B6577;">لو ما كنتش متوقع الدعوة دي تجاهل الرسالة.</p>
    </div>
</body>
</html>
