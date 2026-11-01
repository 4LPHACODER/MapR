<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ResidentProfilePhotoTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        Storage::fake('local');
    }

    public function test_a_resident_can_replace_and_remove_only_their_own_profile_photo(): void
    {
        $barangay = $this->barangay();
        $owner = $this->user('resident', 'Photo Owner', $barangay);
        $neighbor = $this->user('resident', 'Photo Neighbor', $barangay);

        $this->actingAs($owner)->getJson('/resident/api/profile')
            ->assertOk()
            ->assertJsonPath('data.profile_photo_url', null);

        $created = $this->actingAs($owner)->postJson('/resident/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('face.jpg', 48, 32),
            'user_id' => $neighbor->id,
        ])->assertOk();
        $created->assertJsonPath('data.contact_number', null);
        $this->assertStringStartsWith('/resident/api/profile/photo?v=', (string) $created->json('data.profile_photo_url'));
        $this->assertStringNotContainsString('profile-photos/', $created->getContent());

        $owner->refresh();
        $firstPath = $owner->profile_photo_path;
        $this->assertNotNull($firstPath);
        $this->assertStringStartsWith('profile-photos/', $firstPath);
        Storage::disk('local')->assertExists($firstPath);
        $this->assertNull($neighbor->fresh()->profile_photo_path);

        $this->actingAs($owner)->get('/resident/api/profile/photo')->assertOk();
        $this->actingAs($neighbor)->get('/resident/api/profile/photo')->assertNotFound();

        $replaced = $this->actingAs($owner)->postJson('/resident/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('replacement.png', 24, 24),
        ])->assertOk();
        $owner->refresh();
        $this->assertNotSame($firstPath, $owner->profile_photo_path);
        $this->assertNotSame($created->json('data.profile_photo_url'), $replaced->json('data.profile_photo_url'));
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($owner->profile_photo_path);

        $kept = $owner->profile_photo_path;
        $this->actingAs($owner)->postJson('/resident/api/profile/photo', [
            'photo' => UploadedFile::fake()->createWithContent('note.jpg', 'not-an-image'),
        ])->assertStatus(422);
        $this->actingAs($owner)->postJson('/resident/api/profile/photo', [
            'photo' => UploadedFile::fake()->createWithContent('face.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ])->assertStatus(422);
        $this->actingAs($owner)->postJson('/resident/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('wide.jpg', 4001, 12),
        ])->assertStatus(422);
        $this->actingAs($owner)->postJson('/resident/api/profile/photo', [
            'photo' => UploadedFile::fake()->create('large.jpg', 2049, 'image/jpeg'),
        ])->assertStatus(422);
        $this->assertSame($kept, $owner->fresh()->profile_photo_path);
        Storage::disk('local')->assertExists($kept);

        $this->actingAs($owner)->patchJson('/resident/api/profile', [
            'contact_number' => '09170000000',
        ])->assertOk()->assertJsonPath('data.contact_number', '09170000000');
        $this->assertSame($kept, $owner->fresh()->profile_photo_path);

        $this->actingAs($owner)->deleteJson('/resident/api/profile/photo')
            ->assertOk()
            ->assertJsonPath('data.profile_photo_url', null);
        $this->assertNull($owner->fresh()->profile_photo_path);
        Storage::disk('local')->assertMissing($kept);
        $this->assertSame('09170000000', $owner->fresh()->contact_number);
    }

    public function test_profile_photo_routes_reject_guests_and_other_roles(): void
    {
        $user = $this->user('bdrrm', 'Other Role', $this->barangay());

        $this->getJson('/resident/api/profile/photo')->assertUnauthorized();
        $this->actingAs($user)->postJson('/resident/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('face.jpg'),
        ])->assertForbidden();
    }

    private function barangay(): int
    {
        return DB::table('barangays')->insertGetId([
            'barangay_code' => '166819099',
            'barangay_name' => 'Mabua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(string $role, string $name, int $barangayId): User
    {
        $roleId = DB::table('roles')->where('code', $role)->value('id');
        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => ucfirst($role),
                'code' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        [$first, $rest] = array_pad(explode(' ', $name, 2), 2, 'User');

        return User::query()->create([
            'first_name' => $first,
            'last_name' => $rest,
            'email' => strtolower(str_replace(' ', '.', $name)).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
            'barangay_id' => $barangayId,
        ]);
    }
}
