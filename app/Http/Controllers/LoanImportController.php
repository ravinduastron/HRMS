<?php

namespace App\Http\Controllers;

use App\Models\LoanImportBatch;
use App\Services\LoanImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Excel import of existing loans: template, upload, review, apply, reverse. Users see their own uploads (all-scheme users see every upload). */
class LoanImportController extends Controller
{
    public function __construct(private LoanImportService $import) {}

    public function index(Request $request): View
    {
        return view('loans.import.index', [
            'batches' => LoanImportBatch::when(! $request->user()->can('schemes.all'), fn ($q) => $q->where('uploaded_by', $request->user()->id))->latest('id')->paginate(15),
        ]);
    }

    public function template(): Response
    {
        return response($this->import->template(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="loan-import-template.xlsx"', 'Cache-Control' => 'no-store',
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:'.config('loans.import_max_kb', 5120)]],
            ['file.extensions' => 'Upload an Excel .xlsx file (use the template).', 'file.max' => 'The file is too large.']);
        $file = $request->file('file');

        $batch = $this->import->stage($file->getRealPath(), $file->getClientOriginalName(), $request->user());

        return redirect()->route('loans.import.show', $batch)->with('status', 'File checked. Review the result, then confirm.');
    }

    public function show(Request $request, LoanImportBatch $batch): View
    {
        $this->authorizeBatch($request, $batch);
        $status = in_array($request->query('status'), ['valid', 'error', 'created'], true) ? $request->query('status') : null;

        return view('loans.import.show', [
            'batch' => $batch, 'status' => $status,
            'rows' => $batch->rows()->when($status, fn ($q, $s) => $q->where('status', $s))->paginate(40)->withQueryString(),
            'warningRows' => $batch->rows()->whereNotNull('warnings')->count(),
        ]);
    }

    public function apply(Request $request, LoanImportBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);
        $n = $this->import->apply($batch, $request->user());

        return redirect()->route('loans.import.show', $batch)->with('status', "{$n} loans created.");
    }

    public function discard(Request $request, LoanImportBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);
        $this->import->discard($batch);

        return redirect()->route('loans.import.index')->with('status', 'Upload discarded.');
    }

    public function reverse(Request $request, LoanImportBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $n = $this->import->reverse($batch, $d['reason'], $request->user());

        return redirect()->route('loans.import.show', $batch)->with('status', "Upload reversed: {$n} loans cancelled.");
    }

    private function authorizeBatch(Request $request, LoanImportBatch $batch): void
    {
        abort_unless($request->user()->can('schemes.all') || $batch->uploaded_by === $request->user()->id, 404);
    }
}
