<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Http\Controllers\V1\Server\Concerns\EtagHelpers;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use MessagePack\Packer;

class UniProxyController extends Controller
{
    use EtagHelpers;
    private $nodeType;
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
        $this->nodeType = $request->input('node_type');
        if ($this->nodeType === 'v2ray') $this->nodeType = 'vmess';
        if ($this->nodeType === 'hysteria2') $this->nodeType = 'hysteria';
        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, $this->nodeType);
        if (!$this->nodeInfo) abort(404, 'server is not exist');
    }

    // 后端获取用户
    public function user(Request $request)
    {
        ini_set('memory_limit', -1);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);
        $users = $this->serverService->getAvailableUsers($this->nodeInfo->group_id)
            ->map(function ($user) {
                return array_filter($user->toArray(), function ($v) {
                    return !is_null($v);
                });
            })->toArray();

        $response['users'] = $users;
        if (strpos($request->header('X-Response-Format'), 'msgpack') !== false) {
            $packer = new Packer();
            $response = $packer->pack($response);
            $eTag = sha1($response);
            if ($this->ifNoneMatchHit($request, $eTag)) {
                abort(304);
            }

            return response($response, 200, ['Content-Type' => 'application/x-msgpack'])->header('ETag', "\"{$eTag}\"");
        } else {
            $eTag = sha1(json_encode($response));
            if ($this->ifNoneMatchHit($request, $eTag)) {
                abort(304);
            }

            return response($response)->header('ETag', "\"{$eTag}\"");
        }
    }

    // 后端提交数据
    public function push(Request $request)
    {
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        // 空 body 不更新 ONLINE_USER，避免节点重启/健康检查把计数清零
        if (!is_array($data) || empty($data)) {
            return response(['data' => true]);
        }
        // 过滤畸形 entry: uid 必须数字, value 必须 [u, d] 形态
        foreach ($data as $uid => $v) {
            if (!is_numeric($uid) || !is_array($v) || count($v) < 2) {
                unset($data[$uid]);
            }
        }
        if (empty($data)) {
            return response(['data' => true]);
        }
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_ONLINE_USER', $this->nodeInfo->id), count($data), 3600);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_PUSH_AT', $this->nodeInfo->id), time(), 3600);
        $userService = new UserService();
        $userService->trafficFetch($this->nodeInfo->toArray(), $this->nodeType, $data);

        return response([
            'data' => true
        ]);
    }

    // 后端获取在线数据
    public function alivelist(Request $request)
    {
        $deviceLimitMode = (int)config('v2board.device_limit_mode', 0);
        // device_limit_mode 进入 cache key 防止模式切换污染；TTL 缩短到 5s 减少踢人/解禁的体验滞后
        $alive = Cache::remember('ALIVE_LIST_M' . $deviceLimitMode, 5, function () {
            $userService = new UserService();
            $users = $userService->getDeviceLimitedUsers();

            if ($users->isEmpty()) {
                return [];
            }

            $keys = [];
            $idMap = [];
            foreach ($users as $user) {
                $key = 'ALIVE_IP_USER_' . $user->id;
                $keys[] = $key;
                $idMap[$key] = $user->id;
            }

            $results = Cache::many($keys);
            $alive = [];
            foreach ($results as $key => $data) {
                if (is_array($data) && isset($data['alive_ip'])) {
                    $alive[$idMap[$key]] = $data['alive_ip'];
                }
            }
            return $alive;
        });
        return response()->json(['alive' => (object)$alive]);
    }

    // 后端提交在线数据
    public function alive(Request $request)
    {
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if (empty($data)) {
            return response(['data' => true]);
        }
        if (!is_array($data)) {
            return response(['error' => 'Invalid online data format'], 400);
        }
        $updateAt = time();
        $deviceLimitMode = (int)config('v2board.device_limit_mode', 0);

        foreach ($data as $uid => $ips) {
            if (!is_numeric($uid) || !is_array($ips)) {
                continue;
            }
            $key = 'ALIVE_IP_USER_' . $uid;
            // 按 user 维度加锁,消除多节点并发上报的 read-modify-write 竞态
            // 使用闭包形式,Laravel 内部只在持有锁时 release,避免 file cache driver 下误删他人锁
            try {
                Cache::lock('LOCK_' . $key, 5)->block(2, function () use ($key, $ips, $updateAt, $deviceLimitMode) {
                    $ips_array = Cache::get($key) ?? [];
                    if (!is_array($ips_array)) {
                        $ips_array = [];
                    }

                    $ips_array[$this->nodeType . $this->nodeId] = [
                        'aliveips' => $ips,
                        'lastupdateAt' => $updateAt,
                    ];
                    foreach ($ips_array as $nodetypeid => $oldips) {
                        if ($nodetypeid !== 'alive_ip' && is_array($oldips) && ($updateAt - ($oldips['lastupdateAt'] ?? 0) > 100)) {
                            unset($ips_array[$nodetypeid]);
                        }
                    }

                    $count = 0;
                    if ($deviceLimitMode === 1) {
                        $ipmap = [];
                        foreach ($ips_array as $nodetypeid => $newdata) {
                            if ($nodetypeid !== 'alive_ip' && is_array($newdata) && isset($newdata['aliveips'])) {
                                foreach ($newdata['aliveips'] as $ip_NodeId) {
                                    $ip = explode("_", $ip_NodeId)[0];
                                    $ipmap[$ip] = 1;
                                }
                            }
                        }
                        $count = count($ipmap);
                    } else {
                        foreach ($ips_array as $nodetypeid => $newdata) {
                            if ($nodetypeid !== 'alive_ip' && is_array($newdata) && isset($newdata['aliveips'])) {
                                $count += count($newdata['aliveips']);
                            }
                        }
                    }
                    $ips_array['alive_ip'] = $count;

                    Cache::put($key, $ips_array, 120);
                });
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                // 拿不到锁就跳过本次写入,下次 push 还会再上报
                continue;
            }
        }

        return response(['data' => true]);
    }

    // 后端获取配置
    public function config(Request $request)
    {
        $response = [];
        switch ($this->nodeType) {
            case 'shadowsocks':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'cipher' => $this->nodeInfo->cipher,
                    'obfs' => $this->nodeInfo->obfs,
                    'obfs_settings' => $this->nodeInfo->obfs_settings
                ];

                if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
                }
                if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
                }
                break;
            case 'vmess':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    // 双 key 向后兼容: networkSettings/tlsSettings 给 XrayR 0.9.4 (NewV2board parser 期望 camelCase),
                    // network_settings/tls_settings 给 v2node/wyx fork (snake_case)。两个 key 指向同一份数据, 无副作用。
                    'networkSettings' => $this->nodeInfo->networkSettings,
                    'network_settings' => $this->nodeInfo->networkSettings,
                    'tls' => $this->nodeInfo->tls,
                    'tlsSettings' => $this->nodeInfo->tlsSettings,
                    'tls_settings' => $this->nodeInfo->tlsSettings,
                ];
                break;
            case 'vless':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    // 双 key 向后兼容, 同 vmess 分支理由
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'network_settings' => $this->nodeInfo->network_settings,
                    'tls' => $this->nodeInfo->tls,
                    'flow' => $this->nodeInfo->flow,
                    'tlsSettings' => $this->nodeInfo->tls_settings,
                    'tls_settings' => $this->nodeInfo->tls_settings,
                    'encryption' => $this->nodeInfo->encryption,
                    'encryption_settings' => $this->nodeInfo->encryption_settings,
                ];
                break;
            case 'trojan':
                $response = [
                    'host' => $this->nodeInfo->host,
                    'network' => $this->nodeInfo->network,
                    // 双 key 向后兼容, 同 vmess 分支理由
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'network_settings' => $this->nodeInfo->network_settings,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                ];
                break;
            case 'tuic':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'congestion_control' => $this->nodeInfo->congestion_control,
                    'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
                ];
                break;
            case 'hysteria':
                $response = [
                    'version' => $this->nodeInfo->version,
                    'host' => $this->nodeInfo->host,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'up_mbps' => $this->nodeInfo->up_mbps,
                    'down_mbps' => $this->nodeInfo->down_mbps
                ];
                if ($this->nodeInfo->version == 1) {
                    $response['obfs'] = $this->nodeInfo->obfs_password ?? null;
                } elseif ($this->nodeInfo->version == 2) {
                    if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
                        $response['ignore_client_bandwidth'] = true;
                    } else {
                        $response['ignore_client_bandwidth'] = false;
                    }
                    $response['obfs'] = $this->nodeInfo->obfs ?? null;
                    $response['obfs-password'] = $this->nodeInfo->obfs_password ?? null;
                }
                break;
            case 'anytls':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'padding_scheme' => $this->nodeInfo->padding_scheme
                ];
                break;
            default:
                abort(400, 'unsupported node_type: ' . $this->nodeType);
        }
        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60)
        ];
        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }
        $eTag = sha1(json_encode($response));
        if ($this->ifNoneMatchHit($request, $eTag)) {
            abort(304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }

}
