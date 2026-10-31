<?php

namespace App\Reports;

/**
 * A tabular report built from the same filtered query a screen shows. The
 * exporters only format it.
 *
 * columns: list of [label, key, kind] with kind in text|money|number|date|datetime|percent
 */
final class ReportTable
{
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
        public array $totals = [],
        public array $filters = [],   // list of [label, value]
        public array $summary = [],   // list of [label, value] shown above the table
        public string $totalsLabel = 'Total',
    ) {}

    public function moneyNote(): string
    {
        return 'All amounts in Indian Rupees (INR).';
    }
}
