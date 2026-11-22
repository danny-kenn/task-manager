<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    /** GET /reports/download?dataset=tasks&format=pdf&status=pending ... Role is checked inside ReportService::build. */
    public function download(Request $request, ReportService $reports): Response
    {
        $data = $request->validate([
            'dataset' => ['required', 'in:'.implode(',', ReportService::DATASETS)],
            'format' => ['required', 'in:'.implode(',', ReportService::FORMATS)],
        ]);
        $user = $request->user();
        abort_unless(ReportService::allowed($user, $data['dataset']), 403, 'Your role cannot export that data.');

        $built = $reports->build($user, $data['dataset'], $request->only(ReportService::FILTERS));
        $file = $reports->render($built, $data['format']);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => ($data['format'] === 'html' || $data['format'] === 'pdf' ? 'inline' : 'attachment').'; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
