<svg class="sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($icon)
        @case('courses')
            <path d="m2 9 10-5 10 5-10 5-10-5Zm4 2v5c3 3 9 3 12 0v-5m4-2v7"/>
            @break
        @case('users')
        @case('profile')
        @case('classrooms')
        @case('enrollment')
            <circle cx="9" cy="7" r="3"/><path d="M3 20v-2a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v2m1-16a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 5v2"/>
            @break
        @case('tenants')
            <path d="M3 21h18M6 21V5l6-3 6 3v16M10 9h4m-4 4h4m-4 4h4"/>
            @break
        @case('tools')
            <path d="M9 3v5m6-5v5M6 8h12v3a6 6 0 0 1-12 0V8Zm6 9v4"/>
            @break
        @case('logs')
            <path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10l-7-7Zm0 0v7h7M8 13h8m-8 4h8"/>
            @break
        @case('password')
            <rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>
            @break
        @case('activities')
            <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/>
            @break
        @case('grades')
        @case('reports')
            <path d="M4 4v16h16M9 16v-4m5 4V8m5 8v-6"/>
            @break
        @case('settings')
            <path d="M4 6h16M4 12h16M4 18h16M8 3v6m8 0v6m-6 0v6"/>
            @break
    @endswitch
</svg>
