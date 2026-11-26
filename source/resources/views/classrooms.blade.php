@extends('layout')
@section('title', 'Lớp học')
@section('content')
<h1>Lớp học</h1>
<p>Quản lý lớp của tổ chức. Sau khi thêm học viên vào lớp, vào khóa học để ghi danh hoặc giao bài cho danh sách mới.</p>
<p>Lưu trữ lớp dừng việc sửa thành viên và ghi danh mới qua lớp đó; không tự thu hồi quyền học đã cấp trong các khóa.</p>
<x-modal id="classroom-create" title="Tạo lớp học">
<form method="post" action="/classrooms" class="fields">@csrf
<label>Tên lớp<input name="name" maxlength="255" required value="{{ old('name') }}"></label>
<label>Mô tả<textarea name="description" maxlength="3000">{{ old('description') }}</textarea></label>
<button>Tạo lớp</button></form>
</x-modal>
@forelse($classrooms as $classroom)
<section class="card section">
<h2>{{ $classroom->name }} @if($classroom->archived_at)<span class="pill">Đã lưu trữ</span>@endif</h2><p>{{ $classroom->description }}</p>
<p>{{ $members->get($classroom->id, collect())->count() }} học viên</p>
<div class="actions">
<x-modal :id="'classroom-edit-'.$classroom->id" title="Sửa lớp học">
<form method="post" action="/classrooms/{{ $classroom->id }}" class="fields">@csrf
<label>Tên lớp<input name="name" value="{{ $classroom->name }}" maxlength="255" required></label>
<label>Mô tả<textarea name="description" maxlength="3000">{{ $classroom->description }}</textarea></label><button>Lưu</button></form>
</x-modal>
@unless($classroom->archived_at)
<x-modal :id="'classroom-members-'.$classroom->id" title="Thành viên lớp">
<form method="post" action="/classrooms/{{ $classroom->id }}/members" class="fields">@csrf @method('PUT')
<p>Bỏ chọn chỉ gỡ khỏi danh sách lớp. Quyền đã ghi danh hoặc giao bài trong khóa học cần được thu hồi riêng.</p>
<div class="member-picker">
@forelse($students as $student)
<label class="check"><input type="checkbox" name="user_ids[]" value="{{ $student->id }}" @checked($members->get($classroom->id, collect())->contains('user_id', $student->id))>{{ $student->name }} · {{ $student->email }} {{ $student->locked ? '(đã khóa)' : '' }}</label>
@empty<p>Chưa có học viên. Tạo tài khoản học viên trước.</p>@endforelse
</div><button>Lưu danh sách</button></form>
</x-modal>
@endunless
<form method="post" action="/classrooms/{{ $classroom->id }}/archive">@csrf<input type="hidden" name="archived" value="{{ $classroom->archived_at ? 0 : 1 }}"><button class="outline">{{ $classroom->archived_at ? 'Khôi phục lớp' : 'Lưu trữ lớp' }}</button></form>
</div>
</section>
@empty<p class="empty">Chưa có lớp. Tạo lớp để ghi danh và giao bài hàng loạt.</p>@endforelse
@endsection
