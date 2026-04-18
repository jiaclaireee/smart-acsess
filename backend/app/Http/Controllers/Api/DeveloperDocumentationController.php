<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditTrailService;
use App\Services\DeveloperDocumentationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class DeveloperDocumentationController extends Controller
{
    public function __construct(
        protected DeveloperDocumentationService $documentationService,
    ) {
    }

    public function show()
    {
        return response()->json(
            $this->documentationService->build()
        );
    }

    public function downloadPdf(Request $request)
    {
        $pdf = Pdf::loadHTML(
            $this->documentationService->buildPdfHtml()
        )->setPaper('legal', 'portrait');

        app(AuditTrailService::class)->record(
            $request,
            'SMART-ACSESS for Developers',
            'Export Developer Documentation',
            'Downloaded the SMART-ACSESS for Developers documentation as PDF.',
            [
                'document' => 'SMART-ACSESS for Developers',
                'paper_size' => 'legal',
                'paper_orientation' => 'portrait',
                'file_name' => 'smart-acsess-for-developers.pdf',
            ],
            'developer_documentation',
            'smart-acsess-for-developers',
        );

        return $pdf->download('smart-acsess-for-developers.pdf');
    }
}
