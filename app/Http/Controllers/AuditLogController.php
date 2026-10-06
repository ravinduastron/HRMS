<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate([
            'from'  => ['nullable', 'date'],
            'to'    => ['nullable', 'date'],
            'user'  => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:255'],
            'q'     => ['nullable', 'string', 'max:100'],
        ]);
    }

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('audit.index', [
            'logs'   => AuditLog::filter($filters)->latest('occurred_at')->latest('id')->paginate(25)->withQueryString(),
            'events' => AuditLog::query()->distinct()->orderBy('event')->pluck('event'),
            'models' => AuditLog::query()->whereNotNull('auditable_type')->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
        ]);
    }

    public function show(AuditLog $log): View
    {
        return view('audit.show', [
            'log'     => $log,
            'related' => AuditLog::where('batch_id', $log->batch_id)->where('id', '!=', $log->id)->orderBy('id')->limit(50)->get(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        Audit::log('audit.exported', null, 'Audit log exported to CSV', context: ['filters' => array_filter($filters)]);

        // Neutralise spreadsheet formula injection
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($filters, $safe) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Time', 'User', 'Event', 'Record type', 'Record ID', 'Record label', 'Description', 'Old values', 'New values', 'IP', 'URL', 'Batch']);
            AuditLog::filter($filters)->orderBy('id')->cursor()->each(function ($l) use ($out, $safe) {
                fputcsv($out, array_map($safe, [
                    $l->occurred_at->format('Y-m-d H:i:s'), $l->user_name, $l->event, $l->auditable_type, $l->auditable_id,
                    $l->auditable_label, $l->description, json_encode($l->old_values), json_encode($l->new_values),
                    $l->ip_address, $l->url, $l->batch_id,
                ]));
            });
            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
