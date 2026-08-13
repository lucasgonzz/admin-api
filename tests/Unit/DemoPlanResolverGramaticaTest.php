<?php

namespace Tests\Unit;

use App\Services\DemoPlanResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * La gramática de `condicion` del catálogo, caso por caso (misión 48).
 *
 * Lo que estos tests protegen no es que las condiciones buenas se evalúen bien —eso ya lo cubren
 * los feature tests del plan— sino que las MAL ESCRITAS devuelvan `null`, que es lo que hace que
 * el clip se excluya Y quede declarado en `condiciones_invalidas`. Una condición mala que se
 * resuelve a `false` en silencio es el modo de falla caro: el clip desaparece del roadmap de un
 * lead y no queda registro en ningún lado.
 *
 * Sin base: `evaluar()` es puro (la condición y el array de respuestas).
 */
class DemoPlanResolverGramaticaTest extends TestCase
{
    /**
     * Las nueve respuestas efectivas, con los valores por defecto del catálogo.
     *
     * @return array<string, mixed>
     */
    private function respuestas(): array
    {
        return [
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
    }

    /**
     * `evaluar()` es protected: se alcanza por reflexión en vez de volverlo público para el test.
     *
     * @param mixed $condicion
     *
     * @return bool|null
     */
    private function evaluar($condicion)
    {
        $metodo = new ReflectionMethod(DemoPlanResolver::class, 'evaluar');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $condicion, $this->respuestas());
    }

    /**
     * Las tres formas de la gramática, en sus casos legítimos.
     */
    public function test_las_tres_formas_validas(): void
    {
        // `campo`: el booleano tiene que estar en true.
        $this->assertTrue($this->evaluar('registra_compras'));
        $this->assertFalse($this->evaluar('usa_presupuestos'));

        // `campo=valor`: igualdad estricta contra el string.
        $this->assertTrue($this->evaluar('tipo_precios=unico'));
        $this->assertFalse($this->evaluar('tipo_precios=listas'));

        // `a && b`: las dos.
        $this->assertFalse($this->evaluar('costos_en_dolares && tipo_precios=unico'));
        $this->assertTrue($this->evaluar('registra_compras && tipo_precios=unico'));

        // Tolerante a los espacios alrededor del operador.
        $this->assertTrue($this->evaluar('tipo_precios = unico'));
    }

    /**
     * Sin condición: siempre incluido. Vale para null, vacío y espacios.
     */
    public function test_sin_condicion_siempre_incluye(): void
    {
        $this->assertTrue($this->evaluar(null));
        $this->assertTrue($this->evaluar(''));
        $this->assertTrue($this->evaluar('   '));
    }

    /**
     * Operadores que la gramática no tiene: son un error del catálogo, no un caso a interpretar.
     */
    public function test_los_operadores_que_no_existen_son_invalidos(): void
    {
        $this->assertNull($this->evaluar('usa_ecommerce || registra_compras'));
        $this->assertNull($this->evaluar('!usa_ecommerce'));
        $this->assertNull($this->evaluar('(usa_ecommerce)'));
    }

    /**
     * Un campo que no está entre las nueve respuestas es un error del catálogo.
     */
    public function test_un_campo_inexistente_es_invalido(): void
    {
        $this->assertNull($this->evaluar('campo_que_no_existe'));
        $this->assertNull($this->evaluar('campo_que_no_existe=algo'));
        // Los nombres son sensibles a mayúsculas: `Registra_compras` no existe.
        $this->assertNull($this->evaluar('Registra_compras'));
        // Y un `&` suelto (typo de `&&`) no parte nada y cae como campo inexistente.
        $this->assertNull($this->evaluar('registra_compras & usa_ecommerce'));
        $this->assertNull($this->evaluar('registra_compras &'));
    }

    /**
     * 🔴 El agujero que este test cierra: el lado DERECHO del `=` también se valida.
     *
     * Sin esta validación, un typo del valor daba `false` en silencio — el clip desaparecía del
     * plan sin quedar declarado en `condiciones_invalidas` y sin una línea de log.
     */
    public function test_un_valor_que_no_esta_en_el_dominio_es_invalido(): void
    {
        // Typo del valor.
        $this->assertNull($this->evaluar('tipo_precios=lista'));
        // Mayúscula en el valor.
        $this->assertNull($this->evaluar('tipo_precios=Listas'));
        // Un `=` de más.
        $this->assertNull($this->evaluar('tipo_precios==unico'));
        // Lados vacíos.
        $this->assertNull($this->evaluar('=unico'));
        $this->assertNull($this->evaluar('tipo_precios='));
    }

    /**
     * 🔴 Y el peor caso de todos: comparar un booleano por igualdad.
     *
     * La gramática no tiene `!`, así que `campo=false` es lo primero que va a intentar quien
     * necesite negar un booleano en el catálogo. Antes daba `false` SIEMPRE —valiera lo que
     * valiera el campo, porque `(string) false` es la cadena vacía y `(string) true` es "1"— y
     * en silencio. Ahora es inválido y se declara.
     */
    public function test_comparar_un_booleano_por_igualdad_es_invalido(): void
    {
        $this->assertNull($this->evaluar('usa_ecommerce=true'));
        $this->assertNull($this->evaluar('usa_ecommerce=false'));
        $this->assertNull($this->evaluar('usa_presupuestos=false'));
        $this->assertNull($this->evaluar('usa_ecommerce=1'));

        // Y también adentro de un `&&`, que es donde más fácil se cuela.
        $this->assertNull($this->evaluar('tipo_precios=unico && usa_ecommerce=true'));
    }

    /**
     * Una condición que no es un string ni null es un error de tipo del catálogo. Sin este
     * chequeo, `false` se casteaba a la cadena vacía y se leía como "sin condición".
     */
    public function test_una_condicion_que_no_es_string_es_invalida(): void
    {
        $this->assertNull($this->evaluar(false));
        $this->assertNull($this->evaluar(true));
        $this->assertNull($this->evaluar(0));
        $this->assertNull($this->evaluar(['registra_compras']));
    }
}
