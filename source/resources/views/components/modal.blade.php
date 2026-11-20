@props(['id', 'title'])
<div class="modal-action">
    <button type="button" class="outline" data-modal-open="{{ $id }}">{{ $title }}</button>
    <dialog id="{{ $id }}" class="modal" aria-labelledby="{{ $id }}-title" @if($errors->any() && old('_modal') === $id) data-modal-reopen @endif>
        <header class="modal-header">
            <h2 id="{{ $id }}-title">{{ $title }}</h2>
            <button type="button" class="outline" data-modal-close aria-label="Đóng">×</button>
        </header>
        @if($errors->any() && old('_modal') === $id)
            <ul class="notice error" role="alert">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif
        {{ $slot }}
    </dialog>
</div>
