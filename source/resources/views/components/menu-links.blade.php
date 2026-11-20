    <nav aria-label="Điều hướng chính">
    @foreach($links as $path => $label)
        <a href="{{ $path }}" @if($current === $path) aria-current="page" @endif>
            @include('components.sidebar-icon', ['icon' => $path === '/' ? 'courses' : basename($path)])
            <span>{{ $label }}</span>
        </a>
    @endforeach
    </nav>
