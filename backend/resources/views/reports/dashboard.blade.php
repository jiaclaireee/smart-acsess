<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>{{ $title }}</title>
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
    .chart-row { margin-bottom: 8px; }
    .chart-label { font-size: 10px; margin-bottom: 3px; }
    .chart-bar-wrap { background: #edf2f6; border-radius: 999px; height: 12px; overflow: hidden; }
    .chart-bar { background: #0f5b3b; height: 12px; }
    .chart-value { font-size: 10px; color: #5a6570; margin-top: 2px; }
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
      <div class="title">{{ $title }}</div>
      <div class="muted">Generated: {{ $generatedAt }}</div>
    </div>

    <div class="section">
      <div class="section-title">Report Metadata</div>
      <table class="meta-table">
        <tr>
          <td width="24%"><strong>Database</strong></td>
          <td>{{ $database['name'] ?? 'N/A' }}</td>
          <td width="20%"><strong>Type</strong></td>
          <td>{{ $database['type_label'] ?? strtoupper($database['type'] ?? 'N/A') }}</td>
        </tr>
        <tr>
          <td><strong>{{ ucfirst($resourceType) }}</strong></td>
          <td>{{ $selectedResource ?: 'All ' . ($resourceType === 'collection' ? 'collections' : 'tables') }}</td>
          <td><strong>Report Period</strong></td>
          <td>{{ $periodLabel }}</td>
        </tr>
        <tr>
          <td><strong>Date Range</strong></td>
          <td>{{ ($filters['from'] ?? null) ?: 'Any' }} to {{ ($filters['to'] ?? null) ?: 'Any' }}</td>
          <td><strong>Representation</strong></td>
          <td>{{ $filters['graph_type'] ?? 'Table Report' }}</td>
        </tr>
        <tr>
          <td><strong>Date Column</strong></td>
          <td>{{ $filters['date_column'] ?: 'Auto-detect / Not set' }}</td>
          <td><strong>Visualization Column</strong></td>
          <td>{{ $filters['group_column'] ?: 'Auto-detect / Not set' }}</td>
        </tr>
      </table>
    </div>

    @if(!empty($warnings))
      <div class="section">
        <div class="section-title">Notes</div>
        @foreach($warnings as $warning)
          <div class="warning">{{ $warning }}</div>
        @endforeach
      </div>
    @endif

    @if(!empty($kpis))
      <div class="section">
        <div class="section-title">Summary Statistics</div>
        <table class="kpi-grid">
          <tr>
            @foreach($kpis as $kpi)
              <td width="{{ 100 / max(count($kpis), 1) }}%">
                <div class="kpi-card">
                  <div class="kpi-label">{{ $kpi['label'] ?? 'Metric' }}</div>
                  <div class="kpi-value">{{ $kpi['value'] ?? '-' }}</div>
                  @if(!empty($kpi['hint']))
                    <div class="kpi-hint">{{ $kpi['hint'] }}</div>
                  @endif
                </div>
              </td>
            @endforeach
          </tr>
        </table>
      </div>
    @endif

    <div class="section">
      <div class="section-title">Visual Representation</div>
      <div class="chart-box">
        @php
          $labels = $chart['labels'] ?? [];
          $series = $chart['series'] ?? [];
          $maxValue = count($series) ? max($series) : 0;
        @endphp

        @if(count($labels))
          @foreach($labels as $index => $label)
            @php
              $value = $series[$index] ?? 0;
              $width = $maxValue > 0 ? max(2, ($value / $maxValue) * 100) : 0;
            @endphp
            <div class="chart-row">
              <div class="chart-label">{{ $label }}</div>
              <div class="chart-bar-wrap">
                <div class="chart-bar" style="width: {{ $width }}%;"></div>
              </div>
              <div class="chart-value">Value: {{ $value }}</div>
            </div>
          @endforeach
        @else
          <div class="muted small">
            {{ $chart['empty_message'] ?? 'No visual data was available, so the PDF includes a clean summary fallback instead of a chart image.' }}
          </div>
        @endif
      </div>
    </div>

    <div class="section">
      <div class="section-title">Tabular Report</div>
      @php
        $columns = $table['columns'] ?? [];
        $rows = $table['rows'] ?? [];
        $pagination = $table['pagination'] ?? [];
      @endphp

      @if(count($rows) && count($columns))
        <table class="data-table">
          <thead>
            <tr>
              @foreach($columns as $column)
                <th>{{ $column }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach($rows as $row)
              <tr>
                @foreach($columns as $column)
                  @php $value = $row[$column] ?? null; @endphp
                  <td>
                    @if(is_array($value) || is_object($value))
                      {{ json_encode($value) }}
                    @else
                      {{ $value === null || $value === '' ? '-' : $value }}
                    @endif
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
        <div class="footer-note">
          Showing {{ $pagination['from'] ?? 0 }} to {{ $pagination['to'] ?? 0 }} of {{ $pagination['total'] ?? count($rows) }} rows in the current generated report.
        </div>
      @else
        <div class="muted small">No tabular data was available for the current filters.</div>
      @endif
    </div>
  </div>
</body>
</html>
