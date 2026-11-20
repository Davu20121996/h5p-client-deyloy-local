@extends('layout')
@section('title', 'Đổi mật khẩu')
@section('content')
<h1>Đổi mật khẩu</h1>
<section class="card settings-card">
<form method="post" action="/account/password" class="fields">@csrf
        <label>Mật khẩu hiện tại<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>Mật khẩu mới<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
        <label>Nhập lại mật khẩu mới<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
        <button>Lưu mật khẩu</button>
    </form>
</section>
@endsection
