<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\CalendarEvent;
use App\Models\Expense;
use App\Models\IncomeSource;
use App\Models\SavingGoal;
use App\Models\Tanda;
use App\Models\TandaPayment;
use App\Models\User;
use App\Models\WeeklyIncome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Actualiza nombre / correo / contraseña del usuario autenticado.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'string', Password::min(8)->mixedCase()->numbers()],
            'current_password' => ['required_with:password'],
        ]);

        if (isset($data['password'])) {
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Tu contraseña actual no es correcta.'],
                ]);
            }

            $user->password = Hash::make($data['password']);
        }

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }

        if (isset($data['email'])) {
            $user->email = $data['email'];
        }

        $user->save();

        return response()->json($user);
    }

    /**
     * Sube/reemplaza la foto de perfil.
     */
    public function uploadAvatar(Request $request)
    {
        $user = $request->user();

        $request->validate([
            // 'image' por sí solo rechaza HEIC/HEIF (el formato que usa la
            // galería de iPhone por defecto), aunque sí sea una foto válida.
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,bmp,gif,webp,heic,heif', 'max:4096'],
        ]);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');

        $user->avatar_path = $path;
        $user->save();

        return response()->json($user);
    }

    /**
     * Elimina la cuenta del usuario autenticado (y en cascada todos sus
     * datos: recibos, gastos, metas, tandas, ingresos, etc., vía las
     * llaves foráneas onDelete('cascade') ya definidas en cada tabla).
     */
    public function destroy(Request $request)
    {
        $user = $request->user();
        $this->assertPasswordConfirmed($request, $user);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Cuenta eliminada correctamente.']);
    }

    /**
     * Borra todos los datos financieros del usuario (recibos, gastos,
     * sueldos semanales, metas, tandas, ingresos, eventos) pero conserva la
     * cuenta (nombre, correo, foto, sesión activa) para que pueda seguir
     * usando la app desde cero.
     */
    public function resetData(Request $request)
    {
        $user = $request->user();
        $this->assertPasswordConfirmed($request, $user);

        DB::transaction(function () use ($user) {
            // Lo que el usuario es dueño: se borra completo. Las llaves
            // foráneas onDelete('cascade') ya definidas en cada tabla
            // limpian en cadena sus propias relaciones (miembros, pagos,
            // ocurrencias, reglas de distribución, etc.).
            Bill::where('user_id', $user->id)->delete();
            Expense::where('user_id', $user->id)->delete();
            WeeklyIncome::where('user_id', $user->id)->delete();
            CalendarEvent::where('user_id', $user->id)->delete();
            IncomeSource::where('user_id', $user->id)->delete();
            SavingGoal::where('user_id', $user->id)->delete();
            Tanda::where('user_id', $user->id)->delete();

            // Metas/tandas de OTRA persona donde este usuario solo
            // participa: no se borran (son de alguien más), solo se le
            // quita de ahí y se limpian sus propios pagos registrados.
            DB::table('saving_goal_members')->where('user_id', $user->id)->delete();
            DB::table('tanda_members')->where('user_id', $user->id)->delete();
            TandaPayment::where('user_id', $user->id)->delete();
        });

        return response()->json(['message' => 'Tus datos se eliminaron correctamente.']);
    }

    protected function assertPasswordConfirmed(Request $request, User $user): void
    {
        Validator::make($request->all(), [
            'password' => ['required', 'string'],
        ])->validate();

        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Tu contraseña no es correcta.'],
            ]);
        }
    }
}
