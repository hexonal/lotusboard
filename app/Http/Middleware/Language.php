<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

class Language
{
    // 只允许 resources/lang 下真实存在的语言包，避免 Carbon 的
    // AbstractTranslator::setLocale() 拿这个值直接拼 include() 路径
    // (CVE-2025-22145，未授权、guest 路由也能打的 LFI)。
    private const SUPPORTED_LOCALES = ['en-US', 'zh-CN'];

    public function handle($request, Closure $next)
    {
        $locale = $request->header('content-language');
        if ($locale && in_array($locale, self::SUPPORTED_LOCALES, true)) {
            App::setLocale($locale);
        }
        return $next($request);
    }
}
