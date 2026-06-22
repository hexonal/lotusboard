<?php

namespace App\Http\Controllers\V1\Server\Concerns;

use Illuminate\Http\Request;

trait EtagHelpers
{
    /**
     * 按逗号 split + 去引号 + hash_equals 比较 If-None-Match,
     * 避免老版 strpos 子串匹配的误命中, 并兼容 null header.
     */
    protected function ifNoneMatchHit(Request $request, string $eTag): bool
    {
        $header = (string)$request->header('If-None-Match', '');
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $token) {
            $token = trim($token, " \t\"");
            if ($token !== '' && hash_equals($eTag, $token)) {
                return true;
            }
        }
        return false;
    }
}
