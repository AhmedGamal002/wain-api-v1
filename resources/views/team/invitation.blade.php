@extends('team.layout')

@section('title', 'قبول دعوة الفريق')

@section('content')
    <h1>انضم لفريق وين</h1>

    <p>اتدعيت للوحة التحكم بدور <strong>{{ $role }}</strong>.</p>

    <form method="POST" action="{{ route('team.invitation.accept', ['token' => $token]) }}">
        @csrf

        <label for="email">الإيميل</label>
        <input id="email" type="email" value="{{ $email }}" readonly>

        @if ($needsAccount)
            <label for="name">الاسم</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" maxlength="50" required autofocus autocomplete="name">
            @error('name') <p class="error">{{ $message }}</p> @enderror

            <label for="password">الباسورد</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">
            @error('password') <p class="error">{{ $message }}</p> @enderror
            <p class="muted">8 حروف على الأقل، فيها حرف ورقم.</p>

            <label for="password_confirmation">تأكيد الباسورد</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        @else
            <p class="muted" style="margin-top:16px;">الإيميل ده ليه حساب في وين، والدور هيتضاف عليه.</p>
        @endif

        <button class="btn" type="submit">قبول الدعوة</button>
    </form>

    <p class="muted" style="margin-top:16px;">الدعوة صالحة لحد {{ $expiresAt->format('Y-m-d H:i') }} بتوقيت القاهرة.</p>
@endsection
