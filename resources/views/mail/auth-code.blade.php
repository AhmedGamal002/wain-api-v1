<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:24px;background:#F7F5F0;font-family:Tahoma,Arial,sans-serif;color:#071E45;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border:1px solid #EFE6CC;border-radius:16px;padding:28px;">
        <p style="margin:0 0 4px;font-size:14px;color:#8A5E12;font-weight:bold;">{{ __('mail.brand') }}</p>
        <h1 style="margin:0 0 16px;font-size:20px;">{{ $heading }}</h1>

        <p style="margin:0 0 12px;font-size:15px;line-height:1.7;">{{ __('mail.code_intro') }}</p>

        <p style="margin:16px 0;padding:16px;text-align:center;background:#FFF6DC;border-radius:12px;font-size:32px;letter-spacing:8px;font-weight:bold;direction:ltr;">{{ $code }}</p>

        <p style="margin:0 0 8px;font-size:14px;color:#5B6577;">{{ __('mail.valid_for', ['minutes' => $minutes]) }}</p>
        <p style="margin:0;font-size:13px;color:#5B6577;">{{ __('mail.ignore') }}</p>
    </div>
</body>
</html>
