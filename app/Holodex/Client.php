<?php

namespace App\Holodex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class Client
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send($path, fn (PendingRequest $http) => $http->get($path, $query));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->send($path, fn (PendingRequest $http) => $http->post($path, $body));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<mixed>
     */
    private function send(string $path, callable $call): array
    {
        $http = Http::baseUrl(config('hololearn.holodex.base_url'))
            ->withHeaders(['X-APIKEY' => (string) config('hololearn.holodex.key')])
            ->withUserAgent((string) config('hololearn.holodex.user_agent'))
            ->acceptJson()
            ->timeout(30);

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new HolodexException(0, $path, 'keine Verbindung');
        } finally {
            usleep((int) config('hololearn.holodex.pause_ms') * 1000);
        }

        if (! $response->successful()) {
            throw new HolodexException($response->status(), $path, 'HTTP '.$response->status());
        }

        $data = json_decode($response->body(), true);
        if (! is_array($data)) {
            throw new HolodexException($response->status(), $path, 'Antwort ist kein JSON');
        }

        return $data;
    }
}
