<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'وين')</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; padding: 24px; background: #F7F5F0; font-family: Tahoma, Arial, sans-serif; color: #071E45; }
        .card { max-width: 440px; margin: 40px auto; background: #fff; border: 1px solid #EFE6CC; border-radius: 16px; padding: 28px; }
        .brand { margin: 0 0 4px; font-size: 14px; color: #8A5E12; font-weight: bold; }
        h1 { margin: 0 0 16px; font-size: 20px; }
        p { margin: 0 0 12px; font-size: 15px; line-height: 1.7; }
        .muted { color: #5B6577; font-size: 13px; }
        label { display: block; margin: 16px 0 6px; font-size: 14px; font-weight: bold; }
        input[type=text], input[type=password], input[type=email] { width: 100%; padding: 12px; border: 1px solid #D9D2BE; border-radius: 10px; font-size: 16px; font-family: inherit; }
        input[readonly] { background: #F7F5F0; color: #5B6577; direction: ltr; text-align: left; }
        input[type=password] { direction: ltr; text-align: left; }
        .error { margin: 6px 0 0; color: #B3261E; font-size: 13px; }
        .btn { display: block; width: 100%; margin-top: 24px; padding: 14px; background: #E8A817; color: #071E45; border: 0; border-radius: 12px; font-size: 16px; font-weight: bold; font-family: inherit; cursor: pointer; text-align: center; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <p class="brand">وين</p>
        @yield('content')
    </div>
</body>
</html>
