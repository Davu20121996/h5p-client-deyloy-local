@if($screen === 'enrollment')
@php($activeClassrooms = $classrooms->whereNull('archived_at'))
<section class="card settings-card section">
<h2>Giao bài cho lớp</h2>
@if($activeClassrooms->isEmpty())
<p>Chưa có lớp đang hoạt động để giao bài.</p>
@elseif($activities->isEmpty())
<p>Tạo hoạt động hoặc khôi phục bài đã lưu trữ để giao bài.</p>
@else
<p>Học viên trong lớp được ghi danh vào khóa học và có thể mở bài theo lịch đã đặt. Bài nháp được xuất bản khi giao.</p>
<form method="post" action="/courses/{{ $course->id }}/assign-bulk" class="fields">@csrf
<input type="hidden" name="granted" value="1">
<label>Bài học<select name="activity_id" required>@foreach($activities as $activity)<option value="{{ $activity->id }}">{{ $activity->title }}{{ $activity->published ? '' : ' · Bản nháp' }}</option>@endforeach</select></label>
<label>Lớp học<select name="classroom_id" required>
    <option value="">Chọn lớp</option>
    @foreach($activeClassrooms as $classroom)
        <option value="{{ $classroom->id }}" @selected(!old('create_account') && old('classroom_id') == $classroom->id)>{{ $classroom->name }}</option>
    @endforeach
</select></label>
<button>Giao bài</button>
</form>
@endif
</section>
<section class="card settings-card section">
<h2>Tạo học viên cho lớp</h2>
@if($activeClassrooms->isEmpty())
<p>{{ auth()->user()->role === 'teacher' ? 'Bạn cần được phân công lớp đang hoạt động để tạo học viên.' : 'Cần có lớp đang hoạt động để tạo học viên.' }}</p>
@else
<form method="post" action="/courses/{{ $course->id }}/classroom-students" class="fields">@csrf
<label>Lớp học<select name="classroom_id" required>
    <option value="">Chọn lớp</option>
    @foreach($activeClassrooms as $classroom)
        <option value="{{ $classroom->id }}" @selected(old('create_account') && old('classroom_id') == $classroom->id)>{{ $classroom->name }}</option>
    @endforeach
</select></label>
<label>Họ tên<input name="name" maxlength="255" value="{{ old('name') }}" required></label>
<label>Email<input name="email" type="email" maxlength="255" value="{{ old('email') }}" required></label>
<label>Mật khẩu ban đầu<input name="password" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required></label>
<button>Tạo và thêm vào lớp</button>
</form>
@endif
</section>
@elseif($screen === 'settings')
<section class="card settings-card section"><h2>Giáo viên đồng giảng</h2>
<p>Giáo viên phụ trách: {{ $primaryTeacher->name ?? 'Chưa xác định' }}</p>
@foreach($teachers->whereIn('id', $coTeachers) as $teacher)<p>{{ $teacher->name }}{{ $teacher->locked ? ' · Đã khóa' : '' }}</p>@endforeach
@if(auth()->user()->role === 'tenant_admin' || $course->teacher_id === auth()->id())
<p>Đồng giảng được quản lý bài và ghi danh. Chỉ người phụ trách hoặc quản trị tổ chức được đổi danh sách giáo viên.</p>
@if($teachers->where('id', '!=', $course->teacher_id)->isNotEmpty())
<form method="post" action="/courses/{{ $course->id }}/teachers" class="fields">@csrf
<label>Giáo viên<select name="user_id" required>@foreach($teachers->where('id', '!=', $course->teacher_id) as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->name }}{{ $coTeachers->contains($teacher->id) ? ' · Đang đồng giảng' : '' }}{{ $teacher->locked ? ' · Đã khóa' : '' }}</option>@endforeach</select></label>
<div class="actions"><button name="granted" value="1" @disabled($teachers->where('id', '!=', $course->teacher_id)->where('locked', false)->isEmpty())>Thêm đồng giảng</button><button name="granted" value="0" class="outline">Thu hồi đồng giảng</button></div></form>
@else<p>Chưa có giáo viên khác trong tổ chức. Quản trị tổ chức có thể tạo thêm tại mục Tài khoản.</p>@endif
@if($teachers->where('id', '!=', $course->teacher_id)->where('locked', false)->isNotEmpty())
<x-modal id="teacher-transfer" title="Bàn giao giáo viên phụ trách">
<p>Khóa học và điểm được giữ nguyên. Sau khi bàn giao, người phụ trách cũ sẽ mất quyền nếu không được giữ lại làm đồng giảng.</p>
<form method="post" action="/courses/{{ $course->id }}/teacher-transfer" class="fields">@csrf
<label>Người phụ trách mới<select name="teacher_id" required>@foreach($teachers->where('id', '!=', $course->teacher_id)->where('locked', false) as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->name }}</option>@endforeach</select></label>
<label class="check"><input type="checkbox" name="retain_previous" value="1">Giữ người phụ trách cũ làm đồng giảng</label><button>Xác nhận bàn giao</button></form></x-modal>
@endif
@else<p>Liên hệ giáo viên phụ trách để thay đổi danh sách đồng giảng.</p>@endif
</section>
@endif
