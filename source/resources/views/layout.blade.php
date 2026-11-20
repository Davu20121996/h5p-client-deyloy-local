<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Secure LTI') · Không gian học tập</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Newsreader:ital,wght@0,600;0,700;1,700&amp;display=swap">
<link rel="stylesheet" href="/lms.css?v={{ filemtime(public_path('lms.css')) }}">
</head>
<body>
@auth
    @php
        $links = ['/' => 'Khóa học'];
        if (!empty($course)) {
            $base = '/courses/'.$course->id;
            $links += [$base.'/activities' => 'Hoạt động H5P', $base.'/grades' => 'Sổ điểm'];
            if (in_array(auth()->user()->role, ['teacher', 'tenant_admin'], true)) {
                $links += [$base.'/enrollment' => 'Ghi danh', $base.'/settings' => 'Cài đặt khóa học'];
            }
        }
        if (in_array(auth()->user()->role, ['admin', 'tenant_admin'], true)) {
            $links['/admin/tenants'] = 'Tổ chức';
            $links += ['/admin/users' => 'Tài khoản', '/admin/tools' => 'Công cụ LTI', '/admin/logs' => 'Nhật ký LTI'];
        }
        $links['/account/password'] = 'Đổi mật khẩu';
        $current = '/'.request()->path();
        $current = match ($current) {
            '//' => '/',
            '/admin' => '/admin/users',
            $base ?? null => ($base ?? '').'/activities',
            default => $current,
        };
    @endphp
@endauth
<header class="top"><a class="brand" href="/"><span class="logo" aria-hidden="true">✳</span><span>Secure LTI<small>PHÒNG THỬ NGHIỆM HỌC LIỆU</small></span></a>
@auth
<form method="post" action="/logout" class="account">@csrf <span>{{ auth()->user()->name }}</span><button class="outline">Đăng xuất</button></form>
<details class="mobile-menu">
    <summary><span aria-hidden="true">☰</span> Menu</summary>
    @include('components.menu-links')
</details>
@endauth
</header>
@auth
<div class="workspace">
<aside class="sidebar">
    @include('components.menu-links')

</aside>
@endauth
<main class="container">
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')

</main>
@auth</div>@endauth
<div class="sunset-stripe" aria-hidden="true"></div>
<footer class="site-footer"><a href="/">Secure LTI</a><span>H5P Hosting · LTI 1.3</span></footer>
@auth
<script defer src="/lms-dialog.js?v={{ filemtime(public_path('lms-dialog.js')) }}"></script>
@endauth
</body>
</html>
