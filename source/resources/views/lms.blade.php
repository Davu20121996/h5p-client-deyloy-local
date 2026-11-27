@extends('layout')
@section('title', $course->title ?? 'Khóa học')
@section('content')
@guest
<div class="signin">
    <section class="hero welcome editorial-cover">
        <div class="editorial-copy">
        <span class="eyebrow">HỌC LIỆU TƯƠNG TÁC / LTI 1.3</span>
        <h1>Thử bài học.<br><em>Kiểm chứng kết quả.</em></h1>
        <p>Mở bài từ dịch vụ H5P Hosting, hoàn thành hoạt động và kiểm tra điểm trả về trong sổ điểm.</p>
        <a class="hero-link" href="#workflow">Xem quy trình kiểm thử ↓</a>
        </div>
    </section>
    <section id="login" class="card login"><span class="eyebrow">KHÔNG GIAN LÀM VIỆC</span><h2>Đăng nhập</h2><p>Sử dụng tài khoản do quản trị viên cấp.</p>
    <form method="post" action="/login" class="fields">@csrf
        <label>Tên đăng nhập hoặc email<input type="text" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="username" autocapitalize="none" required></label>
        <label>Mật khẩu<input name="password" type="password" autocomplete="current-password" required></label>
        <button>Đăng nhập</button>
        @php($forumUrl = config('services.discourse.url'))
        @if(is_string($forumUrl) && filter_var($forumUrl, FILTER_VALIDATE_URL) && parse_url($forumUrl, PHP_URL_SCHEME) === 'https')
            <a class="button-link" href="{{ rtrim($forumUrl, '/').'/login' }}">Diễn đàn · Đăng nhập bằng Zalo</a>
        @else
            <button type="button" class="outline" disabled>Diễn đàn chưa mở</button>
        @endif
    </form></section>
</div>
<section id="workflow" class="workflow" aria-labelledby="workflow-title">
    <h2 id="workflow-title">Từ bài H5P đến sổ điểm</h2>
    <ol class="steps">
        <li><h3>Kết nối công cụ</h3><p>Quản trị viên đăng ký dịch vụ H5P Hosting qua LTI 1.3.</p></li>
        <li><h3>Mở và làm bài</h3><p>Giáo viên xuất bản hoạt động. Học viên mở bài từ khóa học.</p></li>
        <li><h3>Đối chiếu điểm</h3><p>Giáo viên xem điểm công cụ gửi về qua AGS trong sổ điểm.</p></li>
    </ol>
</section>
@else
@php($manage = in_array(auth()->user()->role, ['teacher', 'tenant_admin'], true))
@if(!$course)
<section class="hero">
    <span class="eyebrow">{{ $organization->name ?? 'KHÔNG GIAN KIỂM THỬ H5P' }}</span>
    <h1>Xin chào, {{ auth()->user()->name }}</h1>
    <p>{{ $organization->welcome_message ?? 'Chọn khóa học để mở hoạt động H5P và đối chiếu điểm trả về.' }}</p>
    <div class="orb" aria-hidden="true"></div>
