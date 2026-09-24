<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TandaResource;
use App\Models\Tanda;
use App\Models\TandaMember;
use App\Models\TandaPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TandaController extends Controller
{
    /**
     * Listar tandas donde el usuario es dueño o miembro.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $tandas = Tanda::with(['members', 'payments'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('members', function ($qp) use ($user) {
                        $qp->where('user_id', $user->id);
                    });
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return TandaResource::collection($tandas);
    }

    /**
     * Crear una nueva tanda.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'contribution_amount' => ['required', 'numeric', 'min:0.01'],
            'num_members' => ['required', 'integer', 'min:2'],
            'frequency' => ['required', 'in:weekly,biweekly,monthly'],
            'start_date' => ['required', 'date'],
        ]);

        $potAmount = $data['contribution_amount'] * $data['num_members'];

        $tanda = Tanda::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'contribution_amount' => $data['contribution_amount'],
            'num_members' => $data['num_members'],
            'rounds_total' => $data['num_members'],
            'pot_amount' => $potAmount,
            'frequency' => $data['frequency'],
            'start_date' => $data['start_date'],
            'current_round' => 1,
            'next_payment_date' => $data['start_date'],
            'status' => 'active',
        ]);

        // El dueño entra como miembro turno 1
        $tanda->members()->create([
            'user_id' => $user->id,
            'turn_order' => 1,
            'has_received' => false,
            'received_at' => null,
        ]);

        $tanda->load(['members', 'payments']);

        return new TandaResource($tanda);
    }

    /**
     * Agregar miembro/turno a la tanda. Acepta:
     *  - email: vincula a un usuario ya registrado (como antes).
     *  - name: crea un turno "invitado" sin cuenta en la app, solo con
     *    nombre para mostrar. Se requiere uno de los dos, no ambos.
     */
    public function addMember(Request $request, Tanda $tanda)
    {
        $this->authorize('manageMembers', $tanda);

        $data = $request->validate([
            'email' => ['required_without:name', 'nullable', 'email'],
            'name' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'turn_order' => ['required', 'integer', 'min:1'],
        ]);

        if (! empty($data['email'])) {
            $user = User::where('email', $data['email'])->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'email' => ['No se encontró un usuario con ese correo.'],
                ]);
            }

            $tanda->members()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'guest_name' => null,
                    'turn_order' => $data['turn_order'],
                    'has_received' => false,
                    'received_at' => null,
                ]
            );
        } else {
            $tanda->members()->create([
                'user_id' => null,
                'guest_name' => $data['name'],
                'turn_order' => $data['turn_order'],
                'has_received' => false,
                'received_at' => null,
            ]);
        }

        $tanda->load(['members', 'payments']);

        return response()->json([
            'ok' => true,
            'tanda' => new TandaResource($tanda),
        ]);
    }

    /**
     * Editar un turno existente: renombrarlo (invitado sin cuenta) o
     * cambiar/asignar el correo vinculado (usuario ya registrado) —
     * enviar uno u otro convierte el turno de invitado a vinculado o
     * viceversa. También reasigna su número o marca/desmarca si ya
     * recibió su pozo. Solo el dueño de la tanda puede hacerlo.
     */
    public function updateMember(Request $request, Tanda $tanda, TandaMember $member)
    {
        $this->authorize('manageMembers', $tanda);

        abort_unless($member->tanda_id === $tanda->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email'],
            'turn_order' => ['sometimes', 'integer', 'min:1'],
            'has_received' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['email'] ?? null)) {
            $user = User::where('email', $data['email'])->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'email' => ['No se encontró un usuario con ese correo.'],
                ]);
            }

            $member->user_id = $user->id;
            $member->guest_name = null;
        } elseif (! empty($data['name'] ?? null)) {
            $member->user_id = null;
            $member->guest_name = $data['name'];
        }

        if (array_key_exists('turn_order', $data)) {
            $member->turn_order = $data['turn_order'];
        }

        if (array_key_exists('has_received', $data)) {
            $member->has_received = $data['has_received'];
            $member->received_at = $data['has_received'] ? now() : null;
        }

        $member->save();

        $tanda->load(['members', 'payments']);

        return response()->json([
            'ok' => true,
            'tanda' => new TandaResource($tanda),
        ]);
    }

    /**
     * Registrar un pago de la tanda (aporte de un miembro).
     * Aquí luego podemos enganchar el ahorro total y el calendario.
     */
    public function registerPayment(Request $request, Tanda $tanda)
    {
        $user = $request->user();

        $this->authorize('registerPayment', $tanda);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_date' => ['nullable', 'date'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $payment = new TandaPayment;
        $payment->tanda_id = $tanda->id;
        $payment->user_id = $user->id;
        $payment->amount = $data['amount'];
        $payment->due_date = $data['due_date'] ?? null;
        $payment->paid_at = $data['paid_at'] ?? now();
        $payment->status = 'paid';
        $payment->notes = $data['notes'] ?? null;
        $payment->save();

        // Avanzar la tanda a la siguiente vuelta y recalcular el próximo pago.
        $baseDate = $tanda->next_payment_date ?? now();
        $tanda->current_round = $tanda->current_round + 1;
        $tanda->next_payment_date = $tanda->nextPaymentDateAfter($baseDate);

        if ($tanda->rounds_total && $tanda->current_round > $tanda->rounds_total) {
            $tanda->status = 'completed';
            $tanda->next_payment_date = null;
        }

        $tanda->save();

        // TODO: aquí podemos:
        //  - Descontar del sueldo/semana (Expense)
        //  - Aumentar el ahorro total si aplica
        //  - Generar/actualizar evento de calendario con due_date

        $tanda->load(['members', 'payments']);

        return response()->json([
            'ok' => true,
            'tanda' => new TandaResource($tanda),
            'payment' => $payment,
        ]);
    }
}
