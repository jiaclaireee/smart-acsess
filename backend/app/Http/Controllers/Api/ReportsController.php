<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ValidatesDashboardFilters;
use App\Http\Controllers\Controller;
use App\Models\ConnectedDatabase;
use App\Services\Database\DatabaseConnectorException;
use App\Services\Database\DatabaseConnectorManager;
use App\Services\DatabaseReportingService;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Throwable;

class ReportsController extends Controller
{
    use ValidatesDashboardFilters;

    public function pdf(Request $request)
    {
        $data = $request->validate([
            'title' => ['required','string','max:200'],
            'subtitle' => ['nullable','string','max:300'],
            'summary' => ['nullable'],
            'series' => ['nullable','array'],
            'table' => ['nullable','array'],
        ]);

        $pdf = Pdf::loadView('reports.metric', [
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? '',
            'summary' => $data['summary'] ?? null,
            'series' => $data['series'] ?? [],
            'table' => $data['table'] ?? [],
            'generatedAt' => now()->toDateTimeString(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('SMART-ACSESS-Report.pdf');
    }

    public function dashboardPdf(
        Request $request,
        DatabaseConnectorManager $manager,
        DatabaseReportingService $reportingService,
    ) {
        $data = $this->validateDashboardFilters($request);

        $database = ConnectedDatabase::findOrFail($data['db_id']);
        $data['resource'] = $data['resource']
            ?? $data['table']
            ?? $data['collection']
            ?? null;

        try {
            $report = $reportingService->buildReport($database, $manager->for($database), $data);
            $generatedAt = now();
            $fileName = sprintf(
                'report-%s-%s.pdf',
                Str::slug($database->name ?: 'database'),
                $generatedAt->format('Y-m-d')
            );

            $pdfPayload = [
                'title' => 'SMART-ACSESS Dashboard Report',
                'generatedAt' => $generatedAt->toDateTimeString(),
                'database' => $report['database'],
                'resourceType' => $report['resource_type'],
                'selectedResource' => $report['selected_resource'],
                'periodLabel' => $this->periodLabel($report['period'] ?? 'none'),
                'filters' => [
                    'from' => $data['from'] ?? null,
                    'to' => $data['to'] ?? null,
                    'graph_type' => $this->graphTypeLabel($data['graph_type'] ?? 'table'),
                    'date_column' => $data['date_column'] ?? null,
                    'group_column' => $data['group_column'] ?? null,
                ],
                'warnings' => $report['warnings'] ?? [],
                'kpis' => $report['kpis'] ?? [],
                'chart' => $report['chart'] ?? [],
                'table' => $report['table'] ?? ['columns' => [], 'rows' => [], 'pagination' => []],
            ];

            $pdf = Pdf::loadHTML($this->buildDashboardPdfHtml($pdfPayload))
                ->setPaper('a4', $this->resolvePaperOrientation($pdfPayload));

            return $pdf->download($fileName);
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to generate the dashboard PDF report.',
            ], 422);
        }
    }

    private function periodLabel(string $period): string
    {
        return match (strtolower(trim($period))) {
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'semiannual' => 'Semi-Annual',
            'annual' => 'Annually',
            default => 'None',
        };
    }

    private function graphTypeLabel(string $graphType): string
    {
        return match (strtolower(trim($graphType))) {
            'bar' => 'Bar Graph',
            'pie' => 'Pie Chart',
            'line' => 'Line Graph',
            default => 'Table Report',
        };
    }

    private function buildDashboardPdfHtml(array $payload): string
    {
        $database = $payload['database'] ?? [];
        $table = $payload['table'] ?? ['columns' => [], 'rows' => [], 'pagination' => []];
        $chart = $payload['chart'] ?? ['labels' => [], 'series' => []];
        $pagination = $table['pagination'] ?? [];

        $warningHtml = '';
        foreach (($payload['warnings'] ?? []) as $warning) {
            $warningHtml .= '<div class="warning">' . $this->escape((string) $warning) . '</div>';
        }

        $kpiHtml = '';
        foreach (($payload['kpis'] ?? []) as $kpi) {
            $kpiHtml .= '
                <td width="' . (100 / max(count($payload['kpis'] ?? []), 1)) . '%">
                    <div class="kpi-card">
                        <div class="kpi-label">' . $this->escape((string) ($kpi['label'] ?? 'Metric')) . '</div>
                        <div class="kpi-value">' . $this->escape((string) ($kpi['value'] ?? '-')) . '</div>
                        <div class="kpi-hint">' . $this->escape((string) ($kpi['hint'] ?? '')) . '</div>
                    </div>
                </td>';
        }

        $chartHtml = $this->renderChartMarkup($chart);

        $tableHeadHtml = '';
        foreach (($table['columns'] ?? []) as $column) {
            $tableHeadHtml .= '<th>' . $this->escape((string) $column) . '</th>';
        }

        $tableBodyHtml = '';
        foreach (($table['rows'] ?? []) as $row) {
            $tableBodyHtml .= '<tr>';
            foreach (($table['columns'] ?? []) as $column) {
                $value = $row[$column] ?? null;
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }

                $tableBodyHtml .= '<td>' . $this->escape((string) ($value === null || $value === '' ? '-' : $value)) . '</td>';
            }
            $tableBodyHtml .= '</tr>';
        }

        $tableSectionHtml = $tableBodyHtml !== '' && $tableHeadHtml !== ''
            ? '
                <table class="data-table">
                    <thead><tr>' . $tableHeadHtml . '</tr></thead>
                    <tbody>' . $tableBodyHtml . '</tbody>
                </table>
                <div class="footer-note">
                    Showing ' . $this->escape((string) ($pagination['from'] ?? 0)) . ' to ' . $this->escape((string) ($pagination['to'] ?? 0)) . ' of ' . $this->escape((string) ($pagination['total'] ?? count($table['rows'] ?? []))) . ' rows in the current generated report.
                </div>'
            : '<div class="muted small">No tabular data was available for the current filters.</div>';

        return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>' . $this->escape((string) ($payload['title'] ?? 'Report')) . '</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #17212b; margin: 0; }
    .page { padding: 26px 28px; }
    .header { border-bottom: 2px solid #0f5b3b; padding-bottom: 12px; margin-bottom: 18px; }
    .brand { display: inline-block; padding: 5px 10px; background: #0f5b3b; color: #fff; border-radius: 12px; font-size: 10px; letter-spacing: .08em; }
    .title { font-size: 22px; font-weight: bold; margin: 10px 0 4px 0; }
    .muted { color: #5a6570; }
    .meta-table, .data-table { width: 100%; border-collapse: collapse; }
    .meta-table td { padding: 4px 8px 4px 0; vertical-align: top; }
    .section { margin-top: 18px; }
    .section-title { font-size: 14px; font-weight: bold; margin-bottom: 10px; color: #0f5b3b; }
    .kpi-grid { width: 100%; border-collapse: separate; border-spacing: 10px 0; margin: 0 -10px; }
    .kpi-card { border: 1px solid #d7e0e8; border-radius: 12px; padding: 12px; background: #f9fbfc; }
    .kpi-label { font-size: 10px; color: #5a6570; text-transform: uppercase; letter-spacing: .05em; }
    .kpi-value { font-size: 18px; font-weight: bold; color: #0f5b3b; margin-top: 8px; }
    .kpi-hint { font-size: 10px; color: #5a6570; margin-top: 6px; line-height: 1.4; }
    .warning { border: 1px solid #eed6a6; background: #fff7e5; color: #7a5c12; padding: 10px 12px; border-radius: 10px; margin-bottom: 8px; }
    .chart-box { border: 1px solid #d7e0e8; border-radius: 12px; padding: 12px; background: #fff; }
    .chart-svg-wrap { text-align: center; }
    .data-table th { background: #0f5b3b; color: #fff; padding: 7px 8px; text-align: left; font-size: 10px; }
    .data-table td { border: 1px solid #d9e1e8; padding: 6px 8px; font-size: 9px; vertical-align: top; word-break: break-word; }
    .small { font-size: 10px; }
    .footer-note { margin-top: 10px; font-size: 10px; color: #5a6570; }
  </style>
</head>
<body>
  <div class="page">
    <div class="header">
      <div class="brand">SMART-ACSESS</div>
      <div class="title">' . $this->escape((string) ($payload['title'] ?? 'Report')) . '</div>
      <div class="muted">Generated: ' . $this->escape((string) ($payload['generatedAt'] ?? '')) . '</div>
    </div>

    <div class="section">
      <div class="section-title">Report Metadata</div>
      <table class="meta-table">
        <tr>
          <td width="24%"><strong>Database</strong></td>
          <td>' . $this->escape((string) ($database['name'] ?? 'N/A')) . '</td>
          <td width="20%"><strong>Type</strong></td>
          <td>' . $this->escape((string) ($database['type_label'] ?? strtoupper($database['type'] ?? 'N/A'))) . '</td>
        </tr>
        <tr>
          <td><strong>' . $this->escape((string) ucfirst((string) ($payload['resourceType'] ?? 'table'))) . '</strong></td>
          <td>' . $this->escape((string) (($payload['selectedResource'] ?? null) ?: ('All ' . (($payload['resourceType'] ?? 'table') === 'collection' ? 'collections' : 'tables')))) . '</td>
          <td><strong>Report Period</strong></td>
          <td>' . $this->escape((string) ($payload['periodLabel'] ?? 'None')) . '</td>
        </tr>
        <tr>
          <td><strong>Date Range</strong></td>
          <td>' . $this->escape((string) (($payload['filters']['from'] ?? null) ?: 'Any')) . ' to ' . $this->escape((string) (($payload['filters']['to'] ?? null) ?: 'Any')) . '</td>
          <td><strong>Representation</strong></td>
          <td>' . $this->escape((string) ($payload['filters']['graph_type'] ?? 'Table Report')) . '</td>
        </tr>
        <tr>
          <td><strong>Date Column</strong></td>
          <td>' . $this->escape((string) (($payload['filters']['date_column'] ?? null) ?: 'Auto-detect / Not set')) . '</td>
          <td><strong>Visualization Column</strong></td>
          <td>' . $this->escape((string) (($payload['filters']['group_column'] ?? null) ?: 'Auto-detect / Not set')) . '</td>
        </tr>
      </table>
    </div>'
    . ($warningHtml !== '' ? '<div class="section"><div class="section-title">Notes</div>' . $warningHtml . '</div>' : '')
    . ($kpiHtml !== '' ? '<div class="section"><div class="section-title">Summary Statistics</div><table class="kpi-grid"><tr>' . $kpiHtml . '</tr></table></div>' : '')
    . '<div class="section">
        <div class="section-title">Visual Representation</div>
        <div class="chart-box">' . $chartHtml . '</div>
      </div>
      <div class="section">
        <div class="section-title">Tabular Report</div>
        ' . $tableSectionHtml . '
      </div>
    </div>
</body>
</html>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function resolvePaperOrientation(array $payload): string
    {
        $columns = $payload['table']['columns'] ?? [];
        $labels = $payload['chart']['labels'] ?? [];
        $graphType = strtolower((string) ($payload['chart']['type'] ?? 'table'));
        $maxLabelLength = 0;

        foreach ($labels as $label) {
            $maxLabelLength = max($maxLabelLength, mb_strlen((string) $label));
        }

        if (count($columns) >= 7) {
            return 'landscape';
        }

        if (count($columns) >= 5 && $graphType !== 'table') {
            return 'landscape';
        }

        if (in_array($graphType, ['bar', 'line'], true) && (count($labels) >= 8 || $maxLabelLength >= 18)) {
            return 'landscape';
        }

        return 'portrait';
    }

    private function renderChartMarkup(array $chart): string
    {
        $labels = $chart['labels'] ?? [];
        $series = $chart['series'] ?? [];
        $type = strtolower((string) ($chart['type'] ?? 'table'));

        if ($labels === [] || $series === []) {
            return '<div class="muted small">' . $this->escape((string) ($chart['empty_message'] ?? 'No visual data was available, so the PDF includes a clean summary fallback instead of a chart image.')) . '</div>';
        }

        $svg = match ($type) {
            'pie' => $this->renderPieChartSvg($labels, $series),
            'line' => $this->renderLineChartSvg($labels, $series),
            'bar' => $this->renderBarChartSvg($labels, $series),
            default => $this->renderBarChartSvg($labels, $series),
        };

        return '<div class="chart-svg-wrap"><img src="' . $this->svgToDataUri($svg) . '" alt="' . $this->escape((string) ($chart['title'] ?? 'Chart')) . '" style="width:100%; height:auto;" /></div>';
    }

    private function renderBarChartSvg(array $labels, array $series): string
    {
        $width = 700;
        $height = 320;
        $paddingLeft = 58;
        $paddingRight = 24;
        $paddingTop = 24;
        $paddingBottom = 78;
        $plotWidth = $width - $paddingLeft - $paddingRight;
        $plotHeight = $height - $paddingTop - $paddingBottom;
        $count = max(count($labels), 1);
        $slotWidth = $plotWidth / $count;
        $barWidth = min(42, $slotWidth * 0.6);
        $maxValue = max(array_map(fn($value) => (float) $value, $series)) ?: 1.0;
        $svg = [];

        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
        $svg[] = '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="#ffffff"/>';
        $svg[] = '<line x1="' . $paddingLeft . '" y1="' . ($paddingTop + $plotHeight) . '" x2="' . ($paddingLeft + $plotWidth) . '" y2="' . ($paddingTop + $plotHeight) . '" stroke="#9cb0c0" stroke-width="1"/>';
        $svg[] = '<line x1="' . $paddingLeft . '" y1="' . $paddingTop . '" x2="' . $paddingLeft . '" y2="' . ($paddingTop + $plotHeight) . '" stroke="#9cb0c0" stroke-width="1"/>';

        foreach ($labels as $index => $label) {
            $value = (float) ($series[$index] ?? 0);
            $barHeight = $plotHeight * ($value / $maxValue);
            $x = $paddingLeft + ($slotWidth * $index) + (($slotWidth - $barWidth) / 2);
            $y = $paddingTop + $plotHeight - $barHeight;
            $labelX = $paddingLeft + ($slotWidth * $index) + ($slotWidth / 2);

            $svg[] = '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($barWidth, 2) . '" height="' . round($barHeight, 2) . '" rx="6" ry="6" fill="#0f5b3b"/>';
            $svg[] = '<text x="' . round($labelX, 2) . '" y="' . ($paddingTop + $plotHeight + 16) . '" font-size="10" text-anchor="middle" fill="#5a6570">' . $this->escape($this->truncateLabel((string) $label, 14)) . '</text>';
            $svg[] = '<text x="' . round($labelX, 2) . '" y="' . max($y - 6, 12) . '" font-size="10" text-anchor="middle" fill="#17212b">' . $this->escape($this->formatNumericValue($value)) . '</text>';
        }

        $svg[] = '<text x="' . $paddingLeft . '" y="14" font-size="12" fill="#17212b">Bar Chart</text>';
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    private function renderLineChartSvg(array $labels, array $series): string
    {
        $width = 700;
        $height = 320;
        $paddingLeft = 58;
        $paddingRight = 24;
        $paddingTop = 24;
        $paddingBottom = 78;
        $plotWidth = $width - $paddingLeft - $paddingRight;
        $plotHeight = $height - $paddingTop - $paddingBottom;
        $count = max(count($labels), 1);
        $stepX = $count > 1 ? $plotWidth / ($count - 1) : 0;
        $maxValue = max(array_map(fn($value) => (float) $value, $series)) ?: 1.0;
        $points = [];
        $areaPoints = [];

        foreach ($labels as $index => $label) {
            $value = (float) ($series[$index] ?? 0);
            $x = $paddingLeft + ($stepX * $index);
            $y = $paddingTop + $plotHeight - (($value / $maxValue) * $plotHeight);
            $points[] = round($x, 2) . ',' . round($y, 2);
            $areaPoints[] = round($x, 2) . ',' . round($y, 2);
        }

        $areaPolygon = [];
        if ($points !== []) {
            $areaPolygon[] = $paddingLeft . ',' . ($paddingTop + $plotHeight);
            $areaPolygon = array_merge($areaPolygon, $areaPoints);
            $lastX = $paddingLeft + ($stepX * (count($labels) - 1));
            $areaPolygon[] = round($lastX, 2) . ',' . ($paddingTop + $plotHeight);
        }

        $svg = [];
        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
        $svg[] = '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="#ffffff"/>';
        $svg[] = '<line x1="' . $paddingLeft . '" y1="' . ($paddingTop + $plotHeight) . '" x2="' . ($paddingLeft + $plotWidth) . '" y2="' . ($paddingTop + $plotHeight) . '" stroke="#9cb0c0" stroke-width="1"/>';
        $svg[] = '<line x1="' . $paddingLeft . '" y1="' . $paddingTop . '" x2="' . $paddingLeft . '" y2="' . ($paddingTop + $plotHeight) . '" stroke="#9cb0c0" stroke-width="1"/>';

        if ($areaPolygon !== []) {
            $svg[] = '<polygon points="' . implode(' ', $areaPolygon) . '" fill="#0f5b3b" fill-opacity="0.12"/>';
            $svg[] = '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="#0f5b3b" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>';
        }

        foreach ($labels as $index => $label) {
            $value = (float) ($series[$index] ?? 0);
            $x = $paddingLeft + ($stepX * $index);
            $y = $paddingTop + $plotHeight - (($value / $maxValue) * $plotHeight);

            $svg[] = '<circle cx="' . round($x, 2) . '" cy="' . round($y, 2) . '" r="4" fill="#0f5b3b"/>';
            $svg[] = '<text x="' . round($x, 2) . '" y="' . ($paddingTop + $plotHeight + 16) . '" font-size="10" text-anchor="middle" fill="#5a6570">' . $this->escape($this->truncateLabel((string) $label, 14)) . '</text>';
            $svg[] = '<text x="' . round($x, 2) . '" y="' . max($y - 8, 12) . '" font-size="10" text-anchor="middle" fill="#17212b">' . $this->escape($this->formatNumericValue($value)) . '</text>';
        }

        $svg[] = '<text x="' . $paddingLeft . '" y="14" font-size="12" fill="#17212b">Line Chart</text>';
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    private function renderPieChartSvg(array $labels, array $series): string
    {
        $width = 700;
        $height = 320;
        $centerX = 180;
        $centerY = 160;
        $radius = 92;
        $total = array_sum(array_map(fn($value) => (float) $value, $series)) ?: 1.0;
        $palette = ['#0f5b3b', '#efb21a', '#2b7fff', '#d9485f', '#4a9f74', '#7a4ef7', '#4b5563', '#14b8a6'];
        $startAngle = -90.0;
        $svg = [];

        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
        $svg[] = '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="#ffffff"/>';
        $svg[] = '<text x="48" y="24" font-size="12" fill="#17212b">Pie Chart</text>';

        foreach ($labels as $index => $label) {
            $value = (float) ($series[$index] ?? 0);
            $sliceAngle = ($value / $total) * 360;
            $endAngle = $startAngle + $sliceAngle;
            $largeArc = $sliceAngle > 180 ? 1 : 0;

            $start = $this->polarToCartesian($centerX, $centerY, $radius, $startAngle);
            $end = $this->polarToCartesian($centerX, $centerY, $radius, $endAngle);
            $path = sprintf(
                'M %s %s L %s %s A %s %s 0 %d 1 %s %s Z',
                round($centerX, 2),
                round($centerY, 2),
                round($start['x'], 2),
                round($start['y'], 2),
                $radius,
                $radius,
                $largeArc,
                round($end['x'], 2),
                round($end['y'], 2),
            );

            $svg[] = '<path d="' . $path . '" fill="' . $palette[$index % count($palette)] . '" stroke="#ffffff" stroke-width="2"/>';

            $legendY = 54 + ($index * 28);
            $percent = round(($value / $total) * 100, 1);
            $svg[] = '<rect x="340" y="' . $legendY . '" width="14" height="14" rx="3" ry="3" fill="' . $palette[$index % count($palette)] . '"/>';
            $svg[] = '<text x="362" y="' . ($legendY + 11) . '" font-size="10" fill="#17212b">' . $this->escape($this->truncateLabel((string) $label, 28)) . ' (' . $this->escape($this->formatNumericValue($value)) . ' | ' . $percent . '%)</text>';

            $startAngle = $endAngle;
        }

        $svg[] = '<circle cx="' . $centerX . '" cy="' . $centerY . '" r="38" fill="#ffffff"/>';
        $svg[] = '<text x="' . $centerX . '" y="' . ($centerY - 4) . '" font-size="12" text-anchor="middle" fill="#5a6570">Total</text>';
        $svg[] = '<text x="' . $centerX . '" y="' . ($centerY + 16) . '" font-size="16" font-weight="bold" text-anchor="middle" fill="#17212b">' . $this->escape($this->formatNumericValue($total)) . '</text>';
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    private function polarToCartesian(float $centerX, float $centerY, float $radius, float $angleInDegrees): array
    {
        $angleInRadians = deg2rad($angleInDegrees);

        return [
            'x' => $centerX + ($radius * cos($angleInRadians)),
            'y' => $centerY + ($radius * sin($angleInRadians)),
        ];
    }

    private function truncateLabel(string $label, int $limit): string
    {
        return mb_strlen($label) > $limit
            ? mb_substr($label, 0, max($limit - 1, 1)) . '…'
            : $label;
    }

    private function formatNumericValue(float|int $value): string
    {
        if (floor((float) $value) === (float) $value) {
            return number_format((float) $value, 0, '.', ',');
        }

        return number_format((float) $value, 2, '.', ',');
    }

    private function svgToDataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
