<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Support\RoleHome;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ProductionReferenceSeedTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        Schema::table('barangays', function (Blueprint $table) {
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('region')->nullable();
        });
        Schema::create('disaster_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('damage_classes', function (Blueprint $table) {
            $table->id();
            $table->string('class_code')->unique();
            $table->string('class_name');
            $table->text('description')->nullable();
            $table->integer('display_order');
            $table->boolean('counts_as_damaged');
            $table->timestamps();
        });
        Schema::create('vulnerability_categories', function (Blueprint $table) {
            $table->id();
            $table->string('category_name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function test_fresh_production_reference_seeding_is_usable_and_creates_no_demo_users(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        $counts = [];
        $created = [];
        foreach (['roles', 'barangays', 'disaster_types', 'damage_classes', 'vulnerability_categories'] as $table) {
            $counts[$table] = DB::table($table)->count();
            $created[$table] = DB::table($table)->orderBy('id')->pluck('created_at', 'id')->all();
            $this->assertGreaterThan(0, $counts[$table], $table.' must be populated');
        }
        $this->assertDatabaseCount('users', 0);
        $this->travel(1)->days();
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
            $this->assertSame($created[$table], DB::table($table)->orderBy('id')->pluck('created_at', 'id')->all(), $table.' creation dates changed');
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_seed_creates_one_login_per_role_and_preserves_existing_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $users = User::query()->with('role')->get()->keyBy('role.code');
        $this->assertDatabaseCount('users', count(RoleHome::PREFIXES));
        foreach (RoleHome::PREFIXES as $code => $path) {
            $user = $users->get($code);
            $this->assertNotNull($user, 'Missing user for '.$code);
            $this->assertNotEmpty($user->first_name);
            $this->assertNotEmpty($user->last_name);
            $this->assertNotSame($user->role->name, $user->display_name);
            $this->assertTrue(Hash::check('password', $user->password));
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($path);
            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
        $this->assertSame($users->count(), $users->pluck('display_name')->unique()->count());

        $resident = $users->get('resident');
        $resident->update(['first_name' => 'Marissa', 'password' => 'changed-password']);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame('Marissa', $resident->fresh()->first_name);
        $this->assertTrue(Hash::check('changed-password', $resident->fresh()->password));
        $this->assertDatabaseCount('users', count(RoleHome::PREFIXES));
    }
}
