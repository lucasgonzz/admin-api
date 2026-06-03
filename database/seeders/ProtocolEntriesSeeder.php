<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProtocolEntriesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('protocol_entries')->truncate();

        $entries = [

            // ─────────────────────────────────────────
            // CATEGORÍA: etapa_principal
            // ─────────────────────────────────────────

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'nuevo',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 1 — Bienvenida y primera pregunta',
                'descripcion'      => 'El lead acaba de llegar por publicidad. Primer mensaje dentro de los 5 minutos.',
                'mensaje_template' => "¡Hola [Nombre]! 👋 Soy [nombre del setter], del equipo de ComercioCity.\n\nGracias por contactarte. Vi que llegaste desde nuestra publicidad.\n\nComercioCity es una plataforma de gestión para distribuidoras y comercios. No es un sistema más: incluye implementación completa, soporte humano, ecommerce integrado y acompañamiento real.\n\nPara contarte cómo podemos ayudarte, necesito entender un poco tu negocio. ¿Me podés comentar a qué se dedica tu empresa y cuántas personas trabajan con vos?",
                'notas_setter'     => 'Mandar dentro de los primeros 5 minutos. La velocidad de respuesta es parte del posicionamiento.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'contactado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 2A — Preguntas básicas de calificación',
                'descripcion'      => 'El lead respondió quién es. Hacer las tres preguntas básicas para confirmar que califica.',
                'mensaje_template' => "Perfecto, [rubro] es exactamente el tipo de empresa con la que trabajamos.\n\nContame un poco más:\n\n1. ¿Cómo manejan hoy el stock y las ventas? ¿Usan algún sistema, Excel, o es todo manual?\n2. ¿Facturan electrónicamente?\n3. ¿Tienen o les gustaría tener una tienda online?",
                'notas_setter'     => 'Con estas respuestas confirmás que el rubro califica. Después viene la pregunta de dolor.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'contactado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 2B — Lead pregunta el precio antes de la demo',
                'descripcion'      => 'El lead pregunta cuánto cuesta antes de ver el sistema. No dar el precio. Redirigir a la demo.',
                'mensaje_template' => "Tenemos soluciones desde USD 500. Antes de hablar de números te propongo que veas la plataforma funcionando, así podés evaluar si es lo que necesitás.\n\n¿Coordinamos una demo?",
                'notas_setter'     => 'Nunca dar el precio antes de la demo ni antes de la videollamada con Lucas.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'contactado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 2C — Lead ya tiene sistema y quiere cambiarse',
                'descripcion'      => 'El lead menciona que usa otro sistema pero está buscando alternativas.',
                'mensaje_template' => "¿Qué es lo que más te falla en el sistema que tenés hoy?\n\nLo que más nos diferencia es que no te dejamos solo con un usuario y contraseña: nosotros migramos todo, te capacitamos y te acompañamos.",
                'notas_setter'     => 'Escuchar el dolor antes de hablar de ComercioCity.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'contactado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 2D — Lead pregunta si es pago único o mensual',
                'descripcion'      => 'El lead pregunta la estructura de precios antes de la demo.',
                'mensaje_template' => "Es un pago único de implementación más una mensualidad por el uso de la plataforma, soporte e infraestructura.\n\nLos valores exactos los vemos en una reunión después de que pruebes el sistema.",
                'notas_setter'     => 'Nunca decir que no tiene costo mensual. Sí tiene mensualidad.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'calificado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 3 — Pregunta de dolor',
                'descripcion'      => 'El lead respondió las preguntas básicas y califica. Hacer la pregunta de dolor para que verbalice su problema antes de ver la demo.',
                'mensaje_template' => "Y contame, ¿qué fue lo que los llevó a buscar una solución ahora? ¿Qué problema concreto querían resolver?",
                'notas_setter'     => 'La respuesta a esta pregunta sirve para: (1) perfilar al lead para la videollamada con Lucas, (2) que Lucas sepa los puntos de dolor antes de reunirse, (3) que el lead llegue más comprometido a la demo. Guardar la respuesta en las notas del lead en el admin.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'calificado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 4A — Oferta de la demo',
                'descripcion'      => 'El lead expresó su dolor. Ofrecer la demo autogestionada.',
                'mensaje_template' => "Perfecto, con lo que me contás tiene sentido que lo veas funcionando.\n\nLo que hacemos es darte acceso a nuestra plataforma de demos para que la recorrés vos solo, con videos tutoriales que te guían paso a paso. Sin reuniones, sin compromisos — lo probás a tu ritmo.\n\n¿Cuándo tenés un ratito esta semana?",
                'notas_setter'     => 'No mencionar la videollamada todavía. Primero la demo.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'calificado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 4B — Confirmación del horario de demo',
                'descripcion'      => 'El lead eligió día y horario. Confirmar y enviar Mail 1.',
                'mensaje_template' => "Perfecto, te anoto para el [día] de [hora] a [hora] hs.\n\nEn los próximos minutos te llega un mail con el acceso y los videos. Te recomiendo ver el primero antes de entrar al sistema.\n\nCualquier duda, escribime por acá.",
                'notas_setter'     => 'Admin → lead → asignar demo y horario → presionar Enviar Mail 1. Registrar en notas lo que dijo en la pregunta de dolor.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'demo_agendada',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 5 — Recordatorio pre-demo (15 minutos antes)',
                'descripcion'      => 'Mandar 15 minutos antes del horario asignado para generar anticipación.',
                'mensaje_template' => "Hola [Nombre]! En unos minutos ya tenés disponible el acceso a la demo.\n\nUn consejo: empezá por el video introductorio del mail, son 3 minutos y te ayudan a entender qué mirar cuando entrés.\n\nCualquier duda mientras recorrés, escribime. 👋",
                'notas_setter'     => 'Si no encuentra el mail, reenviarle URL, usuario y contraseña por WhatsApp.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'demo_agendada',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 6 — Seguimiento a mitad de la demo (30 minutos después)',
                'descripcion'      => 'Mandar 30 minutos después de que el lead confirmó que entró al sistema. Mantiene contacto humano sin ser invasivo.',
                'mensaje_template' => "¿Cómo vas con la demo? ¿Pudiste entrar bien al sistema?",
                'notas_setter'     => 'Solo eso. Si responde con una duda, resolverla. Si responde bien, dejarlo seguir.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'demo_realizada',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 7A — Seguimiento post-demo (día siguiente)',
                'descripcion'      => 'El lead ya tenía acceso. Seguimiento al día siguiente para saber qué le pareció.',
                'mensaje_template' => "¿Pudiste recorrer la demo?\n\n¿Qué fue lo que más te llamó la atención?",
                'notas_setter'     => 'Pregunta abierta. No hablar de precio todavía. El objetivo es que exprese interés.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'demo_realizada',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 7B — Lead no pudo hacer la demo',
                'descripcion'      => 'El lead confirma que no pudo entrar. Ofrecer reagenda con urgencia.',
                'mensaje_template' => "Sin problema. Tené en cuenta que los accesos son por tiempo limitado porque la usamos con otros interesados en paralelo.\n\nTe puedo reagendar para [nueva fecha]. ¿Te viene bien?",
                'notas_setter'     => 'Amable pero firme. No ofrecer reagenda más de una vez.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'demo_realizada',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 8A — Oferta de videollamada con Lucas (post-demo)',
                'descripcion'      => 'El lead vio la demo y expresó interés. Ofrecer la videollamada con Lucas como reunión de implementación, no de ventas.',
                'mensaje_template' => "Para que puedas arrancar, el siguiente paso es una videollamada corta con Lucas, el fundador de ComercioCity.\n\nEn esa llamada:\n• Vemos cómo adaptamos el sistema a tu negocio específico\n• Definimos cómo hacemos la migración de tu información\n• Coordinamos cómo va a ser la capacitación de tu equipo\n• Y te armamos el presupuesto en base a lo que necesitás\n\nSon 20-30 minutos. ¿Cuándo tenés disponibilidad esta semana?",
                'notas_setter'     => 'No es una reunión de ventas — es una reunión de implementación. Eso baja la resistencia a agendar. Agendar en el calendario de Lucas e informarle: nombre, rubro y puntos de dolor del lead.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'demo_realizada',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 8B — Confirmación de la videollamada',
                'descripcion'      => 'El lead aceptó la videollamada y eligió horario.',
                'mensaje_template' => "Perfecto, te anoto para el [día] a las [hora] hs con Lucas.\n\nTe va a escribir él directamente cuando empiece. Cualquier cosa, estoy acá.",
                'notas_setter'     => 'Agendar en el calendario de Lucas e informarle el contexto del lead.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'demo_realizada',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 8C — Lead quiere el precio antes de la videollamada',
                'descripcion'      => 'El lead pregunta el precio antes de aceptar la videollamada.',
                'mensaje_template' => "El presupuesto lo armamos en la videollamada porque depende de tu operación — cantidad de usuarios, ecommerce, cómo es tu negocio.\n\nLo que sí te puedo decir es que las soluciones arrancan desde USD 500. En la videollamada Lucas te arma el número exacto.",
                'notas_setter'     => 'Nunca dar el precio exacto antes de la videollamada.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'mail2_enviado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 10A — Seguimiento post-videollamada (día siguiente)',
                'descripcion'      => 'El lead tuvo la videollamada con Lucas pero no confirmó. Seguimiento al día siguiente.',
                'mensaje_template' => "Hola [Nombre], ¿cómo quedaste después de la videollamada con Lucas?\n\n¿Alguna duda que te haya quedado?",
                'notas_setter'     => 'Tono liviano. No presionar todavía.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'mail2_enviado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 10B — Urgencia por el bono (día 3)',
                'descripcion'      => 'Tercer día sin respuesta post-videollamada. Urgencia por el bono de acción rápida.',
                'mensaje_template' => "[Nombre], te escribo porque el bono de acción rápida que te presentó Lucas vence pronto.\n\nSi querés arrancar o tenés alguna duda, avisame ahora.",
                'notas_setter'     => 'Mencionar el bono sin dar el precio exacto por escrito.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'mail2_enviado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 10C — Lead con inconveniente temporal',
                'descripcion'      => 'El lead tiene interés pero un problema coyuntural (sin presupuesto ahora, muy ocupado, etc.).',
                'mensaje_template' => "Entiendo perfectamente, no hay apuro.\n\nPara no perder el precio y los beneficios cuando estés listo, podés dejarnos USD 100 de seña ahora y arrancamos cuando vos digas.\n\nEl sistema queda reservado para vos.",
                'notas_setter'     => 'Si tampoco puede con los USD 100 ahora, pasarlo a en_pausa con seguimiento en 30 días.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'mail2_enviado',
                'followup_numero'  => null,
                'titulo'           => 'Etapa 10D — Lead que confirmó pero desapareció antes de pagar',
                'descripcion'      => 'El lead había confirmado verbalmente pero no realizó el pago y dejó de responder.',
                'mensaje_template' => "[Nombre], te dejo esto por si lo necesitás en algún momento 👇\n\nCuando estés listo para arrancar, escribime y lo activamos. El sistema sigue disponible para vos.",
                'notas_setter'     => 'Después de este mensaje pasarlo a en_pausa. En 30 días reactivar con un reel o contenido de valor.',
                'activa'           => true,
            ],

            // ─────────────────────────────────────────
            // CATEGORÍA: seguimiento
            // ─────────────────────────────────────────

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'nuevo',
                'followup_numero'  => 1,
                'titulo'           => 'Seguimiento Instancia 1 — No respondió bienvenida (día 2)',
                'descripcion'      => 'El lead llegó por publicidad pero no respondió la bienvenida.',
                'mensaje_template' => "Hola [Nombre]! Te escribo del equipo de ComercioCity.\n\nQuedamos en espera de que nos cuentes un poco sobre tu negocio. Cuando tengas un minuto, con gusto te contamos cómo podemos ayudarte.\n\n¿A qué se dedica tu empresa?",
                'notas_setter'     => 'Tono suave, sin presión. Solo 1 seguimiento más después de este.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'nuevo',
                'followup_numero'  => 2,
                'titulo'           => 'Seguimiento Instancia 1 — No respondió bienvenida (día 5, cierre)',
                'descripcion'      => 'Segundo y último seguimiento si no respondió la bienvenida.',
                'mensaje_template' => "Hola [Nombre], último mensaje de nuestra parte para no molestarte.\n\nSi en algún momento querés conocer la plataforma, acá estamos. Trabajamos con distribuidoras y ferreterías y los resultados hablan solos.\n\nCuando quieras, escribinos.",
                'notas_setter'     => 'Pasar a en_pausa después de este mensaje.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'contactado',
                'followup_numero'  => 1,
                'titulo'           => 'Seguimiento Instancia 2 — Respondió bienvenida, no las preguntas (día 1)',
                'descripcion'      => 'El lead respondió quién es pero no contestó las preguntas de calificación.',
                'mensaje_template' => "Hola [Nombre]! Vi que me contaste del negocio pero no te volví a ver por acá.\n\nCuando tengas un momento, contame: ¿cómo manejan hoy el stock y las ventas?",
                'notas_setter'     => 'Retomar con una sola pregunta directa.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'contactado',
                'followup_numero'  => 2,
                'titulo'           => 'Seguimiento Instancia 2 — Respondió bienvenida, no las preguntas (día 4)',
                'descripcion'      => 'Segundo seguimiento. Ir directo a proponer la demo.',
                'mensaje_template' => "Hola [Nombre], te mando esto porque creo que te puede servir.\n\nLa demo es gratuita y la probás vos solo, sin compromiso. ¿Te generamos el acceso?",
                'notas_setter'     => 'Si responde, ir directo a coordinar la demo.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'calificado',
                'followup_numero'  => 1,
                'titulo'           => 'Seguimiento Instancia 3 — No confirmó horario de demo (día 1)',
                'descripcion'      => 'El lead estaba en conversación pero no eligió fecha para la demo.',
                'mensaje_template' => "Hola [Nombre]! Te escribo porque los cupos para la demo esta semana se están completando.\n\n¿Te viene bien mañana o pasado?",
                'notas_setter'     => 'Urgencia real por cupo.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'calificado',
                'followup_numero'  => 2,
                'titulo'           => 'Seguimiento Instancia 3 — No confirmó horario de demo (día 3)',
                'descripcion'      => 'Segundo seguimiento. Escasez + fecha límite.',
                'mensaje_template' => "Hola [Nombre], te dejo el último cupo disponible esta semana.\n\nLos horarios se asignan por orden. Si no lo reservás hoy, el próximo turno es la semana que viene.\n\n¿Lo reservamos?",
                'notas_setter'     => 'Tono directo pero no agresivo.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'calificado',
                'followup_numero'  => 3,
                'titulo'           => 'Seguimiento Instancia 3 — No confirmó horario de demo (día 6, cierre)',
                'descripcion'      => 'Tercer y último seguimiento.',
                'mensaje_template' => "Hola [Nombre], último mensaje de mi parte.\n\nSi en algún momento querés acceder a la demo, acá estamos.\n\nQue te vaya bien con el negocio!",
                'notas_setter'     => 'Pasar a en_pausa.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'demo_agendada',
                'followup_numero'  => 1,
                'titulo'           => 'Seguimiento Instancia 4 — Confirmó demo pero no la hizo (día 1)',
                'descripcion'      => 'El lead confirmó horario pero no entró o no respondió después.',
                'mensaje_template' => "Hola [Nombre]! ¿Pudiste entrar a la demo?\n\nSi algo surgió y no pudiste, lo reagendamos. Tené en cuenta que el acceso vence pronto.",
                'notas_setter'     => 'Asumir que algo pasó, no que no le interesó.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'demo_agendada',
                'followup_numero'  => 2,
                'titulo'           => 'Seguimiento Instancia 4 — Confirmó demo pero no la hizo (día 3)',
                'descripcion'      => 'Segundo seguimiento. El acceso ya venció.',
                'mensaje_template' => "Hola [Nombre], el acceso a la demo que te asignamos ya venció.\n\nSi querés, te genero uno nuevo para esta semana. Y si ya no es el momento, también está bien.",
                'notas_setter'     => 'Dos caminos claros: reagendar o soltar.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'demo_agendada',
                'followup_numero'  => 3,
                'titulo'           => 'Seguimiento Instancia 4 — Confirmó demo pero no la hizo (día 7, cierre)',
                'descripcion'      => 'Tercer y último seguimiento.',
                'mensaje_template' => "Hola [Nombre], último intento.\n\nSi en algún momento querés retomar, escribinos y te generamos el acceso en el momento.\n\nSuerte con el negocio!",
                'notas_setter'     => 'Pasar a en_pausa.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'demo_realizada',
                'followup_numero'  => 1,
                'titulo'           => 'Seguimiento Instancia 5 — Hizo demo, no respondió (día 1)',
                'descripcion'      => 'El lead entró a la demo pero no volvió a escribir.',
                'mensaje_template' => "¿Pudiste recorrer la demo?\n\n¿Qué fue lo que más te llamó la atención?",
                'notas_setter'     => 'Pregunta abierta y liviana. No hablar de precio todavía.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'demo_realizada',
                'followup_numero'  => 2,
                'titulo'           => 'Seguimiento Instancia 5 — Hizo demo, no respondió (día 3)',
                'descripcion'      => 'Segundo seguimiento. Mostrar que hay más valor del que vio.',
                'mensaje_template' => "Che [Nombre], te mando esto porque quizás no llegaste a verlo todo.\n\nLo que mostramos en la demo es el 30% del sistema. El resto incluye multimoneda, múltiples listas de precio, análisis de datos con un especialista, integración simultánea con Mercado Libre y Tienda Nube.\n\n¿Querés que coordinemos una videollamada para que lo veas en detalle?",
                'notas_setter'     => 'Abrir la puerta a la videollamada con Lucas.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'demo_realizada',
                'followup_numero'  => 3,
                'titulo'           => 'Seguimiento Instancia 5 — Hizo demo, no respondió (día 6, cierre)',
                'descripcion'      => 'Tercer y último seguimiento.',
                'mensaje_template' => "[Nombre], último mensaje de mi parte.\n\nSi lo que viste te interesó y querés saber cómo adaptarlo a tu negocio, coordinamos una videollamada corta con Lucas.\n\nSi ya no es el momento, sin problema. Acá estamos cuando quieras.",
                'notas_setter'     => 'Pasar a en_pausa si no responde.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'mail2_enviado',
                'followup_numero'  => 1,
                'titulo'           => 'Seguimiento Instancia 6 — Post-videollamada, no respondió (día 1)',
                'descripcion'      => 'El lead tuvo la videollamada con Lucas pero no confirmó.',
                'mensaje_template' => "Hola [Nombre]! ¿Cómo quedaste después de la videollamada con Lucas?\n\nCualquier duda, contame.",
                'notas_setter'     => 'Sin presión todavía.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'mail2_enviado',
                'followup_numero'  => 2,
                'titulo'           => 'Seguimiento Instancia 6 — Post-videollamada, no respondió (día 2)',
                'descripcion'      => 'Urgencia por vencimiento del bono.',
                'mensaje_template' => "Hola [Nombre], te escribo porque el bono de acción rápida que te presentó Lucas vence mañana.\n\nSi querés arrancar o tenés alguna duda, avisame ahora.",
                'notas_setter'     => 'Urgencia real por vencimiento.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'seguimiento',
                'estado_aplicable' => 'mail2_enviado',
                'followup_numero'  => 3,
                'titulo'           => 'Seguimiento Instancia 6 — Post-videollamada, no respondió (día 4, cierre)',
                'descripcion'      => 'Tercer y último seguimiento. Ofrecer salida alternativa.',
                'mensaje_template' => "Hola [Nombre], entiendo que a veces no es el momento.\n\nSi tenés alguna duda que no quedó clara en la videollamada, podemos coordinar otro momento con Lucas sin compromiso.\n\n¿Te serviría eso?",
                'notas_setter'     => 'Pasar a en_pausa si no responde.',
                'activa'           => true,
            ],

            // ─────────────────────────────────────────
            // CATEGORÍA: situacion_frecuente
            // ─────────────────────────────────────────

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Rubro compatible — Confirmar y avanzar',
                'descripcion'      => 'El lead mencionó un rubro compatible. Confirmar y seguir con las preguntas básicas.',
                'mensaje_template' => "Perfecto, [rubro] es exactamente el tipo de negocio para el que está pensado ComercioCity.\n\nContame un poco más:\n\n1. ¿Cómo manejan hoy el stock y las ventas?\n2. ¿Facturan electrónicamente?\n3. ¿Tienen o les gustaría tener una tienda online?",
                'notas_setter'     => 'Rubros compatibles: distribuidoras, ferreterías, sanitarios, bulonerías, químicas, comercios mayoristas y minoristas, negocios que fabrican productos propios.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Rubro compatible con necesidad específica',
                'descripcion'      => 'El rubro entra pero el lead pide algo que no está en el sistema estándar. Derivar a videollamada con Lucas.',
                'mensaje_template' => "Entiendo lo que necesitás. Esa funcionalidad en particular no está en el sistema estándar, pero es algo que podemos desarrollar a medida.\n\nLo que hacemos en esos casos es una videollamada con Lucas para entender bien lo que necesitás y presupuestarte el desarrollo.\n\n¿Te interesa explorar eso?",
                'notas_setter'     => 'Marcar el lead como tipo a_medida. Agendar videollamada con Lucas.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Rubro no compatible — Descalificar con amabilidad',
                'descripcion'      => 'El rubro del lead no es compatible con ComercioCity.',
                'mensaje_template' => "Gracias por contarnos sobre tu negocio.\n\nSiendo honestos, ComercioCity está pensado para distribuidoras y comercios que venden productos propios. Para [rubro], necesitarías herramientas específicas que hoy no tenemos.\n\nNo queríamos hacerte perder el tiempo. Si en algún momento el negocio va hacia la venta de productos, acá estamos.\n\n¡Mucho éxito!",
                'notas_setter'     => 'Pasar el lead a cerrado_perdido.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Caso real: Mensajería / logística de envíos',
                'descripcion'      => 'El lead tiene una mensajería que gestiona envíos de terceros. No vende productos propios. No califica.',
                'mensaje_template' => "Hola, gracias por contarnos sobre tu negocio.\n\nSiendo honestos, ComercioCity está pensado para distribuidoras y comercios que venden productos propios.\n\nPara una mensajería que gestiona envíos y choferes de terceros, necesitarías herramientas específicas de logística que nosotros no tenemos.\n\n¡Mucho éxito!",
                'notas_setter'     => 'Pasar a cerrado_perdido.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Lead solo necesita lo básico',
                'descripcion'      => 'El lead aclara que no necesita funcionalidades avanzadas.',
                'mensaje_template' => "Perfecto, eso es exactamente lo que incluye el paquete base.\n\nEl sistema está pensado para ser intuitivo desde el primer día. Y nosotros nos encargamos de toda la implementación.\n\n¿Cuándo tenés un ratito para probarlo?",
                'notas_setter'     => 'Ir directo a la demo.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Lead pide ver ganancia y ventas a simple vista',
                'descripcion'      => 'El lead quiere un panel con métricas clave visibles.',
                'mensaje_template' => "Sí, eso está resuelto. Desde el panel principal ves las métricas del día sin buscar nada: ventas, ganancia, stock, cuentas corrientes.\n\nLo ves en la demo.",
                'notas_setter'     => 'Si pide paneles más avanzados, mencionar el científico de datos como valor adicional (no incluido en el precio base).',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Lead solo tiene tiempo el fin de semana',
                'descripcion'      => 'El lead no tiene disponibilidad en la semana.',
                'mensaje_template' => "El finde perfecto. ¿Sábado te viene bien y en qué horario de la mañana?\n\nTe asignamos el acceso y tenés la demo disponible a tu ritmo.",
                'notas_setter'     => 'El flujo es el mismo. Admin → asignar demo → enviar Mail 1.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Lead con poco tiempo o muy ocupado',
                'descripcion'      => 'El lead dice que no tiene tiempo.',
                'mensaje_template' => "Entiendo, por eso la demo es autogestionada: la probás cuando tenés un rato libre, sin reuniones ni llamadas.\n\n¿Te parece si te armamos el acceso para cuando puedas?",
                'notas_setter'     => 'Reencuadrar la demo como autogestionada.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Demo personalizada — Lead pide ver funcionalidad específica',
                'descripcion'      => 'El lead tiene una necesidad puntual y quiere saber si el sistema la resuelve antes de comprometerse.',
                'mensaje_template' => "Perfecto, eso está totalmente resuelto en ComercioCity. [Explicar cómo funciona para su caso puntual].\n\nLo que vamos a hacer es prepararte una demo donde, además de los módulos principales, vas a tener videos explicándote puntualmente cómo funciona esto para tu caso.\n\n¿Cuándo tenés disponibilidad?",
                'notas_setter'     => 'IMPORTANTE: Verificar con Lucas que la funcionalidad existe antes de confirmar. Coordinar el video extra para incluirlo en el Mail 1.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Caso real: Múltiples sucursales y sistema offline',
                'descripcion'      => 'El lead tiene stock en varias sucursales y un sistema offline.',
                'mensaje_template' => "Perfecto, eso está totalmente resuelto. Te cuento cómo funciona:\n\nCada sucursal es un depósito independiente. El stock se gestiona por separado. Cuando hacés una venta, se descuenta del depósito que corresponde. Podés ver ventas y reportes por sucursal. Y podés generar movimientos entre sucursales con su remito incluido.\n\nY como todo está en la nube, cada computadora ve el stock real en tiempo real, sin sistemas offline.\n\n¿Cuándo tenés disponibilidad para la demo?",
                'notas_setter'     => 'Lead ideal para la videollamada con Lucas — caso complejo.',
                'activa'           => true,
            ],

            [
                'categoria'        => 'situacion_frecuente',
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'titulo'           => 'Facturación ARCA — Lead pregunta qué se factura',
                'descripcion'      => 'El lead pregunta o muestra preocupación sobre qué pasa con ARCA.',
                'mensaje_template' => "El sistema registra todas tus operaciones para tu control interno. Lo que facturás ante ARCA es tu decisión: solo autorizás lo que querés, cuando querés.\n\nNo es automático. Podés registrar todo internamente y facturar solo lo que consideres necesario.",
                'notas_setter'     => 'Importante para pymes argentinas que trabajan parte de sus operaciones en negro.',
                'activa'           => true,
            ],

        ];

        foreach ($entries as $entry) {
            DB::table('protocol_entries')->insert(array_merge($entry, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command->info('ProtocolEntriesSeeder: ' . count($entries) . ' entradas cargadas.');
    }
}
