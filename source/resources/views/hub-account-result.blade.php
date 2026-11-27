<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kết quả tạo tài khoản</title>
    <link rel="stylesheet" href="/lms.css">
</head>
<body>
<main class="container" @if(!$messages) data-hub-picker-result @endif>
    @if($messages)
        <h1>Chưa tạo được tài khoản</h1>
        <ul class="notice error" role="alert">
            @foreach($messages as $message)<li>{{ $message }}</li>@endforeach
        </ul>
        <p>Bấm Tạo tài khoản trên Client để mở lại biểu mẫu.</p>
    @else
        <p role="status">Đã tạo tài khoản.</p>
    @endif
    <a href="/admin/users" target="_top">Về danh sách tài khoản</a>
</main>
@if(!$messages)
<script defer src="/hub-picker.js?v={{ filemtime(public_path('hub-picker.js')) }}"></script>
@endif
</body>
</html>
