<?php

namespace App\Protocols;

use App\Utils\Helper;

class Shadowrocket
{
    public $flag = 'shadowrocket';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        // list=1 保留旧的纯节点列表行为,供已导入的客户端和外部引用回退
        if ((string)request()->query('list') === '1') {
            return $this->buildNodeList();
        }
        return $this->buildConf();
    }

    /**
     * 下发 Shadowrocket 的 .conf 配置(Surge 风格,含 [Rule] 分流规则)。
     * 只给节点不给规则时,客户端「全局路由=配置」下无规则可用,
     * 流量会按本地默认规则直连,表现为「节点能连通但打不开 Google」。
     */
    private function buildConf()
    {
        $user = $this->user;
        $appName = config('v2board.app_name', 'V2Board');

        $confPath = null;
        foreach ([
            base_path() . '/resources/rules/custom.shadowrocket.conf',
            base_path() . '/resources/rules/default.shadowrocket.conf',
        ] as $path) {
            if (\File::exists($path)) {
                $confPath = $path;
                break;
            }
        }
        // 模板缺失时退回节点列表,宁可没有规则也不能让订阅开天窗
        if ($confPath === null) {
            return $this->buildNodeList();
        }

        header("subscription-userinfo: upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
        header('profile-update-interval: 24');
        header("content-disposition:attachment;filename*=UTF-8''" . rawurlencode($appName) . ".conf");

        $proxies = '';
        $proxyGroup = '';
        foreach ($this->servers as $item) {
            if (($item['type'] ?? null) === 'v2node' && isset($item['protocol'])) {
                $item['type'] = $item['protocol'];
            }
            switch ($item['type']) {
                case 'vless':
                    $line = self::buildVless($user['uuid'], $item);
                    break;
                case 'vmess':
                    $line = Surge::buildVmess($user['uuid'], $item);
                    break;
                case 'trojan':
                    $line = Surge::buildTrojan($user['uuid'], $item);
                    break;
                case 'shadowsocks':
                    $line = Surge::buildShadowsocks($user['uuid'], $item);
                    break;
                case 'hysteria':
                    // Shadowrocket 只支持 hysteria2
                    $line = (int)($item['version'] ?? 0) === 2 ? Surge::buildHysteria($user['uuid'], $item) : '';
                    break;
                default:
                    $line = '';
            }
            if ($line === '') continue;
            $proxies .= $line;
            $proxyGroup .= $item['name'] . ', ';
        }

        $config = file_get_contents($confPath);
        $config = str_replace('$subs_link', Helper::getSubscribeUrl($user['token']), $config);
        // HTTP_HOST 可能带端口,而 DOMAIN 规则不接受端口,这里只取主机名
        $config = str_replace('$subs_domain', explode(':', $_SERVER['HTTP_HOST'] ?? '')[0], $config);
        $config = str_replace('$proxies', rtrim($proxies), $config);
        $config = str_replace('$proxy_group', rtrim($proxyGroup, ', '), $config);
        $config = str_replace('$app_name', $appName, $config);
        return $config;
    }

    /**
     * 拼 [Proxy] 段的 vless 行。
     * uuid 用 username= —— 与本仓库 Surge::buildVmess 的写法一致;
     * REALITY 的 pbk/sid/fp 与 vless:// 分享链接同名。
     */
    public static function buildVless($uuid, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? ($server['tlsSettings'] ?? []);
        $config = [
            "{$server['name']}=vless",
            "{$server['host']}",
            "{$server['port']}",
            "username={$uuid}",
        ];

        if ($server['tls']) {
            $config[] = 'tls=true';
            $sni = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? '';
            if ($sni !== '') $config[] = "sni={$sni}";
            if ((int)$server['tls'] === 2) {
                $config[] = 'security=reality';
                $pbk = $tlsSettings['public_key'] ?? $tlsSettings['publicKey'] ?? '';
                $sid = $tlsSettings['short_id'] ?? $tlsSettings['shortId'] ?? '';
                if ($pbk !== '') $config[] = "pbk={$pbk}";
                if ($sid !== '') $config[] = "sid={$sid}";
                $config[] = 'fp=' . ($tlsSettings['fingerprint'] ?? 'chrome');
            } else {
                // REALITY 不允许 insecure,其余 tls 模式沿用节点设置
                $allowInsecure = (int)($tlsSettings['allow_insecure'] ?? $tlsSettings['allowInsecure'] ?? 0);
                $config[] = 'skip-cert-verify=' . ($allowInsecure ? 'true' : 'false');
            }
        }

        if (!empty($server['flow'])) {
            $config[] = "flow={$server['flow']}";
        }

        $networkSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
        if ($server['network'] === 'ws') {
            $config[] = 'ws=true';
            if (!empty($networkSettings['path']))
                $config[] = "ws-path={$networkSettings['path']}";
            if (!empty($networkSettings['headers']['Host']))
                $config[] = "ws-headers=Host:{$networkSettings['headers']['Host']}";
        } elseif ($server['network'] === 'grpc') {
            $config[] = 'grpc=true';
            if (!empty($networkSettings['serviceName']))
                $config[] = "grpc-service-name={$networkSettings['serviceName']}";
        }

        $config[] = 'tfo=true';
        $config[] = 'udp=true';
        return implode(',', $config) . "\r\n";
    }

    private function buildNodeList()
    {
        $user = $this->user;

        $uri = '';
        //display remaining traffic and expire date
        $upload = round($user['u'] / (1024*1024*1024), 2);
        $download = round($user['d'] / (1024*1024*1024), 2);
        $totalTraffic = round($user['transfer_enable'] / (1024*1024*1024), 2);
        $expiredDate = date('Y-m-d', $user['expired_at']);
        $uri .= "STATUS=🚀↑:{$upload}GB,↓:{$download}GB,TOT:{$totalTraffic}GB💡Expires:{$expiredDate}\r\n";

        foreach ($this->servers as $server) {
            if ($server['type'] === 'vmess' || ($server['type'] === 'v2node' && $server['protocol'] === 'vmess')) {
                $uri .= self::buildVmessUri($user['uuid'], $server);
            } else {
                $uri .= Helper::buildUri($this->user['uuid'], $server);
            }
        }
        return base64_encode($uri);
    }

    /** 节点列表模式专用:拼 vmess:// 分享链接,和 [Proxy] 段的行格式不是一回事 */
    public static function buildVmessUri($uuid, $server)
    {
        $userinfo = base64_encode('auto:' . $uuid . '@' . $server['host'] . ':' . $server['port']);
        $config = [
            'tfo' => 1,
            'remark' => $server['name'],
            'alterId' => 0
        ];
        // 提前初始化,避免后续 grpc 分支引用未定义变量
        $tlsSettings = $server['tls_settings'] ?? ($server['tlsSettings'] ?? []);
        if ($server['tls']) {
            $config['tls'] = 1;
            $config['allowInsecure'] = (int)($tlsSettings['allow_insecure'] ?? $tlsSettings['allowInsecure'] ?? 0);
            $config['peer'] = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? '';
        }
        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (isset($tcpSettings['header']['type']) && !empty($tcpSettings['header']['type']))
                $config['obfs'] = $tcpSettings['header']['type'];
            if (isset($tcpSettings['header']['request']['path'][0]) && !empty($tcpSettings['header']['request']['path'][0]))
                $config['path'] = $tcpSettings['header']['request']['path'][0];
            if (isset($tcpSettings['header']['request']['headers']['Host'][0]))
                $config['obfsParam'] = $tcpSettings['header']['request']['headers']['Host'][0];
        }
        if ($server['network'] === 'ws') {
            $config['obfs'] = "websocket";
            $wsSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                $config['path'] = $wsSettings['path'];
            if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                $config['obfsParam'] = $wsSettings['headers']['Host'];
            if (isset($wsSettings['security']))
                $config['method'] = $wsSettings['security'];
        }
        if ($server['network'] === 'grpc') {
            $config['obfs'] = "grpc";
            $grpcSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (isset($grpcSettings['serviceName']) && !empty($grpcSettings['serviceName']))
                $config['path'] = $grpcSettings['serviceName'];
            $sni = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? '';
            $config['host'] = $sni !== '' ? $sni : $server['host'];
        }
        $query = http_build_query($config, '', '&', PHP_QUERY_RFC3986);
        $uri = "vmess://{$userinfo}?{$query}";
        $uri .= "\r\n";
        return $uri;
    }

}
