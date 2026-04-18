<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

trait ValidatesDashboardFilters
{
    protected function validateDashboardFilters(Request $request): array
    {
        return $this->makeDashboardValidator($request, [])->validate();
    }

    protected function validateDashboardDrilldown(Request $request): array
    {
        return $this->makeDashboardValidator($request, [
            'chart_mode' => ['required', 'string', 'in:date,group,resource_overview'],
            'bucket_label' => ['required', 'string', 'max:255'],
            'chart_group_by' => ['nullable', 'string', 'max:200'],
        ], [
            'chart_mode.required' => 'The selected chart mode is required for drill-down.',
            'chart_mode.in' => 'The selected chart mode cannot be drilled into.',
            'bucket_label.required' => 'Select a chart value to inspect its underlying rows.',
        ])->validate();
    }

    private function makeDashboardValidator(Request $request, array $extraRules, array $extraMessages = [])
    {
        $validator = Validator::make(
            $request->all(),
            array_merge([
                'db_id' => ['required', 'integer', 'exists:connected_databases,id'],
                'resource' => ['nullable', 'string', 'max:200'],
                'table' => ['nullable', 'string', 'max:200'],
                'collection' => ['nullable', 'string', 'max:200'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'period' => ['nullable', 'string', 'max:50'],
                'graph_type' => ['nullable', 'string', 'max:50'],
                'date_column' => ['nullable', 'string', 'max:200'],
                'group_column' => ['nullable', 'string', 'max:200'],
                'sort_by' => ['nullable', 'string', 'max:200'],
                'sort_direction' => ['nullable', 'in:asc,desc'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ], $extraRules),
            array_merge([
                'db_id.required' => 'Select a database connection first.',
                'db_id.exists' => 'The selected database connection is no longer available.',
                'from.date' => 'The start date must be a valid date.',
                'to.date' => 'The end date must be a valid date.',
                'sort_direction.in' => 'Sort direction must be ascending or descending.',
            ], $extraMessages),
        );

        $validator->after(function ($validator) use ($request): void {
            $from = $request->input('from');
            $to = $request->input('to');

            if (!$from || !$to) {
                return;
            }

            if (Carbon::parse($from)->gt(Carbon::parse($to))) {
                $validator->errors()->add('to', 'The end date must be on or after the start date.');
            }
        });

        return $validator;
    }
}
