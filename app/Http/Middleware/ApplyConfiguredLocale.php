<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyConfiguredLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = AppSetting::currentLocale();
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
