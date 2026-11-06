<?php

namespace App\Http\Controllers;

use App\Models\PayInputBatch;
use App\Models\PayPeriod;
use App\Services\PayInputImportService;
use App\Services\PayInputService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Excel upload of pay inputs: template, upload, review, apply, reverse. Uploads are visible for the schemes the user may see. */
class PayInputImportController extends Controller
{
    public function __construct(private PayInputImportService $import, private PayInputService $inputs) {}

    public function index(Request $request): View
    {
        $pick = $this->inputs->picker($request->user(), (int) $request->query('scheme') ?: null, (int) $request->query('period') ?: null);

        return view('payroll.inputs.import.index', $pick + [
            'components' => $this->inputs->components(),
            'batches' => PayInputBatch::with('period')->latest('id')->paginate(15),
        ]);
    }

    public function template(Request $request): Response
    {
        $d = $request->validate(['period' => ['required', 'integer'], 'components' => ['nullable', 'array'], 'components.*' => ['integer']]);
        $period = PayPeriod::findOrFail($d['period']); // the scheme scope applies

        return response($this->import->template($period, array_map('intval', $d['components'] ?? [])), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="pay-inputs-'.$period->period_start->format('Y-m').'-c'.$period->cycle_no.'-scheme'.$period->scheme_id.'.xlsx"', 'Cache-Control' => 'no-store',
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['period' => ['required', 'integer'], 'file' => ['required', 'file', 'extensions:xlsx', 'max:5120']],
            ['file.extensions' => 'Upload an Excel .xlsx file (use the template).', 'file.max' => 'The file is too large.']);
        $period = PayPeriod::findOrFail($request->input('period'));
        $file = $request->file('file');

        $batch = $this->import->stage($file->getRealPath(), $file->getClientOriginalName(), $period, $request->user());

        return redirect()->route('pay-inputs.import.show', $batch)->with('status', 'File checked. Review the result, then confirm.');
    }

    public function show(Request $request, PayInputBatch $batch): View
    {
        $status = in_array($request->query('status'), ['valid', 'error', 'applied'], true) ? $request->query('status') : null;
        $batch->load('period');

        return view('payroll.inputs.import.show', [
            'batch' => $batch, 'status' => $status,
            'rows' => $batch->rows()->when($status, fn ($q, $s) => $q->where('status', $s))->paginate(40)->withQueryString(),
            'warningRows' => $batch->rows()->whereNotNull('warnings')->count(),
        ]);
    }

    public function apply(Request $request, PayInputBatch $batch): RedirectResponse
    {
        $r = $this->import->apply($batch, $request->user());

        return redirect()->route('pay-inputs.import.show', $batch)->with('status', "{$r['applied']} value(s) applied".($r['unchanged'] ? ", {$r['unchanged']} unchanged." : '.'));
    }

    public function discard(Request $request, PayInputBatch $batch): RedirectResponse
    {
        $this->import->discard($batch);

        return redirect()->route('pay-inputs.import.index')->with('status', 'Upload discarded.');
    }

    public function reverse(Request $request, PayInputBatch $batch): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $n = $this->import->reverse($batch, $d['reason'], $request->user());

        return redirect()->route('pay-inputs.import.show', $batch)->with('status', "Upload reversed: {$n} value(s) put back as they were.");
    }
}
