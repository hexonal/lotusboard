<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use Illuminate\Http\Request;
use App\Utils\Helper;

class ServerController extends Controller
{
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    public function __construct(Request $request)
    {
        $token = (string)$request->input('token', '');
        $expected = (string)config('v2board.server_token', '');
        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
            abort(403, 'token invalid');
        }

        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, "v2node");

        if (!$this->nodeInfo) {
            abort(404, 'server is not exist');
        }
    }

    // 后端获取配置
    public function config(Request $request)
    {
        $response = [
            'listen_ip' => $this->nodeInfo->listen_ip,
            'server_port' => $this->nodeInfo->server_port,
            'network' => $this->nodeInfo->network,
            'network_settings' => $this->nodeInfo->network_settings,
            'protocol' => $this->nodeInfo->protocol,
            'tls' => $this->nodeInfo->tls,
            'tls_settings' => $this->nodeInfo->tls_settings,
            'encryption' => $this->nodeInfo->encryption,
            'encryption_settings' => $this->nodeInfo->encryption_settings,
            'flow' => $this->nodeInfo->flow,
            'cipher' => $this->nodeInfo->cipher,
            'congestion_control' => $this->nodeInfo->congestion_control,
            'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
            'up_mbps' => $this->nodeInfo->up_mbps,
            'down_mbps' => $this->nodeInfo->down_mbps,
            'obfs' => $this->nodeInfo->obfs,
            'obfs_password' => $this->nodeInfo->obfs_password,
            'padding_scheme' => $this->nodeInfo->padding_scheme
        ];

        if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
        }

        if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
        }

        if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
            $response['ignore_client_bandwidth'] = true;
        } else {
            $response['ignore_client_bandwidth'] = false;
        }

        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60),
            'node_report_min_traffic' => (int)config('v2board.server_node_report_min_traffic', 0),
            'device_online_min_traffic' => (int)config('v2board.server_device_online_min_traffic', 0)
        ];

        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }

        $rsp = json_encode($response);
        $eTag = sha1($rsp);

        // ETag 按逗号 split + 去引号 + hash_equals
        $header = (string)$request->header('If-None-Match', '');
        if ($header !== '') {
            foreach (explode(',', $header) as $token) {
                $token = trim($token, " \t\"");
                if ($token !== '' && hash_equals($eTag, $token)) {
                    return response('', 304)->header('ETag', "\"{$eTag}\"");
                }
            }
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }
}
