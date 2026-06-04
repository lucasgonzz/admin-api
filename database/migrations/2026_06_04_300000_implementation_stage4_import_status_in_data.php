<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Documentación: campo import_status en implementation_stages.data (Etapa 4).
 *
 * No modifica el esquema de la base de datos. La columna `data` de `implementation_stages`
 * ya es JSON nullable y almacena el estado del flujo de archivos Excel de la Etapa 4.
 *
 * A partir de esta versión, `data` puede incluir el subcampo `import_status`:
 *
 * {
 *   "import_status": {
 *     "articles":  { "status": "pending|importing|success|failed", "error": null, "imported_at": null },
 *     "clients":   { ... },
 *     "suppliers": { ... }
 *   }
 * }
 *
 * Se inicializa dinámicamente en ImplementationImportService::process_files y se actualiza
 * en ImplementationImportService::execute_import. El panel admin-spa escucha cambios vía Pusher
 * (evento implementation.import.status_updated).
 */
class ImplementationStage4ImportStatusInData extends Migration
{
    /**
     * Sin cambios de esquema: solo documentación en código.
     *
     * @return void
     */
    public function up()
    {
        // Intencionalmente vacío: el estado de importación vive en el JSON `data`.
    }

    /**
     * Sin cambios de esquema.
     *
     * @return void
     */
    public function down()
    {
        // Intencionalmente vacío.
    }
}
