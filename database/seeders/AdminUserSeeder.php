<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run()
    {

        $admins = [
            [
                'email'         => 'lucas',
                'password'      => '1234',
                'name'          => 'Lucas',
                'is_default_support_owner'  => 0,
            ],
            [
                'email'         => 'martin',
                'password'      => '1234',
                'name'          => 'Martin',
                'is_default_support_owner'  => 1,
            ],
        ];

        foreach ($admins as $admin) {
            Admin::updateOrCreate(
                ['email' => $admin['email']],
                [
                    'name' => $admin['name'],
                    'is_default_support_owner' => $admin['is_default_support_owner'],
                    'password' => Hash::make($admin['password']),
                ]
            );
            $this->command->info("Admin listo: {$admin['email']} / {$admin['password']}");
        }


    }
}
