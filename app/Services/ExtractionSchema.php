<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class ExtractionSchema
{
    public function validate(array $output, array $columns): array
    {
        $validator = Validator::make($output, [
            'rows' => ['required', 'array', 'list', 'min:1', 'max:'.config('imports.max_rows')],
            'rows.*' => ['array:fields,warnings,missing_fields'],
            'rows.*.fields' => ['required', 'array:'.implode(',', $columns)],
            'rows.*.fields.*' => ['array:value,confidence,page,region,method'],
            'rows.*.fields.*.value' => ['present', 'nullable', 'string', 'max:5000'],
            'rows.*.fields.*.confidence' => ['present', 'nullable', 'string', 'regex:/^(?:0(?:\.\d{1,6})?|1(?:\.0{1,6})?)$/'],
            'rows.*.fields.*.page' => ['present', 'nullable', 'integer', 'min:1', 'max:100'],
            'rows.*.fields.*.region' => ['present', 'nullable', 'array', 'list', 'size:4'],
            'rows.*.fields.*.region.*' => ['string', 'regex:/^(?:0(?:\.\d{1,6})?|1(?:\.0{1,6})?)$/'],
            'rows.*.fields.*.method' => ['required', 'in:ocr,vision,text,table,model'],
            'rows.*.warnings' => ['present', 'array', 'list', 'max:20'], 'rows.*.warnings.*' => ['string', 'max:500'],
            'rows.*.missing_fields' => ['present', 'array', 'list', 'max:40'], 'rows.*.missing_fields.*' => ['string', 'max:80'],
            'usage' => ['sometimes', 'array:input_tokens,output_tokens,cost,currency'],
            'usage.input_tokens' => ['nullable', 'integer', 'min:0', 'max:10000000'], 'usage.output_tokens' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'usage.cost' => ['nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/'],
            'usage.currency' => ['required_with:usage.cost', 'nullable', 'in:USD,UZS,EUR,RUB'],
        ]);
        if ($validator->fails()) {
            throw new ImportFailure('provider_invalid_output');
        }

        $result = $validator->validated();
        foreach ($result['rows'] as &$row) {
            foreach ($row['fields'] as $field) {
                $region = $field['region'];
                if ($region !== null && (bccomp($region[0], $region[2], 6) > 0 || bccomp($region[1], $region[3], 6) > 0)) {
                    throw new ImportFailure('provider_invalid_region');
                }
            }
            $missing = array_values(array_filter($columns, fn (string $column): bool => ($row['fields'][$column]['value'] ?? null) === null));
            $row['missing_fields'] = array_values(array_unique([...$row['missing_fields'], ...$missing]));
        }
        unset($row);

        return $result;
    }
}
