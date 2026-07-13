<?php

namespace App\Http\Requests\Benchmark;

use Illuminate\Foundation\Http\FormRequest;

class StoreRenderResultRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            // Identity — Python generates UUID before calling Kling; same UUID on retry = idempotent
            'render_uuid'             => 'required|uuid',

            'session_code'            => 'required|string|exists:bm_sessions,code',
            'fixture_slug'            => 'required|string|max:128',
            'model'                   => 'required|string|max:64',
            'resolution'              => 'nullable|string|max:16',
            'duration_seconds'        => 'required|integer|min:1|max:60',
            'fps'                     => 'nullable|integer|min:1|max:60',
            'seed'                    => 'nullable|string|max:64',
            'char_count'              => 'required|integer|min:1',
            'prompt_version'          => 'required|string|max:64',
            'artifact_path'           => 'required|string|max:500',
            'rendered_at'             => 'nullable|date',

            'instructions'                        => 'nullable|array',
            'instructions.*.catalog_code'         => 'required_with:instructions|string|max:64',
            'instructions.*.beat'                 => 'required_with:instructions|string|max:32',
            'instructions.*.variant_text'         => 'required_with:instructions|string|max:500',

            'planner_outputs'                     => 'nullable|array',
            'planner_outputs.*.planner_name'      => 'required_with:planner_outputs|string|max:64',
            'planner_outputs.*.beat'              => 'required_with:planner_outputs|string|max:32',
            'planner_outputs.*.raw_text'          => 'required_with:planner_outputs|string',
        ];
    }
}
