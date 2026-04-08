<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanUseCopilot
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canUseAiCopilot()) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have permission to use the AI Copilot.');
        }

        return $next($request);
    }
}
