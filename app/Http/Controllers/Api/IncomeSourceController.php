<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncomeOccurrence;
use App\Models\IncomeSource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncomeSourceController extends Controller
{
    /**
     * Lista las fuentes de ingreso del usuario.
     */
    public function index(Request $request)
    {
        $sources = IncomeSource::where('user_id', $request->user()->id)
            ->withCount('occurrences')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($sources);
    }

    /**
     * Crea una fuente de ingreso. Si trae default_amount + start_date,
     * también crea automáticamente su primera ocurrencia esperada.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(array_keys(IncomeSource::TYPES))],
            'default_amount' => ['nullable', 'numeric', 'min:0.01'],
            'estimated_min_amount' => ['nullable', 'numeric', 'min:0'],
            'estimated_max_amount' => ['nullable', 'numeric', 'min:0'],
            'frequency' => ['nullable', Rule::in(IncomeSource::FREQUENCIES)],
            'is_recurring' => ['boolean'],
            'start_date' => ['nullable', 'date', 'required_with:default_amount'],
            'notes' => ['nullable', 'string'],
        ]);

        $source = IncomeSource::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'type' => $data['type'],
            'default_amount' => $data['default_amount'] ?? null,
            'estimated_min_amount' => $data['estimated_min_amount'] ?? null,
            'estimated_max_amount' => $data['estimated_max_amount'] ?? null,
            'frequency' => $data['frequency'] ?? null,
            'is_recurring' => $data['is_recurring'] ?? false,
            'active' => true,
            'notes' => $data['notes'] ?? null,
        ]);

        if (! empty($data['default_amount']) && ! empty($data['start_date'])) {
            IncomeOccurrence::create([
                'income_source_id' => $source->id,
                'user_id' => $user->id,
                'expected_amount' => $data['default_amount'],
                'expected_date' => $data['start_date'],
                'status' => 'expected',
            ]);
        }

        return response()->json($source->load('occurrences'), 201);
    }

    public function show(Request $request, IncomeSource $incomeSource)
    {
        $this->authorize('view', $incomeSource);

        return response()->json($incomeSource->load(['occurrences', 'rules']));
    }

    public function update(Request $request, IncomeSource $incomeSource)
    {
        $this->authorize('update', $incomeSource);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(array_keys(IncomeSource::TYPES))],
            'default_amount' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'estimated_min_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimated_max_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'frequency' => ['sometimes', 'nullable', Rule::in(IncomeSource::FREQUENCIES)],
            'is_recurring' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $incomeSource->fill($data);
        $incomeSource->save();

        return response()->json($incomeSource);
    }

    public function destroy(Request $request, IncomeSource $incomeSource)
    {
        $this->authorize('delete', $incomeSource);

        $incomeSource->delete();

        return response()->json(['message' => 'Fuente de ingreso eliminada correctamente.']);
    }
}
