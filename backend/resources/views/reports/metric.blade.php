<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>{{ $title }}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
    .header { padding: 8px 0; border-bottom: 2px solid #00563F; }
    .badge { display: inline-block; padding: 4px 8px; background: #00563F; color: #fff; border-radius: 4px; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #00563F; color: #fff; padding: 6px; text-align: left; font-size: 11px; }
    td { border: 1px solid #ddd; padding: 6px; font-size: 10px; }
    .muted { color: #555; }
  </style>
</head>
<body>
  <div class="header">
    <div class="badge">SMART-ACSESS</div>
    <h2 style="margin:8px 0 0 0;">{{ $title }}</h2>
    @if($subtitle)
      <div class="muted">{{ $subtitle }}</div>
    @endif
    <div class="muted">Generated: {{ $generatedAt }}</div>
  </div>

  @if(!is_null($summary))
    <h3>Summary</h3>
    <p><strong>{{ $summary }}</strong></p>
  @endif

  @if(count($series))
    <h3>Series (Preview)</h3>
    <table>
      <thead><tr><th>Bucket</th><th>Value</th></tr></thead>
      <tbody>
        @foreach($series as $row)
          <tr><td>{{ $row['x'] ?? '' }}</td><td>{{ $row['y'] ?? '' }}</td></tr>
        @endforeach
      </tbody>
    </table>
  @endif

  @if(count($table))
    <h3>Table (Preview)</h3>
    @php
      $keys = array_keys((array)($table[0] ?? []));
    @endphp
    <table>
      <thead>
        <tr>
          @foreach($keys as $k)<th>{{ $k }}</th>@endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($table as $r)
          <tr>
            @foreach($keys as $k)<td>{{ (array)$r[$k] ?? '' }}</td>@endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</body>
</html>
