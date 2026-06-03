<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientApi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GolonorteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'user_id'               => 800,
                'name'                  => 'Gaston',
                'company_name'          => 'Golonorte',
                'apis_url'              => [
                    [
                        'url'   => 'https://api-colman.comerciocity.com/public',
                        'path'  => 'colman/api'
                    ],
                    [
                        'url'   => 'https://api-colman-prueba.comerciocity.com/public',
                        'path'  => 'colman-prueba/api'
                    ],
                ],
                'current_version_id'    => 1,
                'uuid'                  => 'iddeprueba'
            ],
        ];

        foreach ($models as $model) {

            if (empty($model['slug'])) {
                $model['slug'] = Str::slug($model['name']);
            }
            if (empty($model['api_key'])) {
                $model['api_key'] = Str::random(40);
            }
            // if (empty($model['inbound_api_key'])) {
            //     $model['inbound_api_key'] = Str::random(40);
            // }
            $client = Client::updateOrCreate(
                [
                    'user_id'   => $model['user_id']
                ],
                [
                    'name'                  => $model['name'],
                    'company_name'          => $model['company_name'],
                    'current_version_id'    => $model['current_version_id'],
                    'uuid'                  => $model['uuid'],
                    'slug'                  => $model['slug'],
                    'api_key'               => $model['api_key'],
                ]
            );

            foreach ($model['apis_url'] as $api_url) {
                ClientApi::create([
                    'url'   => $api_url['url'],
                    'path'   => $api_url['path'],
                    'client_id' => $client->id,
                ]);
            }
        }
    }
}
