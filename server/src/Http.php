<?php
declare(strict_types=1);

namespace Place;

/** Minimal HTTP client. Tests replace it with a fake. */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}

final class CurlHttp implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res = curl_exec($ch);
        if ($res === false) {
            error_log('parto-place: HTTP ' . $method . ' ' . parse_url($url, PHP_URL_HOST) . ' failed: ' . curl_error($ch));
            curl_close($ch);
            throw new VendorError('nicht erreichbar');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $res];
    }
}

final class VendorError extends \RuntimeException
{
}

/** Decode a JSON response or throw a readable error. Never includes tokens. */
function json_or_fail(array $res, string $what): mixed
{
    if ($res['status'] === 401 || $res['status'] === 403) {
        throw new VendorError($what . ': Zugang abgelehnt, bitte neu verbinden');
    }
    if ($res['status'] === 429) {
        throw new VendorError($what . ': zu viele Anfragen, später erneut');
    }
    if ($res['status'] >= 400) {
        throw new VendorError($what . ': Fehler ' . $res['status']);
    }
    if ($res['body'] === '') {
        return null;
    }
    $data = json_decode($res['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new VendorError($what . ': unerwartete Antwort');
    }
    return $data;
}
