<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
       User::updateOrcreate(['username' => 'israelnkum'], [
            'name' => 'Israel Nkum',
            'username' => 'israelnkum',
            'email' => 'israelnkum@gmail.com',
            'password' => Hash::make(1),
            'phone_number' => '0249051415',
            'uuid' => Str::uuid(),
        ]);
    }
}
