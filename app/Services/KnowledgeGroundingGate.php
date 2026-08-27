<?php

namespace App\Services;

/**
 * Decide si una respuesta de un agente puede salir hacia el cliente o el lead, o si hay que
 * escalarla a una persona.
 *
 * EL PROBLEMA QUE RESUELVE. Hasta el 27/8/2026 la regla "no improvises, contestá solo lo que
 * está escrito en el manual" existía únicamente como texto dentro del system prompt
 * (`manual_sistema/escalation_rules.md`, sección "Prohibido improvisar", y la REGLA FUNDAMENTAL
 * DE TOOLS del índice de recursos de leads). Un prompt es una petición, no una garantía: si el
 * modelo contestaba sin abrir ningún archivo, el mensaje salía igual. Quedó anotado como
 * hallazgo fuera de alcance #5 del informe `20260825-calidad-del-agente-de-soporte.md`:
 * "Nada verifica que el agente haya leído un archivo del manual antes de responder."
 *
 * CÓMO LO RESUELVE. El agente declara en su JSON qué documentos respaldan lo que afirma
 * (`fuentes_kb`) y de qué tipo es su respuesta (`tipo_respuesta`). Esta clase cruza esa
 * declaración contra el conjunto de documentos que el ejecutor de tools **efectivamente pudo
 * leer** en esa misma consulta. Si el agente afirma algo del sistema sin respaldo verificable,
 * el mensaje no sale: se escala.
 *
 * 🔴 La lista de leídos la arma el código que ejecuta las tools, NUNCA el modelo. Ese es el
 * punto entero: una declaración que se validara contra sí misma no verificaría nada.
 *
 * 🔴 El default ante lo desconocido es escalar, no dejar pasar. Un escalado de más le cuesta a
 * Lucas una revisión; un escalado de menos le cuesta al cliente una respuesta inventada.
 * `escalation_rules.md` lo dice igual: "Ante la duda entre contestar algo plausible y escalar,
 * escalá siempre".
 *
 * La clase es deliberadamente pura —sin red, sin base, sin contenedor— para poder ejercitar el
 * criterio completo en tests sin salir a ningún lado.
 */
class KnowledgeGroundingGate
{
    /** La respuesta afirma algo sobre qué hace o no hace ComercioCity. Exige respaldo. */
    const TIPO_AFIRMACION = 'afirmacion_del_sistema';

    /** Le pregunta al cliente un dato SUYO que falta. No exige respaldo. */
    const TIPO_ACLARACION = 'aclaracion';

    /** Saludo, cortesía, coordinación de agenda. No afirma nada del sistema. */
    const TIPO_CONVERSACIONAL = 'conversacional';

    /** El propio agente pidió escalar. Sigue por el camino de escalado que ya existía. */
    const TIPO_ESCALADO = 'escalado';

    /** Tipos que el gate reconoce. Cualquier otro valor —o ninguno— se trata como desconocido. */
    const TIPOS_VALIDOS = [
        self::TIPO_AFIRMACION,
        self::TIPO_ACLARACION,
        self::TIPO_CONVERSACIONAL,
        self::TIPO_ESCALADO,
    ];

    /**
     * Marcador que tiene que aparecer en el protocolo cargado para que el gate se active.
     *
     * Es el mecanismo de compatibilidad hacia atrás, y existe porque las dos mitades de este
     * cambio llegan a producción por caminos distintos y a destiempo: los .md del repo de
     * conocimiento sincronizan solos (soporte los lee en vivo, leads cada diez minutos) y el
     * código de `admin-api` sube recién cuando Lucas corre el deploy a mano.
     *
     * Con el código nuevo arriba y los .md viejos todavía vivos, el modelo no tiene forma de
     * saber que le pedimos `fuentes_kb`: sin este marcador el gate rechazaría absolutamente
     * todo. Al buscarlo en el texto del prompt, el gate queda inerte hasta que el protocolo que
     * lo explica está de verdad en el prompt, y se enciende solo cuando llega. En el orden
     * inverso (.md nuevos, código viejo) los campos extra del JSON se ignoran, como cualquier
     * campo desconocido.
     */
    const MARCADOR_CONTRATO = 'fuentes_kb';

    /**
     * Indica si el protocolo cargado ya trae el contrato de fuentes.
     *
     * @param string $system_prompt Texto completo del system prompt que se le mandó al modelo.
     *
     * @return bool True si el gate tiene que evaluar; false para dejar todo pasar.
     */
    public function esta_activo(string $system_prompt): bool
    {
        return strpos($system_prompt, self::MARCADOR_CONTRATO) !== false;
    }

