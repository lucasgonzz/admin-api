<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Helpers\WhatsappNormalizer;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappAdReferral;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * De dónde vino un lead (misión origen-del-lead-y-conversiones-meta, 13/10/2026).
 *
 * El origen se lee del PRIMER mensaje entrante del lead y de nada más, con cuatro valores:
 *
 *   - `anuncio_whatsapp`: hay un referral Click-to-WhatsApp de Meta para ese primer mensaje. Gana
 *     sobre cualquier frase, porque es lo único que trae `ctwa_clid`, y el `ctwa_clid` es lo único
 *     con lo que Meta atribuye una conversión.
 *   - `landing`: el primer mensaje contiene la frase del botón de la web (la nueva de M6a o la vieja,
 *     que manda la landing publicada hasta que se publique la nueva).
 *   - `instagram`: contiene la frase del botón de «Comentá DEMO». No hay canal de Instagram en el
 *     admin: la frase es la única señal.
 *   - `desconocido`: nada de lo anterior.
 *
 * 🔴 La regla del anuncio tiene dos pasos y los dos miran el PRIMER mensaje: (a) el `wamid` del
 * referral es el `whatsapp_message_id` del mensaje (Kapso manda como `message.id` el wamid de Meta);
 * (b) mismo teléfono normalizado y `received_at` a ±30 s del `sent_at`. Los dos webhooks (el de Kapso,
 * que crea el lead y el mensaje, y el crudo de Meta, que guarda el referral) llegan en cualquier
 * orden, y por eso hay dos ganchos: {@see al_crear_mensaje()} busca el referral al nacer el lead y
 * {@see al_crear_referral()} busca el lead al guardarse el referral.
 *
 * 🔴 Ningún método de acá recalcula el origen de un lead que ya lo tiene, salvo para pasarlo a
 * `anuncio_whatsapp` cuando el referral llega tarde. Y como todo mira el primer mensaje, un lead
 * viejo que hoy escribe la frase de la web (o toca un anuncio) no cambia de origen: ese mensaje no
 * es el primero.
 */
class LeadOrigenService
{
    /** El lead llegó tocando un anuncio Click-to-WhatsApp de Meta. */
    const ORIGEN_ANUNCIO_WHATSAPP = 'anuncio_whatsapp';

    /** El lead llegó por el botón de la web de ComercioCity. */
    const ORIGEN_LANDING = 'landing';

    /** El lead llegó por el botón de Instagram («Comentá DEMO»). */
    const ORIGEN_INSTAGRAM = 'instagram';

    /** Ninguna señal en el primer mensaje. */
    const ORIGEN_DESCONOCIDO = 'desconocido';

    /** Los cuatro valores válidos de `leads.origen`. */
    const ORIGENES = [
        self::ORIGEN_ANUNCIO_WHATSAPP,
        self::ORIGEN_LANDING,
        self::ORIGEN_INSTAGRAM,
        self::ORIGEN_DESCONOCIDO,
    ];

    /** Frase del botón de la web nueva (M6a, commit 21d9e19 de comerciocity-web). */
    const FRASE_LANDING = 'Hola Martín, vi la web de ComercioCity y quiero hacer la demo';

    /**
     * Frase que manda la landing publicada el 7/10/2026, hasta que se publique la de M6a. Es la
     * misma que el texto por defecto del botón de la página de experiencia, pero ese botón solo lo
     * ve un lead que ya existe, así que nunca es un primer mensaje.
     */
    const FRASE_LANDING_VIEJA = 'Hola Martín, quiero hacer la demo de ComercioCity';

    /** Frase del botón de «Comentá DEMO» de Instagram (informe del 5/10/2026, §11.2). */
    const FRASE_INSTAGRAM = 'Hola Martín, vengo de Instagram y quiero hacer la demo de ComercioCity';

    /**
     * Frase → origen, en el orden en que se evalúan. Instagram va primero por las dudas: ninguna
     * frase contiene a otra hoy, pero si mañana una se acorta, la más larga tiene que ganar.
     */
    const FRASES_POR_ORIGEN = [
        [self::FRASE_INSTAGRAM, self::ORIGEN_INSTAGRAM],
        [self::FRASE_LANDING, self::ORIGEN_LANDING],
        [self::FRASE_LANDING_VIEJA, self::ORIGEN_LANDING],
    ];

    /** Tolerancia entre el `received_at` del referral y el `sent_at` del primer mensaje. */
    const SEGUNDOS_DE_TOLERANCIA_DEL_REFERRAL = 30;

