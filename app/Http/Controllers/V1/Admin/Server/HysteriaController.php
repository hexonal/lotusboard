<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerHysteria;
use App\Utils\Helper;
use Illuminate\Http\Request;

class HysteriaController extends Controller
{
    public function save(Request $request)
    {
        $params = $request->validate([
            'show' => '',
            'name' => 'required',
            'version' => 'required|in:1,2',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'up_mbps' => 'nullable|numeric',
            'down_mbps' => 'nullable|numeric',
            'obfs' => 'nullable',
            'obfs_password' => 'nullable',
            'server_name' => 'nullable',
            'insecure' => 'required|in:0,1'
        ]);

        if (!isset($params['up_mbps'])) {
            $params['up_mbps'] = 0;
        }
        if (!isset($params['down_mbps'])) {
            $params['down_mbps'] = 0;
        }

        if (isset($params['obfs'])) {
            // 不要用客户端可控的 created_at 当熵源,改用安全随机
            if (!isset($params['obfs_password'])) $params['obfs_password'] = \Illuminate\Support\Str::random(16);
        } else {
            $params['obfs_password'] = null;
        }

        if ($request->input('id')) {
            $server = ServerHysteria::find($request->input('id'));
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

        if (!ServerHysteria::create($params)) {
            abort(500, '创建失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $server = ServerHysteria::find($request->input('id'));
        if (!$server) {
            abort(404, '节点ID不存在');
        }
        return response([
            'data' => $server->delete()
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'show' => 'in:0,1'
        ], [
            'show.in' => '显示状态格式不正确'
        ]);
        $params = $request->only([
            'show',
        ]);

        $server = ServerHysteria::find($request->input('id'));

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
        $server = ServerHysteria::find($request->input('id'));
        if (!$server) {
            abort(404, '服务器不存在');
        }
        $data = $server->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at'], $data['parent_id'], $data['sort']);
        if (!empty($data['name'])) $data['name'] .= ' (copy)';
        $data['show'] = 0;
        if (!ServerHysteria::create($data)) {
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }
}
