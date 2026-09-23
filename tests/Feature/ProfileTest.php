<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\SavingGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_update_their_name_and_email(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', [
            'name' => 'Nuevo Nombre',
            'email' => 'nuevo@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Nuevo Nombre')
            ->assertJsonPath('email', 'nuevo@example.com');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'nuevo@example.com']);
    }

    public function test_updating_the_password_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'password' => 'NuevaPass123',
            'current_password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_user_can_update_their_password_with_the_correct_current_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'password' => 'NuevaPass123',
            'current_password' => 'password',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('NuevaPass123', $user->fresh()->password));
    }

    public function test_a_user_can_upload_an_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        $this->assertNotNull($user->avatar_url);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_deleting_the_account_requires_the_correct_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/profile', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /**
     * Al eliminar la cuenta, sus datos (recibos, etc.) se borran en cascada
     * vía las llaves foráneas onDelete('cascade'), y el token deja de servir.
     */
    public function test_deleting_the_account_removes_the_user_and_their_data(): void
    {
        $user = User::factory()->create();
        $bill = Bill::create([
            'user_id' => $user->id,
            'name' => 'Luz',
            'amount' => 300,
            'due_date' => '2026-10-01',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/profile', ['password' => 'password'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('bills', ['id' => $bill->id]);
    }

    public function test_resetting_data_requires_the_correct_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/reset-data', ['password' => 'wrong-password'])
            ->assertStatus(422);
    }

    /**
     * Borrar los datos conserva la cuenta (sigue pudiendo usar la app) pero
     * limpia todo lo financiero, incluyendo su lugar en metas/tandas ajenas.
     */
    public function test_resetting_data_wipes_owned_records_but_keeps_the_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $bill = Bill::create([
            'user_id' => $user->id,
            'name' => 'Luz',
            'amount' => 300,
            'due_date' => '2026-10-01',
            'status' => 'pending',
        ]);

        $ownGoal = SavingGoal::create([
            'user_id' => $user->id,
            'name' => 'Mi meta',
            'target_amount' => 1000,
            'current_amount' => 0,
            'status' => 'active',
        ]);

        $sharedGoal = SavingGoal::create([
            'user_id' => $otherUser->id,
            'name' => 'Meta compartida',
            'target_amount' => 1000,
            'current_amount' => 0,
            'is_group' => true,
            'status' => 'active',
        ]);
        $sharedGoal->participants()->attach($user->id, ['role' => 'member']);

        Sanctum::actingAs($user);

        $this->postJson('/api/profile/reset-data', ['password' => 'password'])
            ->assertStatus(200);

        // La cuenta sigue existiendo.
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        // Sus propios datos se borraron.
        $this->assertDatabaseMissing('bills', ['id' => $bill->id]);
        $this->assertDatabaseMissing('saving_goals', ['id' => $ownGoal->id]);

        // La meta de la otra persona sigue existiendo, solo se le quitó a él.
        $this->assertDatabaseHas('saving_goals', ['id' => $sharedGoal->id]);
        $this->assertDatabaseMissing('saving_goal_members', [
            'saving_goal_id' => $sharedGoal->id,
            'user_id' => $user->id,
        ]);
    }
}
