# COMANDOS.md — Admin API
*Referencia rápida de comandos Artisan y seeders disponibles para testing y desarrollo*
*Antes de preguntarle a Claude si existe algo, revisá este archivo.*

---

## COMANDOS ARTISAN

### 🧪 Testing de Leads

---

#### `leads:seed-demo-testing`
**Propósito:** Crea un lead calificado con conversación completa, dejándolo justo antes de agendar la demo. El próximo mensaje a generar es el horario de la demo.

**Cuándo usarlo:** Querés testear el flujo desde el punto en que Claude propone la demo sin arrancar desde cero por WhatsApp.

```bash
# Uso mínimo
php artisan leads:seed-demo-testing --phone=5493415000000

# Con datos personalizados
php artisan leads:seed-demo-testing \
  --phone=5493415000000 \
  --nombre=Lucas \
  --empresa="Ferretería El Tornillo" \
  --rubro=ferretería

# Limpiar leads [DEMO-TEST] anteriores y crear uno nuevo
php artisan leads:seed-demo-testing --phone=5493415000000 --limpiar
```

**Qué crea:**
- Lead con estado `calificado`
- 7 mensajes simulando el protocolo completo: bienvenida automática → calificación (stock/factura/ecommerce) → pregunta de dolor → respuesta del lead → oferta de demo
- Último mensaje en estado `sugerido` con `suggested_lead_status: demo_agendada`

**Cómo testear:** Escribís desde WhatsApp con el número especificado en `--phone` y el sistema procesa la respuesta como si fuera el lead real.

---

#### `leads:test-followup`
**Propósito:** Simula un seguimiento automático de lead sin esperar los tiempos reales definidos en las reglas.

**Cuándo usarlo:** Querés probar qué hace el sistema cuando un lead no responde, sin esperar 24hs o 48hs.

```bash
# Followup 1 para un lead en estado contactado
php artisan leads:test-followup --estado=contactado

# Followup 2 para un lead que ya realizó la demo
php artisan leads:test-followup --estado=demo_realizada --followup=2

# Limpiar leads [TEST] anteriores y crear uno nuevo
php artisan leads:test-followup --estado=nuevo --limpiar
```

**Opciones:**
- `--estado` *(requerido)*: `nuevo` | `contactado` | `calificado` | `demo_agendada` | `demo_realizada` | `mail2_enviado`
- `--followup`: `1`, `2` o `3` (default: `1`)
- `--limpiar`: elimina todos los leads `[TEST]` antes de crear uno nuevo

---

#### `leads:check-followups`
**Propósito:** Procesa todos los seguimientos automáticos pendientes de leads (reglas + Claude). Es el comando que corre el scheduler en producción.

**Cuándo usarlo:** Después de `leads:test-followup`, para disparar el procesamiento real del lead de prueba.

```bash
php artisan leads:check-followups
```

---

#### `lead:diagnose-audio`
**Propósito:** Lista mensajes de audio recientes y verifica que las columnas `kind` y la tabla `lead_message_attachments` estén correctamente migradas.

**Cuándo usarlo:** Si hay problemas con mensajes de audio de leads (no se graban, no se procesan).

```bash
# Últimos 10 mensajes (default)
php artisan lead:diagnose-audio

# Últimos N mensajes
php artisan lead:diagnose-audio --limit=20
```

---

#### `leads:assign-doc-numbers`
**Propósito:** Asigna número de documento de 12 dígitos a leads existentes que no tienen `doc_number`.

```bash
# Ver qué cambiaría sin persistir
php artisan leads:assign-doc-numbers --dry-run

# Ejecutar
php artisan leads:assign-doc-numbers
```

---

### 🎮 Testing de Demo

---

#### `leads:check-demo-ingress`
**Propósito:** Genera una pregunta automática de check-in si el lead no confirmó que pudo entrar a la demo. Corre cada minuto en el scheduler.

**Cuándo usarlo:** Testear que el sistema detecta que un lead no confirmó acceso a la demo y genera el mensaje de seguimiento.

```bash
php artisan leads:check-demo-ingress
```

---

#### `leads:send-demo-reminders`
**Propósito:** Genera recordatorios pre-demo como sugerencias pendientes para el setter.

```bash
php artisan leads:send-demo-reminders
```

---

#### `leads:run-demo-setup`
**Propósito:** Corre automáticamente el setup de la demo para leads cuya demo arranca pronto.

```bash
php artisan leads:run-demo-setup
```

---

#### `leads:generate-demo-summary`
**Propósito:** Genera con Claude un resumen del lead X minutos antes de que finalice la demo (para que el closer tenga contexto antes de la llamada).

