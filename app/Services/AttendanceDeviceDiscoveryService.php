<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class AttendanceDeviceDiscoveryService
{
    public function requiresLocalBridge(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            if ($long === false) {
                return false;
            }

            $unsigned = (int) sprintf('%u', $long);

            return (($unsigned & 0xFF000000) === 0x0A000000)
                || (($unsigned & 0xFFF00000) === 0xAC100000)
                || (($unsigned & 0xFFFF0000) === 0xC0A80000);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);

            if ($packed === false) {
                return false;
            }

            $first = ord($packed[0]);

            return ($first & 0xFE) === 0xFC;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function discover(string $ip): array
    {
        $ip = trim($ip);

        if (! $this->isAllowedIp($ip)) {
            throw new InvalidArgumentException(__('attendance.discovery.invalid_ip'));
        }

        $openPorts = $this->probePorts($ip);
        $httpEvidence = $this->probeHttp($ip, $openPorts);

        return $this->classify($ip, $openPorts, $httpEvidence);
    }

    /**
     * Pure classification entry point so heuristics stay testable without
     * touching a real network.
     *
     * @param  list<int>  $openPorts
     * @param  list<array{scheme: string, port: int, status: int, text: string, isapi?: bool}>  $httpEvidence
     * @return array<string, mixed>
     */
    public function classify(string $ip, array $openPorts, array $httpEvidence = []): array
    {
        $scores = [];
        $evidence = [];
        $portDefinitions = config('attendance.discovery.ports', []);

        foreach ($openPorts as $port) {
            $definition = $portDefinitions[$port] ?? null;

            if (! is_array($definition)) {
                continue;
            }

            $brand = $definition['brand'] ?? null;

            if ($brand) {
                $scores[$brand] = max($scores[$brand] ?? 0, (int) ($definition['weight'] ?? 0));
                $evidence[] = __('attendance.discovery.port_evidence', ['port' => $port]);
            }
        }

        $fingerprints = config('attendance.discovery.fingerprints', []);

        foreach ($httpEvidence as $http) {
            $text = strtolower((string) ($http['text'] ?? ''));

            if (($http['isapi'] ?? false) === true) {
                $scores['hikvision'] = max($scores['hikvision'] ?? 0, 97);
                $evidence[] = __('attendance.discovery.isapi_evidence');
            }

            foreach ($fingerprints as $brand => $patterns) {
                foreach ($patterns as $pattern) {
                    if ($pattern !== '' && str_contains($text, strtolower($pattern))) {
                        $scores[$brand] = max($scores[$brand] ?? 0, 94);
                        $evidence[] = __('attendance.discovery.fingerprint_evidence', ['value' => $pattern]);
                        break;
                    }
                }
            }
        }

        arsort($scores);
        $brand = array_key_first($scores);
        $confidence = $brand === null ? 0 : min(99, (int) $scores[$brand]);

        if ($brand === null && ($openPorts !== [] || $httpEvidence !== [])) {
            $brand = 'generic';
            $confidence = 35;
            $evidence[] = __('attendance.discovery.generic_evidence');
        }

        [$connection, $port, $baseUrl] = $this->suggestConnection($ip, $brand, $openPorts, $httpEvidence);

        $brands = config('attendance.brands', []);
        $connections = config('attendance.connections', []);

        return [
            'ip' => $ip,
            'reachable' => $openPorts !== [] || $httpEvidence !== [],
            'brand' => $brand,
            'brand_label' => $brand ? ($brands[$brand]['label'] ?? $brand) : null,
            'connection_type' => $connection,
            'connection_label' => $connection ? ($connections[$connection]['label'] ?? $connection) : null,
            'port' => $port,
            'base_url' => $baseUrl,
            'open_ports' => array_values(array_unique($openPorts)),
            'confidence' => $confidence,
            'confidence_label' => $this->confidenceLabel($confidence),
            'evidence' => array_values(array_unique($evidence)),
            'alternatives' => collect($scores)
                ->take(3)
                ->map(fn (int $score, string $candidate): array => [
                    'brand' => $candidate,
                    'label' => $brands[$candidate]['label'] ?? $candidate,
                    'confidence' => min(99, $score),
                ])
                ->values()
                ->all(),
            'warning' => $brand === 'zkteco' && in_array(4370, $openPorts, true)
                ? __('attendance.discovery.zk_family_warning')
                : __('attendance.discovery.best_effort_warning'),
        ];
    }

    /**
     * @return list<int>
     */
    protected function probePorts(string $ip): array
    {
        $open = [];
        $timeout = (float) config('attendance.discovery.tcp_timeout_seconds', 0.35);

        foreach (array_keys(config('attendance.discovery.ports', [])) as $port) {
            $target = str_contains($ip, ':') ? '['.$ip.']' : $ip;
            $errno = 0;
            $error = '';
            $socket = @fsockopen($target, (int) $port, $errno, $error, $timeout);

            if (is_resource($socket)) {
                fclose($socket);
                $open[] = (int) $port;
            }
        }

        sort($open);

        return $open;
    }

    /**
     * @param  list<int>  $openPorts
     * @return list<array{scheme: string, port: int, status: int, text: string, isapi?: bool}>
     */
    protected function probeHttp(string $ip, array $openPorts): array
    {
        $results = [];
        $httpPorts = [
            80 => 'http',
            443 => 'https',
            8000 => 'http',
            8080 => 'http',
            8443 => 'https',
            3000 => 'http',
            3002 => 'https',
            9000 => 'http',
        ];

        foreach ($httpPorts as $port => $scheme) {
            if (! in_array($port, $openPorts, true)) {
                continue;
            }

            $base = $this->url($scheme, $ip, $port);

            if ($response = $this->safeGet($base.'/')) {
                $results[] = $this->httpEvidence($scheme, $port, $response);
            }

            if (in_array($port, [80, 443, 8000, 8080, 8443], true)) {
                if ($response = $this->safeGet($base.'/ISAPI/System/deviceInfo')) {
                    $text = $this->responseText($response);
                    $isapi = in_array($response->status(), [200, 401, 403], true)
                        && (
                            str_contains(strtolower($text), 'isapi')
                            || str_contains(strtolower($text), 'hikvision')
                            || str_contains(strtolower($response->header('WWW-Authenticate', '')), 'hikvision')
                        );

                    $results[] = [
                        ...$this->httpEvidence($scheme, $port, $response),
                        'isapi' => $isapi,
                    ];
                }
            }
        }

        return $results;
    }

    protected function safeGet(string $url): ?Response
    {
        try {
            return Http::timeout((float) config('attendance.discovery.http_timeout_seconds', 1.25))
                ->connectTimeout(1)
                ->withOptions([
                    'verify' => false,
                    'allow_redirects' => false,
                ])
                ->withHeaders([
                    'User-Agent' => 'BusinessOS-Attendance-Discovery/1.0',
                    'Accept' => 'text/html,application/json,application/xml,text/xml,*/*',
                ])
                ->get($url);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{scheme: string, port: int, status: int, text: string}
     */
    protected function httpEvidence(string $scheme, int $port, Response $response): array
    {
        return [
            'scheme' => $scheme,
            'port' => $port,
            'status' => $response->status(),
            'text' => $this->responseText($response),
        ];
    }

    protected function responseText(Response $response): string
    {
        $body = substr((string) $response->body(), 0, 16384);
        $headers = implode(' ', [
            $response->header('Server', ''),
            $response->header('WWW-Authenticate', ''),
            $response->header('X-Powered-By', ''),
        ]);

        return trim($headers.' '.$body);
    }

    /**
     * @param  list<int>  $openPorts
     * @param  list<array{scheme: string, port: int, status: int, text: string, isapi?: bool}>  $httpEvidence
     * @return array{0: string|null, 1: int|null, 2: string|null}
     */
    protected function suggestConnection(string $ip, ?string $brand, array $openPorts, array $httpEvidence): array
    {
        if (in_array($brand, ['zkteco', 'essl', 'realtime', 'bioenable'], true) && in_array(4370, $openPorts, true)) {
            return ['zkteco_tcp', 4370, null];
        }

        if ($brand === 'anviz' && in_array(5010, $openPorts, true)) {
            return ['anviz_tcp', 5010, null];
        }

        if ($brand === 'suprema' && in_array(51211, $openPorts, true)) {
            return ['suprema_device_tcp', 51211, null];
        }

        $preferredHttp = collect($httpEvidence)
            ->first(fn (array $item): bool => in_array((int) $item['port'], [443, 80, 8443, 8080, 8000, 3002, 3000, 9000], true));

        if (is_array($preferredHttp)) {
            $port = (int) $preferredHttp['port'];
            $scheme = (string) $preferredHttp['scheme'];
            $baseUrl = $this->url($scheme, $ip, $port);

            return [
                match ($brand) {
                    'hikvision' => 'isapi',
                    'suprema' => 'biostar2_api',
                    'anviz' => 'crosschex_api',
                    default => 'http_api',
                },
                $port,
                $baseUrl,
            ];
        }

        if ($openPorts !== []) {
            return ['tcp_socket', (int) $openPorts[0], null];
        }

        return [null, null, null];
    }

    protected function url(string $scheme, string $ip, int $port): string
    {
        $host = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $default = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        return $scheme.'://'.$host.($default ? '' : ':'.$port);
    }

    protected function confidenceLabel(int $confidence): string
    {
        return match (true) {
            $confidence >= 90 => __('attendance.discovery.confidence_high'),
            $confidence >= 70 => __('attendance.discovery.confidence_medium'),
            $confidence > 0 => __('attendance.discovery.confidence_low'),
            default => __('attendance.discovery.confidence_none'),
        };
    }

    protected function isAllowedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isAllowedIpv4($ip);
        }

        $packed = inet_pton($ip);

        if ($packed === false || $ip === '::' || $ip === '::1') {
            return false;
        }

        // Reject IPv4-mapped/compatible loopback or link-local addresses too.
        $prefix10 = substr($packed, 0, 10);
        $prefix12 = substr($packed, 0, 12);

        if (
            ($prefix10 === str_repeat("\0", 10) && substr($packed, 10, 2) === "\xff\xff")
            || $prefix12 === str_repeat("\0", 12)
        ) {
            $v4 = inet_ntop(substr($packed, 12, 4));

            return is_string($v4) && $this->isAllowedIpv4($v4);
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        $isLinkLocal = $first === 0xFE && ($second & 0xC0) === 0x80;
        $isMulticast = $first === 0xFF;

        return ! $isLinkLocal && ! $isMulticast;
    }

    protected function isAllowedIpv4(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return false;
        }

        $unsigned = (int) sprintf('%u', $long);
        $first = $unsigned >> 24;
        $firstTwo = $unsigned >> 16;

        return $first !== 0
            && $first !== 127
            && $firstTwo !== ((169 << 8) | 254)
            && $first < 224;
    }
}
