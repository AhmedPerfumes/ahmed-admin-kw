<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictToDomains
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // $allowedOrigins = [
        //     'http://localhost:3000',
        // ];

        // $origin = $request->header('origin');
        // $referer = $request->header('referer');

        // // echo $request->header;

        // $isAllowed = false;

        // foreach ($allowedOrigins as $allowed) {
        //     if (
        //         ($origin && str_starts_with($origin, $allowed)) ||
        //         ($referer && str_starts_with($referer, $allowed))
        //     ) {
        //         $isAllowed = true;
        //         break;
        //     }
        // }

        // if (!$isAllowed) {


        
        //     return response()->json(['error' => 'Unauthorized'], 401);
        // }
        return $next($request);
    }
}