    /**
     * Normaliza un texto para comparar frases: sin tildes, minúsculas, sin signos y con los espacios
     * colapsados. «¡Hola Martín!, quiero...» y «hola martin quiero» quedan iguales.
     *
     * @param string $texto
     *
     * @return string
     */
    public static function normalizar(string $texto): string
    {
        /* Str::ascii primero: translitera Martín → Martin y saca lo que no tiene equivalente. */
        $texto = mb_strtolower(Str::ascii($texto), 'UTF-8');
        $texto = (string) preg_replace('/[^a-z0-9]+/', ' ', $texto);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    /**
     * ¿El texto CONTIENE la frase, comparando normalizado y por palabras enteras?
     *
     * Contener y no ser igual: el lead puede escribir «Buenas!» delante o agregar algo atrás, y el
     * botón le deja el texto editable antes de mandarlo.
     *
     * @param string $texto
     * @param string $frase
     *
     * @return bool
     */
    public static function contiene_frase(string $texto, string $frase): bool
    {
        $frase_normalizada = self::normalizar($frase);
        if ($frase_normalizada === '') {
            return false;
        }

        return strpos(' ' . self::normalizar($texto) . ' ', ' ' . $frase_normalizada . ' ') !== false;
    }

    /**
     * Origen que se lee del TEXTO de un mensaje: `landing`, `instagram` o `desconocido`. El anuncio
     * no sale de acá (no tiene frase): lo decide el referral.
     *
     * @param string $contenido
     *
     * @return string
     */
    public static function origen_por_frase(string $contenido): string
    {
        foreach (self::FRASES_POR_ORIGEN as $par) {
            if (self::contiene_frase($contenido, $par[0])) {
                return $par[1];
            }
        }

        return self::ORIGEN_DESCONOCIDO;
    }

    /**
     * Las frases que el agente reconoce como un «sí» a la demo: el texto del botón de la página de
     * experiencia más las frases de la web y de Instagram, editables desde la configuración de
     * demos (`demo_cta_whatsapp_textos`).
     *
     * @return array<int, string>
     */
    public static function frases_de_cta(): array
    {
        return LeadDemoSettings::get_cta_whatsapp_textos();
    }

    /**
     * Las frases del «sí» entre comillas y separadas por coma, para nombrarlas en el prompt.
     *
     * @return string Ej.: `"A", "B" o "C"`.
     */
    public static function frases_de_cta_para_el_prompt(): string
    {
        $entre_comillas = array_map(function ($frase) {
            return '"' . $frase . '"';
        }, self::frases_de_cta());

        if (count($entre_comillas) <= 1) {
            return implode('', $entre_comillas);
        }

        $ultima = array_pop($entre_comillas);

        return implode(', ', $entre_comillas) . ' o ' . $ultima;
    }

    /**
     * Renglón extra del bloque DEMO DIRECTA cuando el ÚLTIMO mensaje del lead es una de las frases
     * del «sí»: lo dice explícito, para no depender de que el modelo empareje el ejemplo.
     *
     * @param Lead $lead
     *
     * @return string El renglón con su salto de línea, o '' si no corresponde.
     */
    public static function linea_del_si_explicito(Lead $lead): string
    {
        $ultimo = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'lead')
            ->where('is_status_event', false)
            ->orderByDesc('id')
            ->first();
        if ($ultimo === null) {
            return '';
        }

        foreach (self::frases_de_cta() as $frase) {
            if (self::contiene_frase((string) $ultimo->content, $frase)) {
                return "  - OJO: el ULTIMO mensaje del lead es una de esas frases (\"" . $frase . "\"): es un SI a la demo.\n";
            }
        }

        return '';
    }

    /**
     * Primer mensaje entrante del lead (del propio lead, sin eventos internos), por orden de alta.
     *
     * @param int $lead_id
     *
     * @return LeadMessage|null
     */
    public static function primer_mensaje_entrante(int $lead_id): ?LeadMessage
    {
        return LeadMessage::query()
            ->where('lead_id', $lead_id)
            ->where('sender', 'lead')
            ->where('is_status_event', false)
            ->orderBy('id')
            ->first();
    }

    /**
     * Origen del lead según su primer mensaje entrante, sin escribir nada.
     *
     * Es LA regla: la usan el observer del mensaje y el comando de los leads viejos.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>|null Claves `origen`, `ctwa_clid` (o null), `primer_mensaje_id` y
     *                                   `referral_id` (o null). Null si el lead no tiene ningún
     *                                   mensaje entrante: todavía no hay de dónde leerlo.
     */
    public function calcular_para_lead(Lead $lead): ?array
    {
        $primero = self::primer_mensaje_entrante((int) $lead->id);
        if ($primero === null) {
            return null;
        }

        $referral = $this->referral_del_primer_mensaje($lead, $primero);
        if ($referral !== null) {
            $clid = trim((string) $referral->ctwa_clid);

            return [
                'origen'            => self::ORIGEN_ANUNCIO_WHATSAPP,
                'ctwa_clid'         => $clid !== '' ? $clid : null,
                'primer_mensaje_id' => (int) $primero->id,
                'referral_id'       => (int) $referral->id,
            ];
        }

        return [
            'origen'            => self::origen_por_frase((string) $primero->content),
            'ctwa_clid'         => null,
            'primer_mensaje_id' => (int) $primero->id,
            'referral_id'       => null,
        ];
    }

