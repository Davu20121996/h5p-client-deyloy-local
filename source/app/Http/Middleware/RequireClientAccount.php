<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireClientAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->role === 'admin' && $request->routeIs('login')) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        }
        if ($user) {
            abort_unless($user->tenant_id && ! $user->locked
                && in_array($user->role, ['tenant_admin', 'teacher', 'student'], true), 403);
        }

        return $next($request);
    }
}
