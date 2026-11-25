@php
    $modalId = $item ? 'activity-edit-'.$item->id : 'activity-create';
    $restore = old('_modal') === $modalId;
@endphp
<form method="post" action="/courses/{{ $course->id }}/activities{{ $item ? '/'.$item->id : '' }}" class="fields">
    @csrf
    @if($item)
        @method('PUT')
    @endif
    <input type="hidden" name="_modal" value="{{ $modalId }}">
    <label>Tên hoạt động<input name="title" value="{{ $restore ? old('title') : ($item->title ?? '') }}" required maxlength="255"></label>
    <label>Công cụ LTI<select name="lti_registration_id" required>
        @foreach($tools as $tool)
            <option value="{{ $tool->id }}" @selected(($restore ? old('lti_registration_id') : ($item->lti_registration_id ?? null)) == $tool->id)>{{ $tool->name }}</option>
        @endforeach
    </select></label>
    <label>Target Link URI<input name="target_uri" type="url" value="{{ $restore ? old('target_uri') : ($item->target_uri ?? '') }}" required></label>
    <label>Điểm tối đa<input name="maximum" type="number" min="1" max="100000" value="{{ $restore ? old('maximum') : ($item->maximum ?? 100) }}" required></label>
    <label>Hướng dẫn<textarea name="instructions" maxlength="10000">{{ $restore ? old('instructions') : ($item->instructions ?? '') }}</textarea></label>
    <label>Thứ tự hiển thị<input name="sort_order" type="number" min="0" max="100000" value="{{ $restore ? old('sort_order') : ($item->sort_order ?? 0) }}"></label>
    <p>Thời gian theo Việt Nam UTC+7. Để trống nếu không giới hạn. Hạn nộp dùng đánh dấu trễ; thời gian đóng chặn mở bài mới.</p>
    @foreach(['opens_at' => 'Mở từ', 'due_at' => 'Hạn nộp', 'closes_at' => 'Đóng lúc'] as $field => $label)
    <label>{{ $label }}<input type="datetime-local" name="{{ $field }}" value="{{ $restore ? old($field) : (!empty($item->$field) ? \Illuminate\Support\Carbon::parse($item->$field)->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i') : '') }}"></label>
    @endforeach
    <label>Cách tính điểm<select name="grade_policy"><option value="latest" @selected(($restore ? old('grade_policy') : ($item->grade_policy ?? 'latest')) === 'latest')>Điểm gần nhất có điểm</option><option value="highest" @selected(($restore ? old('grade_policy') : ($item->grade_policy ?? 'latest')) === 'highest')>Điểm cao nhất</option></select></label>
    @if($item)<p>Hoạt động đã có lượt mở hoặc điểm sẽ giữ nguyên bài Hub, công cụ và thang điểm. Tạo hoạt động mới nếu cần đổi.</p>@endif
    <label class="check"><input name="published" type="checkbox" value="1" @checked($restore ? old('published') : ($item->published ?? false))> Xuất bản</label>
    <button>{{ $item ? 'Lưu thay đổi' : 'Thêm hoạt động' }}</button>
</form>
