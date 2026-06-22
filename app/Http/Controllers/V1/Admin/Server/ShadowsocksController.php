<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerShadowsocksSave;
use App\Http\Requests\Admin\ServerShadowsocksUpdate;
use App\Models\ServerShadowsocks;
use Illuminate\Http\Request;

class ShadowsocksController extends Controller
{
    public function save(ServerShadowsocksSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = ServerShadowsocks::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server->update($params);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        if (!ServerShadowsocks::create($params)) {
            abort(500, '创建失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $server = ServerShadowsocks::find($request->input('id'));
        if (!$server) {
            abort(404, '节点ID不存在');
        }
        return response([
            'data' => $server->delete()
        ]);
    }

    public function update(ServerShadowsocksUpdate $request)
    {
        $request->validate(['id' => 'required|integer']);
        $params = $request->only([
            'show',
        ]);

        $server = ServerShadowsocks::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $server = ServerShadowsocks::find($request->input('id'));
        if (!$server) {
            abort(404, '服务器不存在');
        }
        $data = $server->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at'], $data['parent_id'], $data['sort']);
        if (!empty($data['name'])) $data['name'] .= ' (copy)';
        $data['show'] = 0;
        if (!ServerShadowsocks::create($data)) {
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }
}
