<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\IncomeDistributionRule;
use App\Models\IncomeSource;
use App\Models\SavingGoal;
use App\Models\Tanda;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IncomeDistributionRuleController extends Controller
{
    /**
     * Lista las reglas de distribución de una fuente de ingreso.
     */
    public function index(Request $request, IncomeSource $incomeSource)
    {
        $this->authorize('view', $incomeSource);

        return response()->json(
            $incomeSource->rules()->orderBy('order')->get()
        );
    }

    /**
     * Crea una regla de distribución para una fuente. Esto solo define la
     * regla; no mueve dinero por sí sola (eso pasa en /distribute, con
     * confirmación explícita del usuario).
     */
    public function store(Request $request, IncomeSource $incomeSource)
    {
        $this->authorize('update', $incomeSource);

        $data = $this->validateRule($request);

        $rule = IncomeDistributionRule::create([
            'income_source_id' => $incomeSource->id,
            'user_id' => $request->user()->id,
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'] ?? null,
            'mode' => $data['mode'],
            'value' => $data['value'],
            'order' => $data['order'] ?? 0,
            'active' => true,
        ]);

        return response()->json($rule, 201);
    }

    public function update(Request $request, IncomeDistributionRule $rule)
    {
        if ($rule->user_id !== $request->user()->id) {
            abort(403, 'No tienes permiso para modificar esta regla.');
        }

        $data = $this->validateRule($request);

        $rule->fill([
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'] ?? null,
            'mode' => $data['mode'],
            'value' => $data['value'],
            'order' => $data['order'] ?? $rule->order,
            'active' => $data['active'] ?? $rule->active,
        ]);
        $rule->save();

        return response()->json($rule);
    }

    public function destroy(Request $request, IncomeDistributionRule $rule)
    {
        if ($rule->user_id !== $request->user()->id) {
            abort(403, 'No tienes permiso para eliminar esta regla.');
        }

        $rule->delete();

        return response()->json(['message' => 'Regla eliminada correctamente.']);
    }

    protected function validateRule(Request $request): array
    {
        $data = $request->validate([
            'target_type' => ['required', 'string', Rule::in(['saving_goal', 'tanda', 'bill', 'free'])],
            'target_id' => ['nullable', 'integer', 'required_unless:target_type,free'],
            'mode' => ['required', 'string', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'numeric', 'min:0.01'],
            'order' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($data['mode'] === 'percent' && $data['value'] > 100) {
            throw ValidationException::withMessages([
                'value' => ['Un porcentaje no puede ser mayor a 100.'],
            ]);
        }

        if ($data['target_type'] !== 'free') {
            $this->assertTargetBelongsToUser($request->user()->id, $data['target_type'], $data['target_id']);
        }

        return $data;
    }

    protected function assertTargetBelongsToUser(int $userId, string $targetType, int $targetId): void
    {
        $exists = match ($targetType) {
            'saving_goal' => SavingGoal::where('id', $targetId)
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)
                        ->orWhereHas('participants', fn ($qp) => $qp->where('user_id', $userId));
                })->exists(),
            'tanda' => Tanda::where('id', $targetId)
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)
                        ->orWhereHas('members', fn ($qp) => $qp->where('user_id', $userId));
                })->exists(),
            'bill' => Bill::where('id', $targetId)->where('user_id', $userId)->exists(),
            default => false,
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'target_id' => ['El destino seleccionado no existe o no te pertenece.'],
            ]);
        }
    }
}
