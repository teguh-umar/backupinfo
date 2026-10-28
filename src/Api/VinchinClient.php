<?php

namespace GlpiPlugin\Backupinfo\Api;

use GlpiPlugin\Backupinfo\Connection;

class VinchinClient
{
    private array $config;
    private string $baseUrl;
    private string $token;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->baseUrl = rtrim($config['api_url'] ?? '', '/');
        $this->token = $this->decrypt($config['token'] ?? '');
    }

    private function decrypt($value)
    {
        if ($value === '' || $value === null) {
            return '';
        }
        if (class_exists('GLPIKey')) {
            try {
                return (new \GLPIKey())->decrypt($value);
            } catch (\Throwable $e) {
                return $value;
            }
        }
        return $value;
    }

    private function request(string $method, string $path, array $data = []): array
    {
        if ($this->baseUrl === '') {
            throw new \Exception('Vinchin API URL not configured');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \Exception('cURL error: ' . $err);
        }

        $json = json_decode($response, true);
        if ($json === null) {
            return ['raw' => $response, 'http_code' => $httpCode];
        }

        $json['_http_code'] = $httpCode;
        return $json;
    }

    public function getProtectedAssets(): array
    {
        // Adjust endpoints to match Vinchin API version if needed
        return $this->request('GET', '/api/v1/protected/vms');
    }

    public function getBackupJobs(array $params = []): array
    {
        return $this->request('GET', '/api/v1/jobs/history', $params);
    }

    public function getStorage(): array
    {
        return $this->request('GET', '/api/v1/storage/pools');
    }

    public function getDashboard(): array
    {
        return $this->request('GET', '/api/v1/dashboard/summary');
    }
}