    /**
     * Evalúa una respuesta del agente.
     *
     * @param bool                $activo       Resultado de esta_activo() sobre el prompt usado.
     * @param mixed               $tipo         Valor crudo de `tipo_respuesta` tal como vino en el JSON.
     * @param mixed               $fuentes      Valor crudo de `fuentes_kb` tal como vino en el JSON.
     * @param array<int, string>  $leidas       Documentos que el ejecutor de tools leyó con ÉXITO.
     *
     * @return array{permitido: bool, motivo: string} Motivo vacío cuando permitido es true.
     */
    public function evaluar($activo, $tipo, $fuentes, array $leidas): array
    {
        /* Protocolo viejo todavía vivo: el modelo no sabe que tiene que declarar nada. */
        if (! $activo) {
            return $this->permitir();
        }

        $tipo_limpio = is_string($tipo) ? trim($tipo) : '';

        /* Tipo ausente o inventado. Puede ser un modelo que ignoró el formato o un prompt a
         * medio sincronizar; en los dos casos no sabemos qué clase de respuesta estamos por
         * mandar, y mandar a ciegas es exactamente lo que este gate viene a impedir. */
        if (! in_array($tipo_limpio, self::TIPOS_VALIDOS, true)) {
            return $this->escalar(
                'El agente no declaró de qué tipo era su respuesta, así que no se puede verificar '
                . 'contra qué documento la respaldó.'
            );
        }

        /* El agente ya pidió escalar por su cuenta: no hay nada que verificar. */
        if ($tipo_limpio === self::TIPO_ESCALADO) {
            return $this->permitir();
        }

        /* Preguntar y saludar no afirman nada del sistema, así que no necesitan respaldo. Sin
         * esta puerta el gate escalaría cada "hola" y cada pedido de aclaración, y el escalado
         * se volvería ruido que se ignora — que es la forma más rápida de perder la señal. */
        if ($tipo_limpio === self::TIPO_ACLARACION || $tipo_limpio === self::TIPO_CONVERSACIONAL) {
            return $this->permitir();
        }

        /* Queda el único caso con consecuencias: el agente afirma algo sobre el sistema. */
        $declaradas = $this->normalizar_lista($fuentes);

        if (empty($declaradas)) {
            return $this->escalar(
                'El agente afirmó algo sobre el sistema sin citar ningún documento del repositorio '
                . 'que lo respalde.'
            );
        }

        $leidas_normalizadas = $this->normalizar_lista($leidas);

        /* Fuentes citadas que el sistema nunca le entregó en esta consulta. Puede ser un archivo
         * inventado, uno que existe pero que no leyó, o uno cuya lectura falló y de todos modos
         * citó: en los tres casos la afirmación no está respaldada por nada que hayamos visto. */
        $sin_respaldo = array_values(array_diff($declaradas, $leidas_normalizadas));

        if (! empty($sin_respaldo)) {
            return $this->escalar(
                'El agente citó documentos que no llegó a leer en esta consulta ('
                . implode(', ', $sin_respaldo)
                . '), así que la respuesta no está respaldada por el repositorio.'
            );
        }

        return $this->permitir();
    }

    /**
     * Veredicto de escalado cuando el repositorio de conocimiento no se pudo cargar.
     *
     * Se usa antes de mirar la respuesta: si el índice de archivos o el protocolo de escalado no
     * llegaron, el agente trabajó sin saber qué podía consultar y —peor— sin enterarse de que le
     * faltaba. Hasta ahora los dos fallos devolvían string vacío en silencio
     * (`fetch_escalation_rules()`, `fetch_manual_file_list()`), y el resultado era un agente sin
     * protocolo de escalado justo en el escenario donde más improvisa. Es el hallazgo fuera de
     * alcance #1 del informe del 25/8/2026.
     *
     * @param string $detalle Qué parte del repositorio no se pudo cargar.
     *
     * @return array{permitido: bool, motivo: string}
     */
    public function escalar_por_repositorio_caido(string $detalle): array
    {
        return $this->escalar(
            'No se pudo consultar el repositorio de conocimiento (' . $detalle . '), así que no hay '
            . 'con qué respaldar una respuesta.'
        );
    }

    /**
     * Normaliza una lista de nombres de documento para poder compararlos entre sí.
     *
     * Empareja las diferencias que no cambian a qué archivo se refiere el agente: mayúsculas,
     * espacios sobrantes y barras iniciales. No toca la extensión ni las carpetas: `precios.md`
     * y `listado/precios.md` son dos archivos distintos y tienen que seguir siéndolo.
     *
     * @param mixed $valor Array de strings, un string suelto, o cualquier otra cosa.
     *
     * @return array<int, string> Lista sin vacíos ni repetidos.
     */
    private function normalizar_lista($valor): array
    {
        /* Un string suelto donde se esperaba un array: el modelo citó una sola fuente y la mandó
         * sin envolver. Es una desviación del formato que no cambia lo que quiso decir. */
        if (is_string($valor)) {
            $valor = [$valor];
        }

        if (! is_array($valor)) {
            return [];
        }

        $normalizadas = [];

        foreach ($valor as $item) {
            if (! is_string($item)) {
                continue;
            }

            $limpio = ltrim(trim($item), '/');

            if ($limpio === '') {
                continue;
            }

            $normalizadas[] = mb_strtolower($limpio);
        }

        return array_values(array_unique($normalizadas));
    }

    /**
     * @return array{permitido: bool, motivo: string}
     */
    private function permitir(): array
    {
        return ['permitido' => true, 'motivo' => ''];
    }

    /**
     * @param string $motivo Texto que va a leer Lucas en el push y en el badge del ticket.
     *
     * @return array{permitido: bool, motivo: string}
     */
    private function escalar(string $motivo): array
    {
        return ['permitido' => false, 'motivo' => $motivo];
    }
}
