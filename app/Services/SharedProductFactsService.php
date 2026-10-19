<?php

namespace App\Services;

use App\Models\SharedProductFact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SharedProductFactsService
{
    const LEASE_SECONDS = 180;

    public function canonicalGtin($gtin): string
    {
        if (!is_string($gtin) || !preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/D', $gtin)) {
            $this->invalidGtin();
        }
        $sum = 0;
        $factor = 3;
        for ($i = strlen($gtin) - 2; $i >= 0; $i--) {
            $sum += (int) $gtin[$i] * $factor;
            $factor = $factor === 3 ? 1 : 3;
        }
        $canonical = str_pad($gtin, 14, '0', STR_PAD_LEFT);
        $prefix = (int) substr($canonical, 1, 3);
        if ((10 - ($sum % 10)) % 10 !== (int) substr($gtin, -1)
            || trim($gtin, '0') === ''
            || ($prefix >= 20 && $prefix <= 29)
            || ($prefix >= 40 && $prefix <= 49)
            || ($prefix >= 200 && $prefix <= 299)) {
            $this->invalidGtin();
        }
        return $canonical;
    }

    private function invalidGtin()
    {
        throw ValidationException::withMessages(['gtin' => 'El código no es un GTIN comercial válido o es de uso interno/balanza.']);
    }

    public function read(string $gtin): array
    {
        return $this->publicState(SharedProductFact::where('gtin', $gtin)->first());
    }

    private function publicState($fact): array
    {
        if ($fact && $fact->expires_at && $fact->expires_at->isFuture()
            && in_array($fact->status, ['found', 'not_found'], true)) {
            // MySQL reordena las claves JSON al guardarlas. Se reconstruye la ficha desde
            // el schema público, conservando sus campos explícitos y sin filtrar metadatos.
            $product = null;
            if ($fact->status === 'found') {
                $product = [];
                foreach (['name', 'description', 'brand', 'weight_kg', 'width_cm', 'height_cm', 'length_cm', 'net_content', 'sources'] as $field) {
                    $product[$field] = $fact->product[$field] ?? null;
                }
            }
            return ['status' => $fact->status, 'product' => $product, 'retry_after' => null];
        }
        if ($fact && $fact->lease_expires_at && $fact->lease_expires_at->isFuture()) {
            return ['status' => 'pending', 'product' => null, 'retry_after' => max(1, now()->diffInSeconds($fact->lease_expires_at))];
        }
        return ['status' => 'miss', 'product' => null, 'retry_after' => null];
    }

    public function claim(string $gtin, int $clientId, array $trace): array
    {
        // INSERT IGNORE + unique GTIN resuelve la carrera de la primera fila. El lock de esa
        // misma fila serializa las reservas entre procesos y negocios, sin depender de Redis.
        return DB::transaction(function () use ($gtin, $clientId, $trace) {
            DB::table('shared_product_facts')->insertOrIgnore([
                'gtin' => $gtin, 'status' => 'miss', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $fact = SharedProductFact::where('gtin', $gtin)->lockForUpdate()->firstOrFail();
            $state = $this->publicState($fact);
            if ($state['status'] !== 'miss') {
                return $state;
            }
            $token = Str::random(64);
            $fact->fill([
                'status' => 'pending', 'product' => null, 'expires_at' => null,
                'lease_client_id' => $clientId, 'lease_token_hash' => hash('sha256', $token),
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                'auth_user_id' => $trace['auth_user_id'] ?? null,
                'auth_user_name' => $trace['auth_user_name'] ?? null,
            ])->save();
            return ['status' => 'claimed', 'lease_token' => $token, 'product' => null, 'retry_after' => self::LEASE_SECONDS];
        }, 3);
    }

    public function finish(string $gtin, int $clientId, array $data): array
    {
        return DB::transaction(function () use ($gtin, $clientId, $data) {
            $fact = SharedProductFact::where('gtin', $gtin)->lockForUpdate()->first();
            if (!$fact || (int) $fact->lease_client_id !== $clientId
                || !$fact->lease_expires_at || !$fact->lease_expires_at->isFuture()
                || !hash_equals((string) $fact->lease_token_hash, hash('sha256', $data['lease_token']))) {
                abort(409, 'La reserva venció o pertenece a otra consulta.');
            }
            $status = $data['status'];
            $fact->fill([
                'status' => $status === 'failed' ? 'miss' : $status,
                'product' => $status === 'found' ? $data['product'] : null,
                'expires_at' => $status === 'found' ? now()->addDays(30) : ($status === 'not_found' ? now()->addHours(6) : null),
                'lease_client_id' => null, 'lease_token_hash' => null, 'lease_expires_at' => null,
            ])->save();
            return $this->publicState($fact);
        }, 3);
    }
}
