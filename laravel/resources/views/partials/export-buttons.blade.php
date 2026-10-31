<div class="no-print flex flex-wrap items-center gap-2">
  @can('export_reports')
  <a href="{{ query_with(['export' => 'csv', 'page' => null]) }}" class="btn-secondary btn-sm" title="Download CSV"><i data-lucide="file-text"></i> CSV</a>
  <a href="{{ query_with(['export' => 'xlsx', 'page' => null]) }}" class="btn-secondary btn-sm" title="Download Excel"><i data-lucide="file-spreadsheet"></i> Excel</a>
  <a href="{{ query_with(['export' => 'pdf', 'page' => null]) }}" class="btn-secondary btn-sm" title="Download PDF"><i data-lucide="file-down"></i> PDF</a>
  @endcan
  <button type="button" class="btn-secondary btn-sm" data-print><i data-lucide="printer"></i> Print</button>
</div>
