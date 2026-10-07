<?php

namespace App\Services\Mihcm;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class MihcmClient
{
    private ?string $token = null;

    private function http(bool $withToken = true): PendingRequest
    {
        $key = trim((string) config('mihcm.primary_key'));
        $req = Http::baseUrl(rtrim(config('mihcm.base_url'), '/'))
            ->timeout(config('mihcm.timeout'))->connectTimeout(10)
            ->withOptions(['verify' => (bool) config('mihcm.verify_ssl')])
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $key])
            ->acceptJson()->retry(2, 1000, throw: false);

        return $withToken ? $req->withToken($this->token()) : $req;
    }

    public function token(bool $refresh = false): string
    {
        if ($this->token && ! $refresh) {
            return $this->token;
        }
        if (! config('mihcm.primary_key') || ! config('mihcm.secondary_key')) {
            throw new MihcmException('MiHCM keys are not configured (MIHCM_PRIMARY_KEY / MIHCM_SECONDARY_KEY).');
        }

        try {
            $res = $this->http(false)->get('/oauth2/token', [
                'grantType'    => 'client_credentials',
                'clientId'     => trim(config('mihcm.primary_key')),
                'clientSecret' => trim(config('mihcm.secondary_key')),
            ]);
        } catch (MihcmException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MihcmException($this->explain($e), 0, $e);
        }

        if (! $res->successful() || ! $res->json('accessToken')) {
            throw new MihcmException('MiHCM authentication failed (HTTP '.$res->status().').');
        }

        return $this->token = $res->json('accessToken');
    }

    /** One page of employees. An empty array means "no more pages". Any error THROWS (never looks like "finished"). */
    public function batch(int $number): array
    {
        $res = $this->get("/profile/employees/batch/{$number}");

        if ($res->status() === 401) { // token expired mid-sync
            $this->token(refresh: true);
            $res = $this->get("/profile/employees/batch/{$number}");
        }
        if (! $res->successful()) {
            throw new MihcmException("MiHCM batch {$number} failed (HTTP {$res->status()}).");
        }

        $employees = $res->json('employees');

        return is_array($employees) ? $employees : [];
    }

    /** @return array{0: array, 1: int} [all employee records, number of batches] */
    public function allEmployees(): array
    {
        $all = [];
        $batches = 0;

        for ($n = 0; $n < config('mihcm.max_batches'); $n++) {
            $records = $this->batch($n);
            if (! $records) {
                return [$all, $batches];
            }
            $batches++;
            array_push($all, ...$records);
        }

        throw new MihcmException('Stopped after '.config('mihcm.max_batches').' batches (MIHCM max_batches). Check the API or raise the limit.');
    }

    private function get(string $path): Response
    {
        try {
            return $this->http()->get($path);
        } catch (MihcmException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MihcmException($this->explain($e), 0, $e);
        }
    }

    private function explain(Throwable $e): string
    {
        $msg = 'Could not reach MiHCM: '.$e->getMessage();

        if (str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'cURL error 77')) {
            $msg .= ' | This PHP has no CA certificate bundle. Set curl.cainfo and openssl.cafile in the php.ini used by the queue worker (php --ini), then restart it. For local development only you can set MIHCM_VERIFY_SSL=false.';
        }

        return $msg;
    }
}
