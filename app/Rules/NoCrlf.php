<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

// Laravel 8.x 从未修复过 CVE-2026-48019(email:strict 挡不住 RFC5322
// obs-fws 语法里合法但带 CR/LF 的邮箱地址，官方只在 12.60.0/13.10.0+ 修了)。
// 这里手动补一份跟官方补丁等价的正则闸门，给 email:strict 用到的地方
// 加一道前置校验，堵住邮件头注入。
class NoCrlf implements Rule
{
    public function passes($attribute, $value)
    {
        return !preg_match('/[\r\n]/', (string) $value);
    }

    public function message()
    {
        return __('The :attribute contains invalid characters.');
    }
}
