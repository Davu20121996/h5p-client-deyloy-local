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
        <label>Email<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required></label>
        <label>Mật khẩu<input name="password" type="password" autocomplete="current-password" required></label>
        <button>Đăng nhập</button>
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
<div class="row"><span class="tile" aria-hidden="true">▶</span><div class="grow"><strong>{{ $activity->title }}</strong><div class="muted">{{ $activity->maximum }} điểm · {{ $activity->published ? 'Đã xuất bản' : 'Bản nháp' }}</div></div>
@if(parse_url($activity->target_uri, PHP_URL_HOST) === 'demo.invalid')
<span class="muted">Bài mẫu · Chưa kết nối H5P</span>
@elseif($activity->published && auth()->user()->role !== 'admin')
<a class="link" href="/courses/{{ $course->id }}/activities/{{ $activity->id }}/learn">Mở bài học →</a>
@endif
@if($manage)
<x-modal :id="'activity-edit-'.$activity->id" title="Sửa hoạt động H5P">
@include('activity-form', ['item' => $activity])
</x-modal>
<form method="post" action="/courses/{{ $course->id }}/activities/{{ $activity->id }}">@csrf<input type="hidden" name="published" value="{{ $activity->published ? 0 : 1 }}"><button class="outline">{{ $activity->published ? 'Ẩn' : 'Xuất bản' }}</button></form>
@endif
</div>
@empty
<p class="empty">Chưa có hoạt động.</p>
@endforelse
</section>
@elseif($screen === 'grades')
<h2 class="section-title">Sổ điểm</h2>
<section class="card table-wrap">
<table><thead><tr><th>Học viên</th><th>Hoạt động</th><th>Điểm</th><th>Thời gian (UTC+7)</th></tr></thead>
<tbody>@forelse($grades as $grade)<tr><td>{{ $grade->name }}</td><td>{{ $grade->title }}</td><td>{{ $grade->score === null ? '—' : (float) $grade->score }} / {{ $grade->maximum }}</td><td>{{ \Illuminate\Support\Carbon::parse($grade->reported_at)->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}</td></tr>@empty<tr><td colspan="4" class="muted">Chưa có điểm trả về.</td></tr>@endforelse</tbody></table>
</section>
@endif
@if($manage && $screen === 'activities')
<div class="section">
<x-modal id="activity-create" title="Thêm hoạt động H5P">
@if($tools->isEmpty())<p>Quản trị viên cần đăng ký công cụ LTI trước.</p>@else
@include('activity-form', ['item' => null])
@endif
</x-modal>
</div>
@elseif($screen === 'enrollment')
<h2 class="section-title">Ghi danh học viên</h2>
@if($manage)
<section class="card settings-card"><form method="post" action="/courses/{{ $course->id }}/enroll" class="fields">@csrf
<label>Email học viên<input name="email" type="email" list="available-learners" value="{{ old('email') }}" required></label>
<datalist id="available-learners">
@foreach($availableLearners as $learner)
    <option value="{{ $learner->email }}">{{ $learner->name }}</option>
@endforeach
</datalist>
<button>Ghi danh</button></form></section>
@endif
<h2 class="section-title">Học viên đã ghi danh <span class="pill">{{ $learners->count() }}</span></h2>
<section class="card table-wrap">
<table><thead><tr><th>Họ tên</th><th>Email</th><th>Thao tác</th></tr></thead>
<tbody>
@forelse($learners as $learner)
<tr><td>{{ $learner->name }}</td><td>{{ $learner->email }}</td><td>
@if($manage)
<x-modal :id="'unenroll-'.$learner->id" title="Thu hồi quyền">
<p>{{ $learner->name }} sẽ mất quyền truy cập và làm bài trong khóa {{ $course->title }}.</p>
<form method="post" action="/courses/{{ $course->id }}/enroll/{{ $learner->id }}">
@csrf @method('DELETE')
<button class="danger">Xác nhận thu hồi</button>
</form>
</x-modal>
@endif
</td></tr>
@empty
<tr><td colspan="3" class="muted">Chưa có học viên được ghi danh.</td></tr>
@endforelse
</tbody></table>
</section>
<h2 class="section-title">Quyền làm bài</h2>
<section class="card settings-card">
@if(!$manage)
@elseif($learners->isEmpty() || $activities->isEmpty())
<p>Ghi danh học viên và tạo hoạt động để cấp quyền làm bài.</p>
@else
<form method="post" action="/courses/{{ $course->id }}/assign" class="fields">@csrf
<label>Học viên<select name="user_id" required>@foreach($learners as $learner)<option value="{{ $learner->id }}">{{ $learner->name }} ({{ $learner->email }})</option>@endforeach</select></label>
<label>Hoạt động<select name="activity_id" required>@foreach($activities as $activity)<option value="{{ $activity->id }}">{{ $activity->title }}{{ $activity->published ? '' : ' (bản nháp)' }}</option>@endforeach</select></label>
<div class="row"><button name="granted" value="1">Cấp quyền</button><button class="outline" name="granted" value="0">Thu hồi quyền</button></div>
</form>
@endif
@forelse($assignments as $assignment)
<div class="row">{{ $assignment->name }} · {{ $assignment->title }}</div>
@empty
<p class="muted">Chưa có bài được cấp.</p>
@endforelse
</section>
@elseif($screen === 'settings' && $manage)
<h2 class="section-title">Cài đặt khóa học</h2>
<div class="grid management">
<x-modal id="course-edit" title="Sửa thông tin"><form method="post" action="/courses/{{ $course->id }}" class="fields">@csrf<input type="hidden" name="_modal" value="course-edit">
<label>Tên khóa học<input name="title" value="{{ $course->title }}" maxlength="255" required></label><label>Mô tả<textarea name="description">{{ $course->description }}</textarea></label><button>Lưu thay đổi</button></form></x-modal>
<x-modal id="course-delete" title="Xóa khóa học"><form method="post" action="/courses/{{ $course->id }}" class="fields">@csrf<input type="hidden" name="_modal" value="course-delete"> @method('DELETE')
<p>Hoạt động, ghi danh và điểm của khóa học sẽ bị xóa.</p><button class="danger">Xác nhận xóa</button></form></x-modal>
</div>
@endif
@endif
@endguest
@endsection
