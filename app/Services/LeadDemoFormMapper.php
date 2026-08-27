<?php

namespace App\Services;

use App\Models\Lead;
use Carbon\Carbon;

/**
 * Traduce las nueve respuestas del formulario de configuración de la página inmersiva de demo
 * (`contexto/demo_catalogo.md` §2) hacia y desde las columnas de `leads` (grupo 300, prompt 01).
 *
 * Es la ÚNICA pieza del sistema que conoce este mapeo. En particular, tres de las nueve preguntas
 * reusan columnas que la tabla `leads` ya tenía antes de este prompt (ver docblock de la migración
 * `2026_07_31_160000_add_demo_form_fields_to_leads_table.php`):
 *
 *  - `tipo_precios` (`unico` | `listas`)      → `use_price_lists` (boolean)
 *  - `usa_depositos` (boolean)                → `use_deposits` (boolean), directo
 *  - `usa_cuentas_corrientes_clientes` (bool) → `omitir_cuentas_corrientes` (boolean), INVERTIDO
 *
 * Las otras seis respuestas se escriben directo en columnas homónimas creadas por esa misma
 * migración. Nadie más debe hacer esta traducción a mano: cualquier lugar que necesite leer o
 * escribir respuestas del formulario tiene que pasar por `to_lead()` / `from_lead()`.
 */
class LeadDemoFormMapper
{
    /**
     * Las nueve respuestas en su valor por defecto, copiadas a mano de
     * `contexto/demo_catalogo.md` §2 (tabla "FORMULARIO DE LA PÁGINA INMERSIVA", columna Default).
     *
     * Son los mismos valores que la página inmersiva muestra ya preseleccionados: el lead confirma,
     * no completa. Si algún día se cambian en el catálogo, hay que cambiarlos también acá.
     *
     * A propósito NO se leen del catálogo sincronizado (`demo_catalogo.json`): ese JSON puede no
     * estar sincronizado todavía y este valor tiene que existir siempre.
     */
    const RESPUESTAS_POR_DEFECTO = [
        'tipo_precios'                       => 'unico',
        'costos_en_dolares'                  => false,
        'descuentos_por_metodo_pago'         => true,
        'usa_cuentas_corrientes_clientes'    => true,
        'usa_cuentas_corrientes_proveedores' => true,
        'usa_presupuestos'                   => false,
        'registra_compras'                   => true,
        'usa_ecommerce'                      => true,
        'usa_depositos'                      => false,
    ];

    /**
     * Aplica al lead las nueve respuestas del formulario, traduciéndolas a las columnas
     * correspondientes. NO persiste: deja el modelo modificado en memoria y es responsabilidad de
     * quien llama decidir cuándo hacer `$lead->save()` (por ejemplo, junto con
     * `demo_form_completado_at` en el mismo `save()`).
     *
     * @param Lead                  $lead       Lead sobre el que se aplican las respuestas.
     * @param array<string, mixed>  $respuestas Respuestas crudas del formulario, con las nueve
     *                                          claves de `demo_catalogo.md` §2: `tipo_precios`
     *                                          (string `unico`|`listas`) y las otras ocho como
     *                                          booleanos (`usa_depositos`,
     *                                          `usa_cuentas_corrientes_clientes`,
     *                                          `costos_en_dolares`,
     *                                          `descuentos_por_metodo_pago`,
     *                                          `usa_cuentas_corrientes_proveedores`,
     *                                          `usa_presupuestos`, `registra_compras`,
     *                                          `usa_ecommerce`). Claves ausentes se toman como el
     *                                          valor "apagado" (false / unico).
     *
     * @return Lead El mismo lead recibido, con los atributos modificados (sin guardar).
     */
    public static function to_lead(Lead $lead, array $respuestas): Lead
    {
        // tipo_precios es la única respuesta no-booleana del formulario: 'listas' activa la
        // columna existente use_price_lists; cualquier otro valor (incluido 'unico', el default
        // del catálogo) cae en precio único (false).
        $lead->use_price_lists = (($respuestas['tipo_precios'] ?? null) === 'listas');

        // usa_depositos → use_deposits: mapeo directo, mismo valor booleano, la columna existente
        // solo cambia el nombre a la convención en inglés que ya tenía la tabla `leads`.
        $lead->use_deposits = (bool) ($respuestas['usa_depositos'] ?? false);

        // usa_cuentas_corrientes_clientes → omitir_cuentas_corrientes, INVERTIDO A PROPÓSITO.
        // La columna existente nació con semántica negativa ("omitir") mientras que la pregunta
        // del formulario está en positivo ("usa"). Un lead que SÍ usa cuentas corrientes (true)
        // tiene que quedar guardado con omitir_cuentas_corrientes = false, y viceversa. No es un
        // error de tipeo: ver la advertencia al principio del prompt que agregó este mapeo
        // (grupo 300, prompt 01) — es el error más fácil de cometer en todo este archivo.
        $lead->omitir_cuentas_corrientes = ! (bool) ($respuestas['usa_cuentas_corrientes_clientes'] ?? false);

        // Las seis respuestas restantes no tenían columna antes de este prompt: se escriben
        // directo, sin ninguna traducción, en su columna homónima.
        $lead->costos_en_dolares                  = (bool) ($respuestas['costos_en_dolares'] ?? false);
        $lead->descuentos_por_metodo_pago         = (bool) ($respuestas['descuentos_por_metodo_pago'] ?? false);
        $lead->usa_cuentas_corrientes_proveedores = (bool) ($respuestas['usa_cuentas_corrientes_proveedores'] ?? false);
        $lead->usa_presupuestos                   = (bool) ($respuestas['usa_presupuestos'] ?? false);
        $lead->registra_compras                   = (bool) ($respuestas['registra_compras'] ?? false);
        $lead->usa_ecommerce                      = (bool) ($respuestas['usa_ecommerce'] ?? false);

        return $lead;
    }

