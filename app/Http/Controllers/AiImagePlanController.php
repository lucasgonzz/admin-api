<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\AiImagePlan;
use App\Models\Client;
use App\Models\IaImagenPedido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiImagePlanController extends Controller
{
    public function index_json()
    {
        return response()->json([
            'ai_image_plans' => AiImagePlan::orderBy('precio_ars')->orderBy('id')->get(),
            'costos' => $this->costos(),
        ]);
    }

    public function store_json(Request $request)
    {
        $plan = AiImagePlan::create($this->validar_plan($request));

        return response()->json(['ai_image_plan' => $plan->refresh()], 201);
    }

    public function update_json(Request $request, AiImagePlan $plan)
    {
        $plan->update($this->validar_plan($request, $plan));

        return response()->json(['ai_image_plan' => $plan->refresh()]);
    }

    public function update_costos_json(Request $request)
    {
        $reglas = ['required', 'numeric', 'min:0.000001', 'max:1000', 'regex:/^\d+(\.\d{1,6})?$/'];
        $datos = $request->validate(['medium' => $reglas, 'high' => $reglas], [], [
            'medium' => 'costo de calidad media', 'high' => 'costo de calidad alta',
        ]);

        DB::transaction(function () use ($datos) {
            AdminSetting::set('ai_image_cost_medium_usd', $datos['medium']);
            AdminSetting::set('ai_image_cost_high_usd', $datos['high']);
        });

        return response()->json(['costos' => $this->costos()]);
    }

    public function client_json(Client $client)
    {
        return response()->json($this->configuracion_del_cliente($client));
    }

    public function assign_json(Request $request, Client $client)
    {
        $datos = $request->validate([
            'ai_image_plan_id' => ['present', 'nullable', 'integer'],
            'ai_image_quality' => ['required', Rule::in(['medium', 'high'])],
        ], [], [
            'ai_image_plan_id' => 'paquete de imágenes', 'ai_image_quality' => 'calidad de imagen',
        ]);

        $client = DB::transaction(function () use ($client, $datos) {
            // El alta de pedidos toma el mismo candado para leer una sola configuración por reserva.
            $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $id_plan = $datos['ai_image_plan_id'];

            if ($id_plan !== null && (int) $id_plan !== $client->ai_image_plan_id) {
                $activo = AiImagePlan::whereKey($id_plan)->where('activo', true)->lockForUpdate()->first();

                if ($activo === null) {
                    throw ValidationException::withMessages(['ai_image_plan_id' => 'Elegí un paquete de imágenes activo.']);
                }
            }

            $client->ai_image_plan_id = $id_plan;
            $client->ai_image_quality = $datos['ai_image_quality'];
            $client->save();

            return $client;
        });

        return response()->json($this->configuracion_del_cliente($client));
    }

    private function validar_plan(Request $request, AiImagePlan $plan = null): array
    {
        $nombre = Rule::unique('ai_image_plans', 'nombre');

        if ($plan !== null) {
            $nombre->ignore($plan->id);
        }

        return $request->validate([
            'nombre' => ['required', 'string', 'max:255', $nombre],
            'precio_ars' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'tope_usd_mensual' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'activo' => ['sometimes', 'boolean'],
        ], [], ['precio_ars' => 'precio mensual en pesos', 'tope_usd_mensual' => 'tope mensual en dólares']);
    }

    private function costos(): array
    {
        $valores = AdminSetting::whereIn('key', ['ai_image_cost_medium_usd', 'ai_image_cost_high_usd'])
            ->pluck('value', 'key');

        return [
            'medium' => (string) $valores->get('ai_image_cost_medium_usd', '0.065000'),
            'high' => (string) $valores->get('ai_image_cost_high_usd', '0.230000'),
        ];
    }

    private function configuracion_del_cliente(Client $client): array
    {
        $periodo = now('America/Argentina/Buenos_Aires')->format('Y-m');
        $consumo = IaImagenPedido::where('client_id', $client->id)
            ->where('periodo_consumo', $periodo)
            ->selectRaw('SUM(costo_cobrado_microusd) AS cobrado, SUM(CASE WHEN costo_cobrado_microusd IS NULL THEN costo_reservado_microusd ELSE 0 END) AS reservado')
            ->first();

        return [
            'ai_image_plan_id' => $client->ai_image_plan_id,
            'ai_image_quality' => $client->ai_image_quality,
            'periodo' => $periodo,
            'consumo_usd' => (int) $consumo->cobrado / 1000000,
            'reservado_usd' => (int) $consumo->reservado / 1000000,
        ];
    }
}
