@if($screen === 'enrollment')
<section class="card settings-card section">
<h2>Giao bài cho lớp</h2><p>Chọn bài và lớp rồi bấm Giao bài. Học viên đang hoạt động trong lớp được thêm vào khóa học và có thể mở bài theo lịch đã đặt. Bài nháp được xuất bản khi giao cho lớp.</p>
@if($activities->isNotEmpty())
<form method="post" action="/courses/{{ $course->id }}/assign-bulk" class="fields">@csrf
<label>Bài học<select name="activity_id">@foreach($activities as $activity)<option value="{{ $activity->id }}">{{ $activity->title }}{{ $activity->archived_at ? ' · Đã lưu trữ' : ($activity->published ? '' : ' · Bản nháp') }}</option>@endforeach</select></label>
<label>Đối tượng giao bài<select name="classroom_id" required>
    <option value="">Chọn toàn khóa học hoặc một lớp</option>
    <option value="course" @selected(old('classroom_id') === 'course')>Tất cả học viên đã ghi danh</option>
    @foreach($classrooms as $classroom)
        @if(!$classroom->archived_at || $attachedClassrooms->contains($classroom->id))
        <option value="{{ $classroom->id }}" @selected(old('classroom_id') == $classroom->id)>Lớp {{ $classroom->name }}{{ $classroom->archived_at ? ' · Chỉ thu hồi' : '' }}</option>
        @endif
    @endforeach
</select></label>
<div class="actions"><button name="granted" value="1">Giao bài</button><button name="granted" value="0" class="outline">Thu hồi bài</button></div>
</form>@else<p>Tạo hoạt động trước khi giao bài.</p>@endif
</section>
<details class="card settings-card section">
<summary>Thêm cả lớp vào khóa học mà chưa giao bài</summary>
<p>Ghi danh cho học viên vào khóa học. Khi đã sẵn sàng, dùng mục Giao bài cho lớp để giao bài.</p>
@if($classrooms->whereNull('archived_at')->isNotEmpty())
<form method="post" action="/courses/{{ $course->id }}/classrooms" class="fields">@csrf
<label>Lớp<select name="classroom_id" required>@foreach($classrooms->whereNull('archived_at') as $classroom)<option value="{{ $classroom->id }}">{{ $classroom->name }}{{ $attachedClassrooms->contains($classroom->id) ? ' · Đã gắn' : '' }}</option>@endforeach</select></label>
<button>Thêm lớp vào khóa học</button></form>
@else<p>Bạn chưa có lớp đang hoạt động để ghi danh theo lớp.</p>@endif
</details>
@foreach($classrooms->whereIn('id', $attachedClassrooms) as $classroom)
<div class="card row section"><span class="grow">{{ $classroom->name }}{{ $classroom->archived_at ? ' · Đã lưu trữ' : '' }}</span>
<x-modal :id="'classroom-detach-'.$classroom->id" title="Gỡ liên kết lớp">
<p>Gỡ {{ $classroom->name }} khỏi khóa học. Học viên vẫn giữ ghi danh và quyền bài đã cấp. Thu hồi riêng trong danh sách học viên nếu cần ngừng quyền học.</p>
<form method="post" action="/courses/{{ $course->id }}/classrooms/{{ $classroom->id }}">@csrf @method('DELETE')<button>Gỡ liên kết lớp</button></form></x-modal></div>
@endforeach
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