    /**
     * Inverso de `to_lead()`: reconstruye las nueve respuestas del formulario a partir del estado
     * actual del lead, para que la página pueda mostrar el estado actual cuando el lead vuelve a
     * abrir el formulario.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed> Las nueve respuestas, en el mismo formato que recibe `to_lead()`.
     */
    public static function from_lead(Lead $lead): array
    {
        return [
            'tipo_precios' => $lead->use_price_lists ? 'listas' : 'unico',

            'usa_depositos' => (bool) $lead->use_deposits,

            // Misma inversión que en to_lead(), en sentido contrario: omitir = false → usa = true.
            // No es un error de tipeo (ver comentario en to_lead()).
            'usa_cuentas_corrientes_clientes' => ! (bool) $lead->omitir_cuentas_corrientes,

            'costos_en_dolares'                  => (bool) $lead->costos_en_dolares,
            'descuentos_por_metodo_pago'         => (bool) $lead->descuentos_por_metodo_pago,
            'usa_cuentas_corrientes_proveedores' => (bool) $lead->usa_cuentas_corrientes_proveedores,
            'usa_presupuestos'                   => (bool) $lead->usa_presupuestos,
            'registra_compras'                   => (bool) $lead->registra_compras,
            'usa_ecommerce'                      => (bool) $lead->usa_ecommerce,
        ];
    }

