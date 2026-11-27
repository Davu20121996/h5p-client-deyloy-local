@extends('layout')
@section('title', 'Lớp học')
@section('content')
<h1>Lớp học</h1>
@if($tenantManager)
<x-modal id="classroom-create" title="Tạo lớp học">
    <form method="post" action="/classrooms" class="fields">
        @csrf
        <input type="hidden" name="_modal" value="classroom-create">
        <label>Tên lớp<input name="name" maxlength="255" required value="{{ old('_modal') === 'classroom-create' ? old('name') : '' }}"></label>
        <label>Mô tả<textarea name="description" maxlength="3000">{{ old('_modal') === 'classroom-create' ? old('description') : '' }}</textarea></label>
        <label>Giáo viên phụ trách<select name="teacher_id">
            <option value="">Chưa phân công</option>
            @foreach($teachers->where('locked', false) as $teacher)
            <option value="{{ $teacher->id }}" @selected(old('_modal') === 'classroom-create' && old('teacher_id') == $teacher->id)>{{ $teacher->name }}</option>
            @endforeach
        </select></label>
        <button>Tạo lớp</button>
    </form>
</x-modal>
@endif

@forelse($classrooms as $classroom)
@php($roster = $members->get($classroom->id, collect()))
<section class="card section">
    <h2>{{ $classroom->name }} @if($classroom->archived_at)<span class="pill">Đã lưu trữ</span>@endif</h2>
    @if($classroom->description)<p>{{ $classroom->description }}</p>@endif
    @if($tenantManager)
        <p>Giáo viên phụ trách: {{ $teachers->get($classroom->teacher_id)?->name ?? 'Chưa phân công' }}</p>
    @endif
    <div class="actions">
        @if($tenantManager)
            @php($editModal = 'classroom-edit-'.$classroom->id)
            <x-modal :id="$editModal" title="Sửa lớp và phân công">
                <form method="post" action="/classrooms/{{ $classroom->id }}" class="fields">
                    @csrf
                    <input type="hidden" name="_modal" value="{{ $editModal }}">
                    <label>Tên lớp<input name="name" value="{{ old('_modal') === $editModal ? old('name') : $classroom->name }}" maxlength="255" required></label>
                    <label>Mô tả<textarea name="description" maxlength="3000">{{ old('_modal') === $editModal ? old('description') : $classroom->description }}</textarea></label>
                    <label>Giáo viên phụ trách<select name="teacher_id">
                        <option value="">Chưa phân công</option>
                        @foreach($teachers as $teacher)
                        <option value="{{ $teacher->id }}" @disabled($teacher->locked) @selected((old('_modal') === $editModal ? old('teacher_id') : $classroom->teacher_id) == $teacher->id)>{{ $teacher->name }}{{ $teacher->locked ? ' (đã khóa)' : '' }}</option>
                        @endforeach
                    </select></label>
                    <button>Lưu</button>
                </form>
            </x-modal>
        @endif

        @unless($classroom->archived_at)
            @php($createStudentModal = 'classroom-create-student-'.$classroom->id)
            <x-modal :id="$createStudentModal" title="Tạo học viên">
                <form method="post" action="/classrooms/{{ $classroom->id }}/students" class="fields">
                    @csrf
                    <input type="hidden" name="_modal" value="{{ $createStudentModal }}">
                    <input type="hidden" name="create_account" value="1">
                    <label>Họ tên<input name="name" maxlength="255" required value="{{ old('_modal') === $createStudentModal ? old('name') : '' }}"></label>
                    <label>Email<input name="email" type="email" maxlength="255" required value="{{ old('_modal') === $createStudentModal ? old('email') : '' }}"></label>
                    <label>Mật khẩu ban đầu<input name="password" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required></label>
                    <button>Tạo và thêm vào lớp</button>
                </form>
            </x-modal>
            @php($addStudentModal = 'classroom-add-student-'.$classroom->id)
            <x-modal :id="$addStudentModal" title="Thêm học viên hiện có">
                <form method="post" action="/classrooms/{{ $classroom->id }}/students" class="fields">
                    @csrf
                    <input type="hidden" name="_modal" value="{{ $addStudentModal }}">
                    <label>Email học viên<input name="email" type="email" maxlength="255" required value="{{ old('_modal') === $addStudentModal ? old('email') : '' }}"></label>
                    <button>Thêm vào lớp</button>
                </form>
            </x-modal>
            @if($tenantManager)
                @php($membersModal = 'classroom-members-'.$classroom->id)
                <x-modal :id="$membersModal" title="Thành viên lớp">
                    <form method="post" action="/classrooms/{{ $classroom->id }}/members" class="fields">
                        @csrf @method('PUT')
                        <input type="hidden" name="_modal" value="{{ $membersModal }}">
                        <p>Bỏ chọn sẽ gỡ học viên khỏi lớp. Các ghi danh và quyền làm bài trong khóa học được quản lý tại khóa đó.</p>
                        <div class="member-picker">
                            @forelse($students as $student)
                            <label class="check"><input type="checkbox" name="user_ids[]" value="{{ $student->id }}" @checked($roster->contains('user_id', $student->id))>{{ $student->name }} · {{ $student->email ?? $student->username }} {{ $student->locked ? '(đã khóa)' : '' }}</label>
                            @empty<p>Chưa có học viên.</p>@endforelse
                        </div>
                        <button>Lưu danh sách</button>
                    </form>
                </x-modal>
            @endif
        @endunless
        @if($tenantManager)
            <form method="post" action="/classrooms/{{ $classroom->id }}/archive">
                @csrf
                <input type="hidden" name="archived" value="{{ $classroom->archived_at ? 0 : 1 }}">
                <button class="outline">{{ $classroom->archived_at ? 'Khôi phục lớp' : 'Lưu trữ lớp' }}</button>
            </form>
        @endif
    </div>

    <h3>Học viên <span class="pill">{{ $roster->count() }}</span></h3>
    @if($roster->isEmpty())
        <p class="muted">Lớp chưa có học viên.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th>Họ tên</th><th>Email hoặc tên đăng nhập</th><th>Trạng thái</th></tr></thead>
                <tbody>
                @foreach($roster as $student)
                <tr><td>{{ $student->name }}</td><td>{{ $student->email ?? $student->username }}</td><td>{{ $student->locked ? 'Đã khóa' : 'Hoạt động' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@empty
<p class="empty">{{ $tenantManager ? 'Tổ chức chưa có lớp học.' : 'Bạn chưa được phân công lớp học.' }}</p>
@endforelse
@endsection
