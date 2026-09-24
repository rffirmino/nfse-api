<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('request_id', $request->header('X-Request-Id', 'req_' . bin2hex(random_bytes(8))));
        $response = $next($request);
        $response->headers->set('X-Request-Id', $request->attributes->get('request_id'));
        return $response;
    }
}