    /**
     * Las nueve respuestas que hay que usar para configurar la instancia de demo de este lead.
     *
     * No es lo mismo que `from_lead()` y no se puede "simplificar" a `from_lead()`:
     * `from_lead()` refleja el estado de las columnas, y las columnas de un lead que no contestó
     * el formulario NO significan "contestó esto", significan "no contestó". Que hoy casi todas
     * coincidan con el catálogo no las vuelve una respuesta.
     *
     * ⚠️ Este docblock decía hasta el 27/8/2026 que "las seis columnas nuevas nacieron con
     * `->default(false)` mientras que los defaults del catálogo están en su mayoría encendidos", y
     * era FALSO — verificado contra la migración `2026_07_31_160000_add_demo_form_fields_to_leads_table.php`
     * y contra el esquema: esa migración copió a mano el default DOCUMENTADO EN EL CATÁLOGO para
     * cada una de las seis (`descuentos_por_metodo_pago`, `usa_cuentas_corrientes_proveedores`,
     * `registra_compras` y `usa_ecommerce` en `true`; `costos_en_dolares` y `usa_presupuestos` en
     * `false`), justamente para que un lead que nunca completa el formulario quede configurado
     * como dice el catálogo. La única respuesta en la que las dos fuentes discrepan hoy es
     * `usa_depositos`: el catálogo dice `false` y `Lead::booted()` fuerza `use_deposits = true` al
     * crear el lead.
     *
     * O sea que el riesgo real de confundirlas es más chico de lo que decía ese texto, pero el
     * método sigue haciendo falta y no se puede borrar: esa coincidencia se sostiene A MANO —el
     * catálogo se edita en `demo_catalogo.md` §2 y se sincroniza sin deploy, la columna sólo cambia
     * con una migración—, y basta que se muevan de a uno para que a un lead que todavía no abrió la
     * página se le arme la instancia con algo que nadie eligió. Caso alcanzable, porque el botón
     * "Disparar setup demo" del panel de Operaciones se puede pulsar en cualquier momento.
     *
     * No escribe, no persiste, no toca el lead.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed> Las nueve respuestas, en el mismo formato que devuelve
     *                              `from_lead()`.
     */
    public static function respuestas_efectivas(Lead $lead): array
    {
        /* Las columnas valen si ALGUIEN las escribió a conciencia, y hay dos maneras de que eso
         * haya pasado: el lead completó el formulario en la página inmersiva
         * (`demo_form_completado_at`) o un admin las editó a mano desde el modal del lead
         * (`demo_form_editado_admin_at`, misión del 27/8/2026). Sin ninguna de las dos marcas no
         * hay respuestas de nadie y valen los defaults del catálogo.
         *
         * La segunda marca la ponen los DOS caminos de escritura del modal, y tiene que ser así:
         * `LeadController::update_demo_form_json()` cuando se guarda la tarjeta, y
         * `LeadController::update_json()` cuando el "Guardar" general del modal cambia alguno de
         * los dos checkboxes del grupo Demo (`use_deposits` / `use_price_lists`) que escriben dos
         * de estas mismas nueve respuestas.
         *
         * 🔴 La segunda marca es el motivo entero por el que existe esa columna. Mientras la
         * condición miraba sólo `demo_form_completado_at`, una edición desde el panel se guardaba
         * en las columnas y el demo setup la ignoraba igual: Lucas cambiaba una respuesta, la
         * tarjeta se lo mostraba guardado, y la instancia se armaba con los defaults. */
        if ($lead->demo_form_completado_at === null && $lead->demo_form_editado_admin_at === null) {
            return self::RESPUESTAS_POR_DEFECTO;
        }

        return self::from_lead($lead);
    }

    /**
     * Todo lo que la tarjeta "Respuestas del formulario de la demo" del modal del lead necesita
     * para pintarse: las nueve respuestas efectivas más el contexto de dónde salieron y de qué
     * pasa si se cambian ahora.
     *
     * Puro, igual que `respuestas_efectivas()`: no toca la base, no sale a la red y no modifica el
     * lead. Lo llama el accesor `Lead::getDemoFormPanelAttribute()`, que a su vez viaja en el
     * detalle del lead — o sea que corre en cada apertura del modal y no puede permitirse una
     * query.
     *
     * Por qué el estado va armado desde acá y no lo compone el SPA con las columnas sueltas: el
     * aviso de la tarjeta ("lo completó el lead" / "lo editaste vos" / "pasaron las dos cosas" /
     * "son los valores por defecto") es la misma decisión que toma `respuestas_efectivas()` para
     * armar la instancia. Si
     * el front la volviera a deducir por su cuenta, el día que esa regla cambie —ya cambió una vez,
     * con la marca de edición manual— la tarjeta le estaría diciendo a Lucas algo distinto de lo
     * que el demo setup va a hacer.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    public static function estado_para_panel(Lead $lead): array
    {
        $completado_at = self::a_carbon($lead->demo_form_completado_at);
        $editado_at    = self::a_carbon($lead->demo_form_editado_admin_at);

        return [
            'respuestas'          => self::respuestas_efectivas($lead),
            'completado_por_lead' => $completado_at !== null,
            'completado_at'       => self::formatear($completado_at),
            'editado_por_admin'   => $editado_at !== null,
            'editado_admin_at'    => self::formatear($editado_at),
            'origen'              => self::origen($completado_at, $editado_at),

            /* El estado del roadmap va en el mismo bloque porque la tarjeta tiene que avisar
             * cuándo editar las respuestas ya no lo cambia: con el plan congelado y el setup fuera
             * de `pendiente`, el recorrido quedó armado con las respuestas viejas y los hitos
             * pueden estar marcados. */
            'plan_congelado'    => $lead->demo_plan_congelado_at !== null,
            'plan_congelado_at' => self::formatear(self::a_carbon($lead->demo_plan_congelado_at)),
            'setup_estado'      => $lead->demo_setup_status ?? 'pendiente',

