<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncomeOccurrence;
use App\Models\IncomeSource;
use App\Services\IncomeDistributionService;
use App\Services\IncomeOccurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class IncomeOccurrenceController extends Controller
{
    public function __construct(
        protected IncomeOccurrenceService $occurrences,
        protected IncomeDistributionService $distribution,
    ) {}

    /**
     * Lista ocurrencias del usuario. Filtros opcionales:
     *   ?status=expected|received|partial|missed|cancelled
     *   ?month=YYYY-MM
     *   ?income_source_id=123
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = IncomeOccurrence::where('user_id', $user->id)->with('source');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($sourceId = $request->query('income_source_id')) {
            $query->where('income_source_id', $sourceId);
        }

        if ($month = $request->query('month')) {
            try {
                [$year, $monthNumber] = explode('-', $month);
                $start = Carbon::createFromDate((int) $year, (int) $monthNumber, 1)->startOfMonth();
                $end = $start->copy()->endOfMonth();
                $query->whereBetween('expected_date', [$start->toDateString(), $end->toDateString()]);
            } catch (\Throwable) {
                // Filtro de mes inválido: se ignora y se listan todas.
            }
        }

        $occurrences = $query->orderBy('expected_date', 'desc')->get();

        return response()->json($occurrences);
    }

    /**
     * Crea una ocurrencia manual bajo una fuente (útil para ingresos
     * variables/irregulares, ej. un pago puntual de freelance).
     */
    public function store(Request $request, IncomeSource $incomeSource)
    {
        $this->authorize('update', $incomeSource);

        $data = $request->validate([
            'expected_amount' => ['required', 'numeric', 'min:0.01'],
            'expected_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $incomeSource->id,
            'user_id' => $request->user()->id,
            'expected_amount' => $data['expected_amount'],
            'expected_date' => $data['expected_date'],
            'status' => 'expected',
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json($occurrence, 201);
    }

    public function show(Request $request, IncomeOccurrence $incomeOccurrence)
    {
        $this->authorize('view', $incomeOccurrence);

        return response()->json($incomeOccurrence->load('source', 'weeklyIncome'));
    }

    public function receive(Request $request, IncomeOccurrence $incomeOccurrence)
    {
        $this->authorize('update', $incomeOccurrence);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'date' => ['nullable', 'date'],
        ]);

        $occurrence = $this->occurrences->receive(
            $incomeOccurrence,
            isset($data['amount']) ? (float) $data['amount'] : null,
            isset($data['date']) ? Carbon::parse($data['date']) : null,
        );

        return response()->json($occurrence->load('source'));
    }

    public function partial(Request $request, IncomeOccurrence $incomeOccurrence)
    {
        $this->authorize('update', $incomeOccurrence);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['nullable', 'date'],
        ]);

        $occurrence = $this->occurrences->partial(
            $incomeOccurrence,
            (float) $data['amount'],
            isset($data['date']) ? Carbon::parse($data['date']) : null,
        );

        return response()->json($occurrence->load('source'));
    }

    public function miss(Request $request, IncomeOccurrence $incomeOccurrence)
    {
        $this->authorize('update', $incomeOccurrence);

        $occurrence = $this->occurrences->miss($incomeOccurrence);

        return response()->json($occurrence);
    }

    public function cancel(Request $request, IncomeOccurrence $incomeOccurrence)
    {
        $this->authorize('update', $incomeOccurrence);

        $occurrence = $this->occurrences->cancel($incomeOccurrence);

        return response()->json($occurrence);
    }

    public function distributionPreview(Request $request, IncomeOccurrence $incomeOccurrence)
    {
        $this->authorize('view', $incomeOccurrence);

        return response()->json([
            'occurrence_id' => $incomeOccurrence->id,
            'total' => (float) ($incomeOccurrence->received_amount ?? $incomeOccurrence->expected_amount),
            'lines' => $this->distribution->preview($incomeOccurrence),
        ]);
    }

    public function distribute(Request $request, IncomeOccurrence $incomeOccurrence)
    {
        $this->authorize('update', $incomeOccurrence);

        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.target_type' => ['required', 'string', Rule::in(['saving_goal', 'tanda', 'bill', 'free'])],
            'allocations.*.target_id' => ['nullable', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        $occurrence = $this->distribution->apply($incomeOccurrence, $request->user(), $data['allocations']);

        return response()->json($occurrence->load('source'));
    }
}
