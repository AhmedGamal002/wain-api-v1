@extends('team.layout')

@section('title', 'اتقبلت الدعوة')

@section('content')
    <h1>أهلاً بيك في الفريق</h1>

    <p>حسابك جاهز بدور <strong>{{ $role }}</strong>. سجّل دخول بإيميلك والباسورد اللي اخترته.</p>

    <a class="btn" href="{{ $panelUrl }}">فتح لوحة التحكم</a>
@endsection
