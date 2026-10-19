<?php

namespace App\Http\Controllers;

use App\Services\SharedProductFactsService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SharedProductFactsController extends Controller
{
    public function show(Request $request, $gtin, SharedProductFactsService $service)
    {
        return response()->json($service->read($service->canonicalGtin($gtin)));
    }

    public function claim(Request $request, $gtin, SharedProductFactsService $service)
    {
        $trace = $request->validate([
            'auth_user_id' => 'nullable|integer|min:1', 'auth_user_name' => 'nullable|string|max:190',
        ]);
        return response()->json($service->claim($service->canonicalGtin($gtin), (int) $request->attributes->get('sync_client')->id, $trace));
    }

    public function update(Request $request, $gtin, SharedProductFactsService $service)
    {
        $data = $request->validate([
            'lease_token' => 'required|string|max:128', 'status' => 'required|in:found,not_found,failed',
            'product' => 'required_if:status,found|nullable|array:name,description,brand,weight_kg,width_cm,height_cm,length_cm,net_content,sources',
            'product.name' => 'required_if:status,found|string|max:300',
            'product.description' => 'nullable|string|max:10000',
            'product.brand' => 'nullable|string|max:190',
            'product.net_content' => 'nullable|string|max:190',
            // Peso bruto y dimensiones del embalaje de envío, nunca peso neto/medidas del
            // producto. Article y los cotizadores logísticos consumen estos mismos campos.
            'product.weight_kg' => 'nullable|numeric|min:0|max:1000000',
            'product.width_cm' => 'nullable|numeric|min:0|max:1000000',
            'product.height_cm' => 'nullable|numeric|min:0|max:1000000',
            'product.length_cm' => 'nullable|numeric|min:0|max:1000000',
            'product.sources' => 'required_if:status,found|array|min:1|max:10',
            'product.sources.*' => 'array:url,title',
            'product.sources.*.url' => 'required|url|max:2048',
            'product.sources.*.title' => 'nullable|string|max:300',
        ]);
        if ($data['status'] === 'found') {
            foreach ($data['product']['sources'] as $source) {
                if (!$this->publicUrl($source['url'])) {
                    throw ValidationException::withMessages(['product.sources' => 'Las fuentes deben ser direcciones públicas HTTP o HTTPS.']);
                }
            }
            // Se completa el contrato explícito, sin persistir campos del catálogo privado.
            $data['product'] = array_merge(array_fill_keys([
                'name', 'description', 'brand', 'weight_kg', 'width_cm', 'height_cm', 'length_cm', 'net_content',
            ], null), $data['product']);
            foreach (['weight_kg', 'width_cm', 'height_cm', 'length_cm'] as $field) {
                if ($data['product'][$field] !== null) {
                    $data['product'][$field] = (float) $data['product'][$field];
                }
            }
            foreach ($data['product']['sources'] as &$source) {
                $source = ['url' => $source['url'], 'title' => $source['title'] ?? null];
            }
            unset($source);
        }
        return response()->json($service->finish($service->canonicalGtin($gtin), (int) $request->attributes->get('sync_client')->id, $data));
    }

    private function publicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || empty($parts['host'])) {
            return false;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (strpos($host, '::ffff:') === 0) {
            $host = substr($host, 7);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D', $host)
            && !preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/D', $host);
    }
}