    /**
     * Gancho de `LeadMessage::created`: si este es el primer mensaje entrante de un lead sin origen,
     * se calcula y se escribe.
     *
     * @param LeadMessage $mensaje Recién creado.
     *
     * @return string|null El origen escrito, o null si no correspondía calcular nada.
     */
    public function al_crear_mensaje(LeadMessage $mensaje): ?string
    {
        if ((string) $mensaje->sender !== 'lead' || (bool) $mensaje->is_status_event || ! $mensaje->lead_id) {
            return null;
        }

        /* 🔴 Si ya hay un entrante anterior, este no es el primero: un lead viejo que escribe hoy no
           se recalcula, ni siquiera si nunca tuvo origen (eso es trabajo del comando). */
        $hay_anterior = LeadMessage::query()
            ->where('lead_id', (int) $mensaje->lead_id)
            ->where('sender', 'lead')
            ->where('is_status_event', false)
            ->where('id', '<', (int) $mensaje->id)
            ->exists();
        if ($hay_anterior) {
            return null;
        }

        $lead = Lead::query()->find((int) $mensaje->lead_id);
        if ($lead === null || $lead->origen !== null) {
            return null;
        }

        $resultado = $this->calcular_para_lead($lead);
        if ($resultado === null) {
            return null;
        }

        $cambios = [
            'origen'              => $resultado['origen'],
            'origen_calculado_at' => AppTime::now(),
        ];
        if ($resultado['ctwa_clid'] !== null) {
            $cambios['ctwa_clid'] = $resultado['ctwa_clid'];
        }

        /* Condicional sobre `origen` NULL: si dos procesos llegan a la vez, escribe uno solo. */
        $escritas = Lead::query()->whereKey((int) $lead->id)->whereNull('origen')->update($cambios);
        if ($escritas === 0) {
            return null;
        }

        if ($resultado['ctwa_clid'] !== null) {
            $this->despues_de_guardar_el_clid((int) $lead->id);
        }

        return $resultado['origen'];
    }

    /**
     * Gancho de `WhatsappAdReferral::created`: si el lead del teléfono ya existe y este referral es
     * el de su primer mensaje, pasa a `anuncio_whatsapp` (aunque ya tuviera otro origen: el anuncio
     * gana) y se le copia el `ctwa_clid`.
     *
     * @param WhatsappAdReferral $referral Recién guardado.
     *
     * @return int|null Id del lead actualizado, o null si no había a quién atribuirlo todavía.
     */
    public function al_crear_referral(WhatsappAdReferral $referral): ?int
    {
        foreach ($this->leads_del_telefono((string) $referral->phone) as $lead) {
            $primero = self::primer_mensaje_entrante((int) $lead->id);
            if ($primero === null || ! $this->referral_coincide($referral, $primero, $lead)) {
                continue;
            }

            return $this->marcar_anuncio($lead, $referral) ? (int) $lead->id : null;
        }

        return null;
    }

    /**
     * Referral que corresponde al primer mensaje del lead: primero por wamid exacto, después por
     * teléfono y ±30 s (el más cercano en el tiempo si hay más de uno).
     *
     * @param Lead        $lead
     * @param LeadMessage $primero
     *
     * @return WhatsappAdReferral|null
     */
    public function referral_del_primer_mensaje(Lead $lead, LeadMessage $primero): ?WhatsappAdReferral
    {
        $wamid = trim((string) $primero->whatsapp_message_id);
        if ($wamid !== '') {
            $exacto = WhatsappAdReferral::query()->where('wamid', $wamid)->first();
            if ($exacto !== null) {
                return $exacto;
            }
        }

        $telefono = WhatsappNormalizer::normalize((string) $lead->phone);
        $momento  = $this->momento_del_mensaje($primero);
        if ($telefono === '' || $momento === null) {
            return null;
        }

        $tolerancia = self::SEGUNDOS_DE_TOLERANCIA_DEL_REFERRAL;
        $candidatos = WhatsappAdReferral::query()
            ->where('phone', $telefono)
            ->whereNotNull('received_at')
            ->whereBetween('received_at', [
                $momento->copy()->subSeconds($tolerancia),
                $momento->copy()->addSeconds($tolerancia),
            ])
            ->get();

        return $candidatos->sortBy(function (WhatsappAdReferral $candidato) use ($momento) {
            return abs($candidato->received_at->getTimestamp() - $momento->getTimestamp());
        })->first();
    }

