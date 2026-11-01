<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ResidentDemoSeeder extends Seeder
{
    public const EMAIL = 'resident@mapr.test';

    public const BUILDING_REFERENCE = 'CBMS22-BLDG-4910';

    public const HOUSEHOLD_REFERENCE = 'CBMS22-HH-4910';

    public const RESIDENT_REFERENCE = 'CBMS22-RES-4910-02';

    private const PASSWORD = 'MapRResident!2026';

    private const LONGITUDE = '126.20313';

    private const LATITUDE = '9.069381354';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('ResidentDemoSeeder runs only in local or testing environments.');
        }

        $this->call(RoleSeeder::class);
        $roleId = DB::table('roles')->where('code', 'resident')->value('id');
        if ($roleId === null) {
            throw new RuntimeException('Role [resident] was not found.');
        }

        $barangayId = $this->mabuaId();
        $existing = User::withTrashed()->where('email', self::EMAIL)->first();
        if ($existing) {
            if ($this->isLegacyDemo($existing)) {
                $existing->forceFill([
                    'first_name' => 'Maricar',
                    'middle_name' => 'Dumpa',
                    'last_name' => 'Irizari',
                    'role_id' => $roleId,
                    'barangay_id' => $barangayId,
                ])->save();
                $this->command?->warn('Updated '.self::EMAIL.' to Maricar Dumpa Irizari. The password was not changed.');
            } elseif (! $this->isMaricar($existing)) {
                $this->command?->warn('Skipped existing '.self::EMAIL.'. The password was not changed.');

                return;
            }
            $user = $existing;
        } else {
            $user = User::query()->create([
                'first_name' => 'Maricar',
                'middle_name' => 'Dumpa',
                'last_name' => 'Irizari',
                'email' => self::EMAIL,
                'password' => self::PASSWORD,
                'account_status' => 'active',
                'email_verified_at' => now(),
                'role_id' => $roleId,
                'barangay_id' => $barangayId,
            ]);
            $this->command?->info('Created '.self::EMAIL.' for barangay Mabua.');
        }

        $this->connectRegistry($user, $barangayId);
    }

    private function isLegacyDemo(User $user): bool
    {
        return $user->first_name === 'MapR' && $user->last_name === 'Resident';
    }

    private function isMaricar(User $user): bool
    {
        return $user->first_name === 'Maricar'
            && $user->middle_name === 'Dumpa'
            && $user->last_name === 'Irizari';
    }

    private function connectRegistry(User $user, int $barangayId): void
    {
        foreach (['buildings', 'households', 'residents', 'account_resident_links'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $buildingId = $this->buildingId($barangayId);
        $householdId = $this->householdId($buildingId, $barangayId);
        $residentId = $this->residentId($householdId);
        $this->linkAccount($user, $residentId);
    }

    private function buildingId(int $barangayId): int
    {
        $existingId = DB::table('buildings')->where('building_reference', self::BUILDING_REFERENCE)->value('id');
        if ($existingId) {
            DB::table('buildings')->where('id', $existingId)->update([
                'address_description' => '32',
                'updated_at' => now(),
            ]);
            $this->writeBuildingPoint((int) $existingId);

            return (int) $existingId;
        }

        $id = DB::table('buildings')->insertGetId([
            'building_reference' => self::BUILDING_REFERENCE,
            'address_description' => '32',
            'location_source' => 'CBMS2022HOUSEHOLD ID 4910',
            'location_verification_status' => 'unverified',
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->writeBuildingPoint((int) $id);

        return (int) $id;
    }

    private function writeBuildingPoint(int $id): void
    {
        if (! Schema::hasColumn('buildings', 'geographic_point')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::update(
                "UPDATE buildings SET geographic_point = ST_GeomFromText(?, 4326, 'axis-order=long-lat') WHERE id = ?",
                [sprintf('POINT(%s %s)', self::LONGITUDE, self::LATITUDE), $id],
            );

            return;
        }
        DB::table('buildings')->where('id', $id)->update([
            'geographic_point' => self::LONGITUDE.' '.self::LATITUDE,
        ]);
    }

    private function householdId(int $buildingId, int $barangayId): int
    {
        $row = [
            'household_status' => 'active',
            'record_source' => 'CBMS2022HOUSEHOLD ID 4910',
            'building_id' => $buildingId,
            'barangay_id' => $barangayId,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('households', 'source_household_size')) {
            $row['source_household_size'] = 2;
            $row['source_nuclear_families'] = 1;
            $row['source_no_males'] = 0;
            $row['source_no_females'] = 2;
        }

        $existingId = DB::table('households')->where('household_reference', self::HOUSEHOLD_REFERENCE)->value('id');
        if ($existingId) {
            DB::table('households')->where('id', $existingId)->update($row);

            return (int) $existingId;
        }

        $row['household_reference'] = self::HOUSEHOLD_REFERENCE;
        $row['created_at'] = now();

        return (int) DB::table('households')->insertGetId($row);
    }

    private function residentId(int $householdId): int
    {
        $row = [
            'first_name' => 'Maricar',
            'middle_name' => 'Dumpa',
            'last_name' => 'Irizari',
            'birth_date' => '1985-02-13',
            'sex' => 'female',
            'residency_status' => 'active',
            'record_source' => 'CBMS DATA 1.csv person 02',
            'household_id' => $householdId,
            'updated_at' => now(),
        ];
        $existingId = DB::table('residents')->where('registry_reference', self::RESIDENT_REFERENCE)->value('id');
        if ($existingId) {
            DB::table('residents')->where('id', $existingId)->update($row);

            return (int) $existingId;
        }

        $row['registry_reference'] = self::RESIDENT_REFERENCE;
        $row['created_at'] = now();

        return (int) DB::table('residents')->insertGetId($row);
    }

    private function linkAccount(User $user, int $residentId): void
    {
        $byUser = DB::table('account_resident_links')->where('user_id', $user->id)->first();
        $byResident = DB::table('account_resident_links')->where('resident_id', $residentId)->first();
        if ($byUser && (int) $byUser->resident_id === $residentId) {
            DB::table('account_resident_links')->where('id', $byUser->id)->update([
                'authorization_reference' => self::RESIDENT_REFERENCE,
                'updated_at' => now(),
            ]);

            return;
        }
        if ($byUser || $byResident) {
            return;
        }

        $verifierId = User::query()
            ->where('id', '!=', $user->id)
            ->where('account_status', 'active')
            ->orderBy('id')
            ->value('id');

        DB::table('account_resident_links')->insert([
            'verified_at' => now(),
            'authorization_reference' => self::RESIDENT_REFERENCE,
            'user_id' => $user->id,
            'resident_id' => $residentId,
            'verified_by' => $verifierId ?: $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mabuaId(): int
    {
        $matches = DB::table('barangays')
            ->whereRaw('LOWER(TRIM(barangay_name)) = ?', ['mabua'])
            ->get(['id', 'barangay_code', 'barangay_name']);

        if ($matches->isEmpty()) {
            throw new RuntimeException('Barangay Mabua was not found. Seed barangays before creating the resident account.');
        }

        $preferred = $matches->firstWhere('barangay_code', '166819008');
        $barangay = $preferred ?? ($matches->count() === 1 ? $matches->first() : null);
        if ($barangay === null) {
            throw new RuntimeException('More than one barangay is named Mabua. Resolve that before seeding the resident account.');
        }

        return (int) $barangay->id;
    }
}
