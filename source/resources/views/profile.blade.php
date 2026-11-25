@extends('layout')
@section('title', 'Hồ sơ cá nhân')
@section('content')
<h1>Hồ sơ cá nhân</h1>
<section class="card settings-card"><form method="post" action="/account/profile" class="fields">@csrf @method('PUT')
<label>Họ tên<input name="name" value="{{ old('name', auth()->user()->name) }}" required maxlength="255" autocomplete="name"></label>
<label>Email đăng nhập<input name="email" type="email" value="{{ old('email', auth()->user()->email) }}" required maxlength="255" autocomplete="username"></label>
<p>Nếu đổi email đăng nhập, nhập mật khẩu hiện tại để xác nhận. Các phiên đăng nhập khác sẽ được thu hồi.</p>
<label>Mật khẩu hiện tại<input name="current_password" type="password" maxlength="1024" autocomplete="current-password"></label>
<button>Lưu hồ sơ</button></form></section>
@endsection