            /* Un lead de la dinámica ACTUAL no tiene página inmersiva ni formulario: no hay nada
             * que editar y la tarjeta muestra sólo el cartel. Es la misma guardia que aplica el
             * endpoint que guarda (`LeadController::update_demo_form_json()`), expuesta acá para
             * que el front no tenga que deducirla de `demo_experiencia`. */
            'editable' => $lead->usa_experiencia_demo_nueva(),
        ];
    }

    /**
     * Quién escribió las respuestas que hoy tienen las columnas — sólo lo que se puede AFIRMAR.
     *
     * Cuatro valores, ninguno deducido: `defaults` si no las escribió nadie, `lead` si la única
     * marca es la del formulario público, `admin` si la única es la del panel, y `ambos` si las dos
     * puntas escribieron alguna vez.
     *
     * 🔴 POR QUÉ `ambos` Y NO "LA MÁS RECIENTE GANA" (corregido el 27/8/2026)
     * ----------------------------------------------------------------------
     * Hasta esta corrección el empate se resolvía comparando las dos fechas. Esa comparación es
     * inválida: `demo_form_completado_at` se sella en el PRIMER envío del lead y no se mueve nunca
     * más (`DemoExperienciaController::store_formulario_json()` la escribe sólo si estaba en
     * `null`, porque además dispara el setup automático), así que NO representa "la última vez que
     * el lead escribió".
     *
     * El caso medido: el lead contesta a las 10:00, el admin edita a las 11:00, el lead reenvía a
     * las 12:00 pisando todo. La comparación devolvía `admin` y la tarjeta mostraba "Modificado por
     * vos el 11:00" arriba de respuestas que el lead acababa de escribir a las 12:00 y que decían
     * lo contrario de lo que había puesto el admin.
     *
     * Con las columnas que hay hoy no existe forma de saber quién escribió último, así que no se
     * inventa un ganador: se declara que pasaron las dos cosas y la tarjeta muestra las dos fechas
     * sin afirmar cuál mandó. Lo que sí se puede afirmar SIEMPRE —y es lo que Lucas pidió que el
     * aviso diga— es si el lead completó el formulario o no; eso viaja aparte y entero en
     * `completado_por_lead`, que ningún caso de esta función puede perder.
     *
     * @param Carbon|null $completado_at
     * @param Carbon|null $editado_at
     *
     * @return string `defaults` | `lead` | `admin` | `ambos`
     */
    private static function origen(?Carbon $completado_at, ?Carbon $editado_at): string
    {
        if ($completado_at === null && $editado_at === null) {
            return 'defaults';
        }

        if ($editado_at === null) {
            return 'lead';
        }

        if ($completado_at === null) {
            return 'admin';
        }

        return 'ambos';
    }

    /**
     * Normaliza a Carbon un valor que el modelo castea a `datetime`.
     *
     * Existe por defensa y no por capricho: los tres atributos que lee `estado_para_panel()` están
     * casteados a `datetime` en el modelo, pero un Lead armado a mano en un test (o hidratado sin
     * casts, o traído por un `DB::table()`) puede traer el string crudo de MySQL — y sobre un
     * string, el `->format()` de `formatear()` es un error fatal, no un valor raro. De paso
     * normaliza la cadena vacía a `null`, que es lo que `origen()` necesita para no contar como
     * escrita una fecha que no está.
     *
     * @param mixed $valor
     *
     * @return Carbon|null
     */
    private static function a_carbon($valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return Carbon::parse($valor);
    }

    /**
     * Formatea una fecha para el panel, o null si no hay.
     *
     * Formato `Y-m-d H:i:s` y no ISO-8601: es el mismo que ya usa `DemoPlanResolver` para
     * `resuelto_at` dentro del plan congelado, y el que el resto del panel sabe leer.
     *
     * @param Carbon|null $fecha
     *
     * @return string|null
     */
    private static function formatear(?Carbon $fecha): ?string
    {
        return $fecha === null ? null : $fecha->format('Y-m-d H:i:s');
    }
}
