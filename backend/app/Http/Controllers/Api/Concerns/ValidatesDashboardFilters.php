<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

trait ValidatesDashboardFilters
{
    protected function validateDashboardFilters(Request $request): array
    {
        $validator = Validator::make(
            $request->all(),
            [
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
            ],
            [
                'db_id.required' => 'Select a database connection first.',
                'db_id.exists' => 'The selected database connection is no longer available.',
                'from.date' => 'The start date must be a valid date.',
                'to.date' => 'The end date must be a valid date.',
                'sort_direction.in' => 'Sort direction must be ascending or descending.',
            ],
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

        return $validator->validate();
    }
}
