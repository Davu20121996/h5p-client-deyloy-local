<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kết quả chọn học liệu</title>
    <link rel="stylesheet" href="/lms.css">
</head>
<body>
<main class="container" data-hub-picker-result>
    <p role="status">{{ $message }}</p>
    <a href="{{ $destination }}" target="_top">Về hoạt động H5P của khóa học</a>
</main>
<script defer src="/hub-picker.js?v={{ filemtime(public_path('hub-picker.js')) }}"></script>
</body>
</html>
