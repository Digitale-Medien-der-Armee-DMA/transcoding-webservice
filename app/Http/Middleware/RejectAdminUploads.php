<?php

namespace App\Http\Middleware;

use Closure;

class RejectAdminUploads
{
    public function handle($request, Closure $next)
    {
        if (!config('admin.upload.enabled', false) && $request->files->count() > 0) {
            abort(403, 'Admin uploads are disabled');
        }

        return $next($request);
    }
}
