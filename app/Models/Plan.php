<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'v2_plan';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    // 套餐未显式设置 reset_traffic_method 时，回退到全局默认值；集中一处，避免多处各自复制这段 fallback 逻辑
    public static function resolveResetTrafficMethod(?Plan $plan): int
    {
        $method = $plan ? $plan->reset_traffic_method : null;
        if ($method === null) {
            $method = config('v2board.reset_traffic_method', 0);
        }
        return (int)$method;
    }
}
