@php($numeric = ['money', 'number', 'percent'])
@php($weights = array_map(fn ($c) => $c[2] === 'text' ? 1.6 : 1.0, $table->columns))
@php($weightSum = array_sum($weights) ?: 1)
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $table->title }} - {{ $business->business_name }}</title>
<style>
  @page { margin: 30mm 12mm 16mm 12mm; }
  body { font-family: "DejaVu Sans", sans-serif; font-size: 7.6pt; color: #1e293b; }
  header { position: fixed; top: -30mm; left: -12mm; right: -12mm; height: 24mm; background: #0B1F33; color: #fff; }
  header .stripe { position: absolute; left: 0; right: 0; bottom: -1.5mm; height: 1.5mm; background: #FF6B00; }
  header table { width: 100%; border-collapse: collapse; }
  header td { padding: 0; vertical-align: middle; }
  header .logo { width: 16mm; height: 16mm; }
  header .name { font-size: 15pt; font-weight: bold; letter-spacing: .5pt; }
  header .tag { font-size: 7.5pt; color: #FFC79E; }
  header .details { font-size: 7pt; color: #D6DFEA; }
  header .erp { font-size: 8.5pt; font-weight: bold; text-align: right; }
  footer { position: fixed; bottom: -11mm; left: 0; right: 0; height: 6mm; font-size: 6.8pt; color: #64748B; }
  footer .page:after { content: "Page " counter(page); }
  h1 { color: #0B1F33; font-size: 13pt; margin: 0 0 2pt 0; }
  .meta { font-size: 8pt; color: #475569; margin: 0 0 1pt 0; }
  table.data { width: 100%; border-collapse: collapse; margin-top: 6pt; }
  table.data th { background: #0B1F33; color: #fff; text-align: left; padding: 4pt 3pt; border-bottom: 1.2pt solid #FF6B00; font-size: 7.4pt; }
  table.data td { padding: 3.5pt 3pt; border-bottom: .3pt solid #E2E8F0; }
  table.data tr:nth-child(even) td { background: #F5F7FA; }
  table.data tr.totals td { background: #FFE4CF; border-top: 1pt solid #FF6B00; font-weight: bold; color: #0B1F33; }
  .r { text-align: right; }
  table.summary { border-collapse: collapse; margin-top: 4pt; }
  table.summary td { border: .4pt solid #D6DFEA; padding: 3pt 6pt; }
  table.summary td.l { background: #F5F7FA; font-weight: bold; }
</style>
</head>
<body>
<header>
  <table><tr>
    <td style="width: 12mm"></td>
    @if ($logo)<td style="width: 19mm"><img class="logo" src="{{ $logo }}" alt=""></td>@endif
    <td>
      <div class="name">{{ mb_strtoupper($business->business_name) }}</div>
      <div class="tag">{{ $business->tagline }}</div>
      <div class="details">{{ \Illuminate\Support\Str::limit(implode(' | ', array_filter([preg_replace('/\s+/', ' ', (string) $business->address), $business->contactLine()])), 160) }}</div>
    </td>
    <td class="erp" style="padding-right: 12mm">{{ $business->erp_title }}</td>
  </tr></table>
  <div class="stripe"></div>
</header>
<footer>
  <span style="float: right" class="page"></span>
  Generated {{ $generated }}@if ($user) by {{ $user }}@endif. {{ $business->report_footer }}
</footer>

<h1>{{ $table->title }}</h1>
@foreach ($table->filters as [$label, $value])<p class="meta"><b>{{ $label }}:</b> {{ $value }}</p>@endforeach
@if ($table->summary)
<table class="summary">
  @foreach ($table->summary as [$label, $value])<tr><td class="l">{{ $label }}</td><td class="r">{{ is_string($value) && preg_match('/^-?\d+\.\d{2}$/', $value) ? inr($value) : $value }}</td></tr>@endforeach
</table>
@endif

<table class="data">
  <thead><tr>
    @foreach ($table->columns as $i => [$label, $key, $kind])
      <th class="{{ in_array($kind, $numeric, true) ? 'r' : '' }}" style="width: {{ round($weights[$i] / $weightSum * 100, 2) }}%">{{ $label }}</th>
    @endforeach
  </tr></thead>
  <tbody>
    @forelse ($table->rows as $row)
    <tr>@foreach ($table->columns as [$label, $key, $kind])<td class="{{ in_array($kind, $numeric, true) ? 'r' : '' }}">{{ \App\Reports\Exporter::format($row[$key] ?? null, $kind) }}</td>@endforeach</tr>
    @empty
    <tr><td colspan="{{ count($table->columns) }}">No records for the selected filters.</td></tr>
    @endforelse
    @if ($table->totals)
    <tr class="totals">@foreach ($table->columns as $i => [$label, $key, $kind])<td class="{{ in_array($kind, $numeric, true) ? 'r' : '' }}">{{ $i === 0 ? $table->totalsLabel : \App\Reports\Exporter::format($table->totals[$key] ?? null, $kind) }}</td>@endforeach</tr>
    @endif
  </tbody>
</table>
<p class="meta" style="margin-top: 6pt">{{ $table->moneyNote() }}</p>
</body>
</html>
