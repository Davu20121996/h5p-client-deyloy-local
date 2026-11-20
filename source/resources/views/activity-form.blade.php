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
    <label class="check"><input name="published" type="checkbox" value="1" @checked($restore ? old('published') : ($item->published ?? false))> Xuất bản</label>
    <button>{{ $item ? 'Lưu thay đổi' : 'Thêm hoạt động' }}</button>
</form>
