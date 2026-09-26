<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use Illuminate\Support\Facades\Http;
use Throwable;

class AttendanceDeviceConnectionService
{
    /**
     * @return array{ok: bool, status: string, message: string, latency_ms: int|null}
     */
    public function test(AttendanceDevice $device): array
    {
        if (! $device->enabled) {
            return $this->result(false, 'disabled', __('attendance.status.disabled'));
        }

        $definition = config('attendance.connections.'.$device->connection_type);

        if (! is_array($definition)) {
            return $this->result(false, 'invalid', __('attendance.status.invalid_connection'));
        }

        $transport = $definition['transport'] ?? null;
        $started = microtime(true);

        try {
            if ($transport === 'push') {
                return $this->persist(
                    $device,
                    $this->result(true, 'waiting_for_push', __('attendance.status.push_ready'), $started),
                    false,
                );
            }

            if ($transport === 'tcp') {
                $host = trim((string) $device->host);
                $port = (int) $device->port;

                if ($host === '' || $port < 1) {
                    return $this->persist($device, $this->result(false, 'invalid', __('attendance.status.host_port_required')));
                }

                $errno = 0;
                $error = '';
                $socket = @fsockopen($host, $port, $errno, $error, max(1, (int) $device->timeout_seconds));

                if (! is_resource($socket)) {
                    return $this->persist(
                        $device,
                        $this->result(false, 'offline', __('attendance.status.tcp_failed', ['error' => $error ?: (string) $errno]), $started),
                    );
                }

                fclose($socket);

                return $this->persist(
                    $device,
                    $this->result(true, 'online', __('attendance.status.tcp_ok'), $started),
                    true,
                );
            }

            if ($transport === 'http') {
                $url = trim((string) $device->base_url);

                if ($url === '' || ! preg_match('/^https?:\/\//i', $url)) {
                    return $this->persist($device, $this->result(false, 'invalid', __('attendance.status.url_required')));
                }

                $request = Http::timeout(max(1, (int) $device->timeout_seconds))
                    ->connectTimeout(max(1, min(5, (int) $device->timeout_seconds)))
                    ->withOptions(['verify' => (bool) $device->tls_verify])
                    ->acceptJson();

                if ($device->username !== null && $device->username !== '' && $device->password !== null) {
                    $request = $request->withBasicAuth($device->username, $device->password);
                }

                if ($device->api_key !== null && $device->api_key !== '') {
                    $request = $request->withHeaders(['X-API-Key' => $device->api_key]);
                }

                $response = $request->get($url);
                $reachable = $response->status() < 500;

                return $this->persist(
                    $device,
                    $this->result(
                        $reachable,
                        $reachable ? 'online' : 'error',
                        __('attendance.status.http_result', ['status' => $response->status()]),
                        $started,
                    ),
                    $reachable,
                );
            }

            return $this->persist($device, $this->result(false, 'invalid', __('attendance.status.invalid_connection')));
        } catch (Throwable $exception) {
            report($exception);

            return $this->persist(
                $device,
                $this->result(false, 'error', __('attendance.status.exception', ['error' => $exception->getMessage()]), $started),
            );
        }
    }

    private function result(bool $ok, string $status, string $message, ?float $started = null): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'message' => $message,
            'latency_ms' => $started === null ? null : (int) round((microtime(true) - $started) * 1000),
        ];
    }

    private function persist(AttendanceDevice $device, array $result, bool $seen = false): array
    {
        $device->forceFill([
            'last_status' => $result['status'],
            'last_message' => $result['message'],
            'last_seen_at' => $seen ? now() : $device->last_seen_at,
        ])->save();

        return $result;
    }
}