```bash
php artisan leads:generate-demo-summary
```

---

### 🔧 Testing de Implementación

---

#### `implementacion:reset`
**Propósito:** Elimina todas las implementaciones existentes y crea una nueva en etapa 3, lista para avanzar. Usa el cliente con phone `+5493444622139` y el primer admin disponible.

**⚠️ Solo para desarrollo local. No ejecutar en producción.**

```bash
php artisan implementacion:reset
```

---

### 📨 Soporte

---

#### `support:check-response-alerts`
**Propósito:** Emite alertas cuando tickets de soporte superan el umbral de tiempo sin respuesta del operador.

```bash
php artisan support:check-response-alerts
```

---

#### `support:retry-pending-syncs`
**Propósito:** Reintenta sincronizar mensajes de soporte que quedaron pendientes de envío hacia `empresa-api`.

```bash
php artisan support:retry-pending-syncs
```

---

## SEEDERS

Los seeders se corren con `php artisan db:seed --class=NombreDelSeeder`.

---

#### `LeadFollowupTestSeeder`
**Propósito:** Siembra 4 leads de prueba para testear el servicio de followup con distintos escenarios.

**Casos que crea:**
- `followup-test-ai`: estado `contactado`, sin followups previos, última actividad >24h → dispara sugerencia IA
- `followup-test-pause`: estado `nuevo`, ya tiene 1 followup, última actividad >48h → pasa a `en_pausa` sin llamar a la API
- `followup-test-too-soon`: estado `calificado`, mensaje reciente → el servicio no hace nada (control negativo)
- `followup-test-skip-pending`: estado `demo_realizada` con `tiene_sugerencia_pendiente` → el servicio lo omite

**Uso típico:**
```bash
php artisan db:seed --class=LeadFollowupTestSeeder
php artisan leads:check-followups
```

---

#### `LeadSeeder`
**Propósito:** Crea un lead base mínimo (Lucas / lucasgonzalez5500@gmail.com) para tener un registro inicial en entornos de desarrollo.

```bash
php artisan db:seed --class=LeadSeeder
```

---

#### `LeadPipelineStatusSeeder`
**Propósito:** Siembra los estados del pipeline de leads (columnas del kanban).

```bash
php artisan db:seed --class=LeadPipelineStatusSeeder
```

---

#### `FollowupRulesSeeder` / `FollowupTemplatesSeeder`
**Propósito:** Siembra las reglas y plantillas de seguimiento automático de leads.

```bash
php artisan db:seed --class=FollowupRulesSeeder
php artisan db:seed --class=FollowupTemplatesSeeder
```

---

#### `AiSystemPromptSeeder` / `UpdateAiSystemPromptSeeder`
**Propósito:** Siembra o actualiza el system prompt de los agentes IA (setter, soporte, implementación).

```bash
php artisan db:seed --class=AiSystemPromptSeeder
php artisan db:seed --class=UpdateAiSystemPromptSeeder
```

---

#### `AgentIdentitySeeder`
**Propósito:** Siembra la identidad del agente (Martín) en la base de datos.

```bash
php artisan db:seed --class=AgentIdentitySeeder
```

---

#### `WhatsappConfigSeeder`
**Propósito:** Siembra la configuración base de WhatsApp (token, phone_number_id, webhook).

```bash
php artisan db:seed --class=WhatsappConfigSeeder
```

---

#### `ImplementationStageConfigSeeder`
**Propósito:** Siembra la configuración de las etapas del flujo de implementación (7 etapas).

```bash
php artisan db:seed --class=ImplementationStageConfigSeeder
```

---

#### `EcommerceImplementationStageConfigSeeder` / `EcommerceImplementationStageConfigStandaloneSeeder`
**Propósito:** Siembra la configuración de las etapas del flujo de implementación de ecommerce. La versión `Standalone` es para bases productivas existentes.

```bash
php artisan db:seed --class=EcommerceImplementationStageConfigSeeder
# o en producción existente:
php artisan db:seed --class=EcommerceImplementationStageConfigStandaloneSeeder
```

---

#### `DemoSeeder`
**Propósito:** Crea el registro de demo base apuntando a los entornos locales (`empresa.local:8080`, `tienda.local:8081`).

```bash
php artisan db:seed --class=DemoSeeder
```

---

#### `AdminUserSeeder`
**Propósito:** Crea el usuario admin inicial del sistema.

```bash
php artisan db:seed --class=AdminUserSeeder
```

---

*Última actualización: Junio 2026*
