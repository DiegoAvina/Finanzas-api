<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavingGoal;
use App\Models\SavingGoalMovement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SavingGoalController extends Controller
{
    /**
     * Listar metas de ahorro del usuario (dueño o participante).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $goals = SavingGoal::with(['participants'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('participants', function ($qp) use ($user) {
                        $qp->where('user_id', $user->id);
                    });
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($goals);
    }

    /**
     * Crear nueva meta de ahorro.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'target_amount' => ['required', 'numeric', 'min:0.01'],
            'current_amount' => ['nullable', 'numeric', 'min:0'],
            'deadline' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_group' => ['boolean'],
        ]);

        $goal = new SavingGoal;
        $goal->user_id = $user->id;
        $goal->name = $data['name'];
        $goal->description = $data['description'] ?? null;
        $goal->target_amount = $data['target_amount'];
        $goal->current_amount = $data['current_amount'] ?? 0;
        $goal->deadline = $data['deadline'] ?? null;
        $goal->category = $data['category'] ?? null;
        $goal->is_group = $data['is_group'] ?? false;
        $goal->status = 'active';
        $goal->save();

        // El dueño también es participante (como owner)
        $goal->participants()->syncWithoutDetaching([
            $user->id => [
                'role' => 'owner',
                'expected_contribution' => null,
            ],
        ]);

        $goal->load('participants');

        return response()->json($goal, 201);
    }

    /**
     * Registrar un depósito (aporte) a una meta.
     */
    public function contribute(Request $request, SavingGoal $savingGoal)
    {
        $this->authorize('contribute', $savingGoal);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $savingGoal->current_amount = $savingGoal->current_amount + $data['amount'];

        if ($savingGoal->current_amount >= $savingGoal->target_amount && $savingGoal->target_amount > 0) {
            $savingGoal->status = 'completed';
        }

        $savingGoal->save();

        SavingGoalMovement::create([
            'saving_goal_id' => $savingGoal->id,
            'user_id' => $user->id,
            'date' => Carbon::today(),
            'amount' => $data['amount'],
            'type' => 'deposit',
            'description' => $data['description'] ?? null,
        ]);

        $savingGoal->load('participants');

        return response()->json([
            'ok' => true,
            'goal' => $savingGoal,
        ]);
    }

    /**
     * 💸 Registrar un retiro de la meta.
     */
    public function withdraw(Request $request, SavingGoal $savingGoal)
    {
        $this->authorize('withdraw', $savingGoal);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:' . (float) $savingGoal->current_amount],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $savingGoal->current_amount = $savingGoal->current_amount - $data['amount'];

        if ($savingGoal->current_amount < $savingGoal->target_amount) {
            $savingGoal->status = 'active';
        }

        $savingGoal->save();

        SavingGoalMovement::create([
            'saving_goal_id' => $savingGoal->id,
            'user_id' => $user->id,
            'date' => Carbon::today(),
            'amount' => -$data['amount'],
            'type' => 'withdraw',
            'description' => $data['description'] ?? null,
        ]);

        $savingGoal->load('participants');

        return response()->json([
            'ok' => true,
            'goal' => $savingGoal,
        ]);
    }

    /**
     * 📜 Historial de movimientos (aportes y retiros) de la meta.
     */
    public function movements(Request $request, SavingGoal $savingGoal)
    {
        $this->authorize('contribute', $savingGoal);

        $movements = $savingGoal->movements()
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($movements);
    }

    /**
     * ➕ Agregar miembro a una meta grupal por correo.
     */
    public function addMember(Request $request, SavingGoal $savingGoal)
    {
        $this->authorize('manageMembers', $savingGoal);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'expected_contribution' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['No se encontró un usuario con ese correo.'],
            ]);
        }

        $savingGoal->participants()->syncWithoutDetaching([
            $user->id => [
                'role' => 'member',
                'expected_contribution' => $data['expected_contribution'] ?? null,
            ],
        ]);

        $savingGoal->load('participants');

        return response()->json([
            'ok' => true,
            'goal' => $savingGoal,
        ]);
    }

    /**
     * 🖼️ Subir/reemplazar la foto de portada de la meta.
     */
    public function uploadImage(Request $request, SavingGoal $savingGoal)
    {
        $this->authorize('update', $savingGoal);

        $request->validate([
            // 'image' por sí solo rechaza HEIC/HEIF (el formato que usa la
            // galería de iPhone por defecto), aunque sí sea una foto válida.
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,bmp,gif,webp,heic,heif', 'max:4096'],
        ]);

        if ($savingGoal->image_path) {
            Storage::disk('public')->delete($savingGoal->image_path);
        }

        $path = $request->file('image')->store('saving_goals', 'public');

        $savingGoal->image_path = $path;
        $savingGoal->save();
        $savingGoal->load('participants');

        return response()->json([
            'ok' => true,
            'goal' => $savingGoal,
        ]);
    }
}
