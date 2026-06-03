<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ClientSeeder extends Seeder
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
                'name'                  => 'Fenix',
                'api_url'               => 'http://empresa.local:8000',
                'api_key'               => 'apikeydeprueba',
                'inbound_api_key'       => 'inboundapikeydeprueba',
                'current_version_id'    => 1,
                'uuid'                  => 'iddeprueba'
            ],
            [
                'name'                  => 'San Blas',
                'api_url'               => 'http://empresa.local:8000',
                'api_key'               => 'apikeydeprueba',
                'inbound_api_key'       => 'inboundapikeydeprueba',
                'current_version_id'    => 1,
                'uuid'                  => 'iddeprueba',
                'phone'                 => '+543444622139',
            ],
        ];

        foreach ($models as $model) {

            if (empty($model['slug'])) {
                $model['slug'] = Str::slug($model['name']);
            }
            if (empty($model['api_key'])) {
                $model['api_key'] = Str::random(40);
            }
            if (empty($model['inbound_api_key'])) {
                $model['inbound_api_key'] = Str::random(40);
            }
            $client = Client::create($model);
        }

    }
}
