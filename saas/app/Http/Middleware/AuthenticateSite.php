<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the WordPress plugin by its site token (Authorization: Bearer gsk_...).
 */
class AuthenticateSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $site = $token === '' ? null : Site::findByToken($token);
        if ($site === null) {
            return response()->json(['message' => 'Invalid site token.'], 401);
        }

        $request->attributes->set('site', $site);

        return $next($request);
    }
}