</section>
<h2 class="section-title">Khóa học của bạn <span class="pill">{{ $courses->count() }}</span></h2>
<div class="grid">
@forelse($courses as $item)
<a class="card course-card" href="/courses/{{ $item->id }}"><span class="tile" aria-hidden="true">▤</span><h3>{{ $item->title }}</h3><p>{{ \Illuminate\Support\Str::limit($item->description ?? '', 140) }}</p><span class="link">Mở khóa học →</span></a>
@empty
<p class="empty">Chưa có khóa học.</p>
@endforelse
</div>
@if($manage)
<x-modal id="course-create" title="Tạo khóa học">
<form method="post" action="/courses" class="fields">@csrf<input type="hidden" name="_modal" value="course-create">
<label>Tên khóa học<input name="title" maxlength="255" required></label>
<label>Mô tả<textarea name="description" maxlength="3000"></textarea></label>
<button>Tạo khóa học</button></form>
</x-modal>
@endif
@else
<a class="back" href="/">← Tất cả khóa học</a>
<section class="hero"><span class="eyebrow">KHÓA HỌC</span><h1>{{ $course->title }}</h1><p>{{ $course->description }}</p><div class="orb" aria-hidden="true"></div></section>
@if($screen === 'activities')
<h2 class="section-title">Hoạt động H5P</h2>
<section class="card list">
@forelse($activities as $activity)
<div class="row activity-row">
<div class="activity-details"><span class="tile" aria-hidden="true">▶</span><div class="grow"><strong>{{ $activity->title }}</strong><div class="muted">{{ $activity->maximum }} điểm · {{ $activity->archived_at ? 'Đã lưu trữ' : ($activity->published ? 'Đã xuất bản' : 'Bản nháp') }}</div>
@if($activity->due_at)<small>Hạn nộp {{ \Illuminate\Support\Carbon::parse($activity->due_at)->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</small>@endif
@if(!$manage && !\App\Services\LearningAccess::available($activity))<p class="muted">{{ \App\Services\LearningAccess::availability($activity) === 'scheduled' ? 'Chưa đến giờ mở bài' : 'Đã hết thời gian mở bài' }}@if(\App\Services\LearningAccess::availability($activity) === 'scheduled') · {{ \Illuminate\Support\Carbon::parse($activity->opens_at)->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}@endif</p>@endif</div>
</div>
<div class="actions">
@if(parse_url($activity->target_uri, PHP_URL_HOST) === 'demo.invalid')
<span class="muted">Bài mẫu · Chưa kết nối H5P</span>
@elseif($activity->published && !$activity->archived_at && auth()->user()->role !== 'admin' && ($manage || \App\Services\LearningAccess::available($activity)))
<a class="button-link" href="/courses/{{ $course->id }}/activities/{{ $activity->id }}/learn">Mở bài học →</a>
@endif
@if($manage)
<x-modal :id="'activity-edit-'.$activity->id" title="Sửa hoạt động H5P">
@include('activity-form', ['item' => $activity])
</x-modal>
@unless($activity->archived_at)
<form method="post" action="/courses/{{ $course->id }}/activities/{{ $activity->id }}">@csrf<input type="hidden" name="published" value="{{ $activity->published ? 0 : 1 }}"><button class="outline">{{ $activity->published ? 'Ẩn' : 'Xuất bản' }}</button></form>
@endunless
<form method="post" action="/courses/{{ $course->id }}/activities/{{ $activity->id }}/archive">@csrf<input type="hidden" name="archived" value="{{ $activity->archived_at ? 0 : 1 }}"><button class="outline">{{ $activity->archived_at ? 'Khôi phục bản nháp' : 'Lưu trữ' }}</button></form>
@endif
</div>
</div>
@empty
<p class="empty">Chưa có hoạt động.</p>
@endforelse
</section>
@elseif($screen === 'grades')
@include('gradebook')
@endif
@if($manage && $screen === 'activities')
<div class="section">
<section class="card settings-card"><h2>Thêm bài từ Hub</h2>
@if($tools->whereNotNull('deep_link_url')->isNotEmpty())
<form method="post" action="/courses/{{ $course->id }}/hub/select" class="fields" target="hub-picker" data-hub-picker-form>@csrf
<label>Kho nội dung<select name="lti_registration_id" required>@foreach($tools->whereNotNull('deep_link_url') as $tool)<option value="{{ $tool->id }}">{{ $tool->name }}</option>@endforeach</select></label>
<p>Bài được thêm vào khóa học dưới dạng nháp.</p>
<input type="hidden" name="embedded" value="1">
<div class="actions"><button type="submit">Mở thư viện H5P</button></div>
</form>
@else<p>Liên hệ quản trị hệ thống để thiết lập kết nối của tổ chức trên H5P Hub.</p>@endif
</section>
@if($tools->whereNotNull('deep_link_url')->isNotEmpty())
<section class="lesson-player hub-picker-panel" data-hub-picker-panel data-expandable-frame aria-label="Thư viện H5P Hub" hidden>
    <div class="lesson-toolbar">
        <div class="lesson-player-label">Thư viện H5P Hub</div>
        <x-frame-expand />
    </div>
    <div class="lesson-stage">
        <iframe name="hub-picker" class="hub-picker" title="Thư viện H5P Hub" data-hub-picker-frame></iframe>
    </div>
</section>
<script defer src="/frame-expand.js?v={{ filemtime(public_path('frame-expand.js')) }}"></script>
<script defer src="/hub-picker.js?v={{ filemtime(public_path('hub-picker.js')) }}"></script>
@endif
<x-modal id="activity-create" title="Thêm hoạt động H5P">
@if($tools->isEmpty())<p>Quản trị viên cần đăng ký công cụ LTI trước.</p>@else
@include('activity-form', ['item' => null])
@endif
</x-modal>
</div>
@elseif($screen === 'enrollment')
@if($manage)@include('course-management')@endif
@elseif($screen === 'settings' && $manage)
@include('course-management')
<h2 class="section-title">Cài đặt khóa học</h2>
<div class="actions">
<x-modal id="course-edit" title="Sửa thông tin"><form method="post" action="/courses/{{ $course->id }}" class="fields">@csrf<input type="hidden" name="_modal" value="course-edit">
<label>Tên khóa học<input name="title" value="{{ $course->title }}" maxlength="255" required></label><label>Mô tả<textarea name="description">{{ $course->description }}</textarea></label><button>Lưu thay đổi</button></form></x-modal>
<x-modal id="course-delete" title="Xóa khóa học"><form method="post" action="/courses/{{ $course->id }}" class="fields">@csrf<input type="hidden" name="_modal" value="course-delete"> @method('DELETE')
<p>Hoạt động, ghi danh và điểm của khóa học sẽ bị xóa.</p><button class="danger">Xác nhận xóa</button></form></x-modal>
</div>
@endif
@endif
@endguest
@endsection
