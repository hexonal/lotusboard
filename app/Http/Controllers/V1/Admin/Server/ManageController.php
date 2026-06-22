<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $serverService = new ServerService();
        return response([
            'data' => $serverService->getAllServers()
        ]);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '5m');
        $params = $request->only(
            'shadowsocks',
            'vmess',
            'vless',
            'trojan',
            'tuic',
            'hysteria',
            'anytls',
            'v2node'
        ) ?? [];
        if (empty($params)) {
            $params = [
                'shadowsocks' => $_POST['shadowsocks'] ?? null,
                'vmess'       => $_POST['vmess'] ?? null,
                'vless'       => $_POST['vless'] ?? null,
                'trojan'      => $_POST['trojan'] ?? null,
                'tuic'        => $_POST['tuic'] ?? null,
                'hysteria'    => $_POST['hysteria'] ?? null,
                'anytls'      => $_POST['anytls'] ?? null,
                'v2node'      => $_POST['v2node'] ?? null,
            ];
        }
        // 显式映射协议名 → FQCN，避免类名注入和大小写问题
        $modelMap = [
            'shadowsocks' => \App\Models\ServerShadowsocks::class,
            'vmess'       => \App\Models\ServerVmess::class,
            'vless'       => \App\Models\ServerVless::class,
            'trojan'      => \App\Models\ServerTrojan::class,
            'tuic'        => \App\Models\ServerTuic::class,
            'hysteria'    => \App\Models\ServerHysteria::class,
            'anytls'      => \App\Models\ServerAnytls::class,
            'v2node'      => \App\Models\ServerV2node::class,
        ];
        DB::beginTransaction();
        try {
            foreach ($params as $k => $v) {
                if (empty($v) || !isset($modelMap[$k])) continue;
                $model = $modelMap[$k];
                foreach ($v as $id => $sort) {
                    $row = $model::find($id);
                    if (!$row) {
                        DB::rollBack();
                        abort(404, "节点不存在: {$k}#{$id}");
                    }
                    if (!$row->update(['sort' => $sort])) {
                        DB::rollBack();
                        abort(500, '保存失败');
                    }
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        return response([
            'data' => true
        ]);
    }
}
