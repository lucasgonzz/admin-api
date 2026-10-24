<?php

namespace App\Services;

use App\Models\Client;

/** Reutiliza únicamente la consulta de datos; jamás llama a ask_claude ni responde al cliente. */
class SupportCodexDiagnosticService extends SistemaQueryService
{
    public function read(Client $client, string $query): ?array
    {
        return $this->fetch_system_data($client, $query);
    }
}
