<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ProductionReferenceSeedTest extends TestCase
{
    use CreatesMaprAuthSchema;

    public function test_fresh_production_reference_seeding_is_usable_and_creates_no_demo_users(): void
    {
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
        $this->app['env'] = 'production';
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        $counts = [];
        foreach (['roles', 'barangays', 'disaster_types', 'damage_classes', 'vulnerability_categories'] as $table) {
            $counts[$table] = DB::table($table)->count();
            $this->assertGreaterThan(0, $counts[$table], $table.' must be populated');
        }
        $this->assertDatabaseCount('users', 0);
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertDatabaseCount('users', 0);
    }
}