    /**
     * ¿Este referral es el del primer mensaje del lead? Misma regla que
     * {@see referral_del_primer_mensaje()}, vista desde el referral.
     *
     * @param WhatsappAdReferral $referral
     * @param LeadMessage        $primero
     * @param Lead               $lead
     *
     * @return bool
     */
    private function referral_coincide(WhatsappAdReferral $referral, LeadMessage $primero, Lead $lead): bool
    {
        $wamid = trim((string) $primero->whatsapp_message_id);
        if ($wamid !== '' && $wamid === trim((string) $referral->wamid)) {
            return true;
        }

        $momento = $this->momento_del_mensaje($primero);
        if ($momento === null || $referral->received_at === null) {
            return false;
        }

        if (! WhatsappNormalizer::phones_match((string) $lead->phone, (string) $referral->phone)) {
            return false;
        }

        $diferencia = abs($referral->received_at->getTimestamp() - $momento->getTimestamp());

        return $diferencia <= self::SEGUNDOS_DE_TOLERANCIA_DEL_REFERRAL;
    }

    /**
     * Escribe `anuncio_whatsapp` y el `ctwa_clid` del referral. No pisa un lead que ya es de anuncio
     * y ya tiene su clid.
     *
     * @param Lead               $lead
     * @param WhatsappAdReferral $referral
     *
     * @return bool true si escribió algo.
     */
    private function marcar_anuncio(Lead $lead, WhatsappAdReferral $referral): bool
    {
        $clid    = trim((string) $referral->ctwa_clid);
        $cambios = [
            'origen'              => self::ORIGEN_ANUNCIO_WHATSAPP,
            'origen_calculado_at' => AppTime::now(),
        ];
        if ($clid !== '') {
            $cambios['ctwa_clid'] = $clid;
        }

        $escritas = Lead::query()
            ->whereKey((int) $lead->id)
            ->where(function ($query) use ($clid) {
                $query->whereNull('origen')->orWhere('origen', '!=', self::ORIGEN_ANUNCIO_WHATSAPP);
                if ($clid !== '') {
                    $query->orWhereNull('ctwa_clid')->orWhere('ctwa_clid', '');
                }
            })
            ->update($cambios);

        if ($escritas > 0 && $clid !== '') {
            $this->despues_de_guardar_el_clid((int) $lead->id);
        }

        return $escritas > 0;
    }

    /**
     * Leads del teléfono, el más nuevo primero: exacto por el teléfono normalizado y, si no hay,
     * por sufijo con `phones_match()` (el mismo criterio que usa el webhook para encontrar al lead).
     *
     * @param string $telefono
     *
     * @return Collection<int, Lead>
     */
    private function leads_del_telefono(string $telefono): Collection
    {
        $normalizado = WhatsappNormalizer::normalize($telefono);
        if ($normalizado === '') {
            return collect();
        }

        $exactos = Lead::query()->where('phone', $normalizado)->orderByDesc('id')->get();
        if ($exactos->isNotEmpty()) {
            return $exactos;
        }

        $digitos = (string) preg_replace('/\D+/', '', $normalizado);
        $sufijo  = substr($digitos, -10);
        if (strlen($sufijo) < 8) {
            return collect();
        }

        return Lead::query()
            ->where('phone', 'like', '%' . $sufijo)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->filter(function (Lead $lead) use ($normalizado) {
                return WhatsappNormalizer::phones_match((string) $lead->phone, $normalizado);
            })
            ->values();
    }

    /**
     * Momento del mensaje según WhatsApp (`sent_at`), o el de alta si no lo trae.
     *
     * @param LeadMessage $mensaje
     *
     * @return Carbon|null
     */
    private function momento_del_mensaje(LeadMessage $mensaje): ?Carbon
    {
        $momento = $mensaje->sent_at !== null ? $mensaje->sent_at : $mensaje->created_at;

        return $momento !== null ? Carbon::instance($momento) : null;
    }

    /**
     * Lo que pasa después de guardarle a un lead su `ctwa_clid`: registrar las conversiones que ya
     * alcanzó. El `ctwa_clid` se escribe con un UPDATE condicional (para que dos webhooks no se
     * pisen) y ese UPDATE no dispara `Lead::saved`, así que el registro se pide acá. Casi siempre no
     * hay nada que registrar (el lead recién nace, está en `nuevo`); si el referral llegó tarde y el
     * lead ya avanzó, sale lo que corresponde. Si esto falla, lo levanta el conciliador.
     *
     * @param int $lead_id
     *
     * @return void
     */
    protected function despues_de_guardar_el_clid(int $lead_id): void
    {
        try {
            $lead = Lead::query()->find($lead_id);
            if ($lead !== null) {
                app(MetaConversionesService::class)->registrar_alcanzados($lead);
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('Origen del lead: no se pudieron registrar las conversiones a Meta.', [
                'lead_id' => $lead_id,
                'error'   => $exception->getMessage(),
            ]);
        }
    }
}
