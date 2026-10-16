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

    /* ------------------------------ employees ------------------------------ */

    /** One page of employees. An empty array means "no more pages". Any error THROWS (never looks like "finished"). */
    public function batch(int $number): array
    {
        $res = $this->getWithRefresh("/profile/employees/batch/{$number}");

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

    /* ------------------------------ attendance ------------------------------ */

    /**
     * One page of attendance for a date range (Y-m-d, both ends included as far as MiHCM documents).
     *
     * @return array{0: array, 1: ?int, 2: bool} [rows, nextBatch, isLastRecord]
     */
    public function attendanceBatch(int $number, string $from, string $to): array
    {
        $res = $this->getWithRefresh("/ontime/attendance/batch/{$number}", ['startDate' => $from, 'endDate' => $to]);

        if (! $res->successful()) {
            throw new MihcmException("MiHCM attendance batch {$number} failed (HTTP {$res->status()}).");
        }

        $rows = $res->json('attendanceViewModels');
        $next = $res->json('nextBatch');

        return [
            is_array($rows) ? $rows : [],
            is_numeric($next) ? (int) $next : null,
            filter_var($res->json('isLastRecord'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Every attendance row in the range. Follows nextBatch until isLastRecord. Any problem THROWS:
     * a partial result must never look like a complete one.
     *
     * @return array{0: array, 1: int} [rows, number of pages]
     */
    public function allAttendance(string $from, string $to): array
    {
        $all = [];
        $n = 0;
        $pages = 0;

        while (true) {
            [$rows, $next, $last] = $this->attendanceBatch($n, $from, $to);
            $pages++;
            array_push($all, ...$rows);

            if ($last) {
                return [$all, $pages];
            }
            if ($next === null) {
                throw new MihcmException("MiHCM attendance batch {$n} did not say whether more data follows. Nothing was saved.");
            }
            if ($next <= $n) {
                throw new MihcmException("MiHCM attendance paging did not advance (batch {$n} -> {$next}). Nothing was saved.");
            }
            if ($pages >= config('attendance.max_batches')) {
                throw new MihcmException('Stopped after '.config('attendance.max_batches').' attendance batches. Use a shorter date range.');
            }
            $n = $next;
        }
    }

    /* ------------------------------ leave ------------------------------ */

    /** Every leave entry MiHCM returns for a calendar month (all employees). */
    public function leaveForMonth(int $year, int $month): array
    {
        $res = $this->getWithRefresh("/ontime/leave/{$year}/{$month}");

        if (! $res->successful()) {
            throw new MihcmException("MiHCM leave {$year}-{$month} failed (HTTP {$res->status()}).");
        }

        $rows = $res->json();
        if (! is_array($rows) || ($rows && ! array_is_list($rows))) {
            throw new MihcmException("MiHCM leave {$year}-{$month} returned an unexpected response.");
        }

        return $rows;
    }

    /* ------------------------------ internals ------------------------------ */

    /** GET, refreshing the token once if it expired mid-load. */
    private function getWithRefresh(string $path, array $query = []): Response
    {
        $res = $this->get($path, $query);

        if ($res->status() === 401) {
            $this->token(refresh: true);
            $res = $this->get($path, $query);
        }

        return $res;
    }

    private function get(string $path, array $query = []): Response
    {
        try {
            return $this->http()->get($path, $query);
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
