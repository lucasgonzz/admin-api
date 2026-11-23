<?php

namespace Tests\Feature\TableroDeJuan;

use Tests\Feature\FlujoLead\ArmadoDelFlujoDeEntrada;
use Tests\Feature\Prueba\BaseDePruebaGratuita;

/**
 * Base de los tests de la etapa E1 de la misión embudo-juan-pipeline que viven en admin-api.
 *
 * Extiende `BaseDePruebaGratuita` porque la entrada automática al tablero se prueba también con el alta REAL de la
 * prueba (`POST landing/prueba/alta`): trae el VPS, Hostinger y el ERP falsificados, el WhatsApp espiado y las
 * perillas de la prueba fijas. Ningún test sale a la red.
 *
 * Suma:
 *  - `ArmadoDelFlujoDeEntrada` (el armado del flujo de entrada de Martín: IA simulada, leads y mensajes de prueba).
 *  - `ArmadoDelTableroDeJuan` (pipelines, tarjetas, admins, tareas y llamadas).
 *  - El reloj clavado en el lunes 23/11/2026 a las 12:00 de Argentina.
 *  - A Juan creado (perfil «Sólo Leads») y el interruptor del flujo de entrada apagado, como el resto de los tests
 *    de E2 de la misión anterior: la tarea y la tarjeta del embudo no dependen de él.
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDelTableroDeJuan extends BaseDePruebaGratuita
{
    use ArmadoDelFlujoDeEntrada;
    use ArmadoDelTableroDeJuan;

    /** @var \App\Models\Admin Juan, el admin del perfil «Sólo Leads» que recibe las tareas del embudo. */
    protected $juan;

    /**
     * Deja el entorno: reloj, HTTP simulado (con la IA), protocolo de leads, interruptor del flujo apagado y a Juan.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clavar_el_reloj(self::$AHORA_DEL_TABLERO);

        /* El Http de la base ya atiende Meta y los ERP: acá sólo se suma la IA simulada (patrón de E2). */
        $this->fakear_http_del_flujo(true);
        $this->sembrar_protocolo_de_leads();
        \App\Models\AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');

        $this->juan = $this->crear_a_juan();
    }
}
