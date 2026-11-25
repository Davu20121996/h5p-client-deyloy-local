@extends('layout')
@section('title', 'Báo cáo học tập')
@section('content')
<h1>Báo cáo học tập</h1>
<p>Chọn khóa học bạn có quyền xem, sau đó lọc theo lớp, bài và trạng thái để xem hoặc tải kết quả.</p>
<section class="card settings-card">
@if($courses->isNotEmpty())
<form method="get" action="/reports" class="fields">
<label>Khóa học<select name="course_id" required>@foreach($courses as $item)<option value="{{ $item->id }}">{{ $item->title }}</option>@endforeach</select></label>
<button>Xem báo cáo khóa học</button></form>
@else<p>Chưa có khóa học trong phạm vi quản lý của bạn.</p>@endif
</section>
@endsection
