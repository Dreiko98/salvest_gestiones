# Funcionamiento end-to-end de Salvest Gestiones

Este documento explica el recorrido completo de una factura: desde que un proveedor
la envía por correo hasta que el PDF queda archivado en Google Drive, el mensaje se
retira de la bandeja de entrada y toda la operación queda registrada.

## 1. Qué hace el sistema

Salvest Gestiones automatiza estas tareas:

1. Revisa varios buzones de correo mediante IMAP.
2. Detecta los PDF adjuntos.
3. Evita procesar dos veces el mismo correo o el mismo documento.
4. Extrae los datos de la factura mediante la API de OpenAI.
5. Identifica la comunidad usando el maestro de comunidades.
6. Comprueba que el proveedor está asignado a esa comunidad.
7. Determina la categoría a partir de esa relación configurada.
8. Renombra y archiva el PDF en Google Drive.
9. Mueve el correo a una carpeta IMAP adecuada.
10. Registra el resultado completo en MySQL y lo muestra en el panel.

El sistema no modifica ni renombra archivos o carpetas que ya existan en Drive. Solo
crea los elementos que faltan y añade documentos nuevos.

## 2. Componentes que intervienen

```text
Proveedor
   │ envía un correo con PDF
   ▼
Buzón IMAP
   │
   │ ciclo automático
   ▼
Worker de Salvest
   ├── parser de correo y adjuntos
   ├── control de duplicados en MySQL
   ├── extracción con OpenAI
   ├── clasificación contra los maestros
   ├── copia privada local
   ├── archivo definitivo en Google Drive
   └── organización del correo en IMAP
```

La web sirve para mantener comunidades, proveedores y buzones, consultar el estado de
Drive y atender los casos que requieren revisión. No es necesario dejar la web abierta
para que el procesamiento automático funcione.

## 3. Configuración previa

### 3.1 Comunidades

Cada comunidad debe tener como mínimo:

- Código externo único.
- Nombre oficial.
- CIF y dirección cuando estén disponibles.
- Proveedores asignados por categoría.

El código es el identificador principal y coincide con el prefijo de la carpeta de
Drive. Por ejemplo:

```text
01 - LES ERES 3
109 - JAIME I 22
```

El maestro actual contiene 65 comunidades. Los códigos 75, 115 y 118 son válidos. Los
códigos 100 y 200 no se importan porque todavía no se dispone de sus datos.

### 3.2 Proveedores y categorías

Un proveedor se vincula a cada comunidad en una categoría concreta. La categoría no
se decide libremente a partir del texto del PDF: se obtiene de esta relación del
maestro.

Las carpetas canónicas son:

- `ELECTRICIDAD`
- `AGUA`
- `EXTINTORES`
- `ASCENSOR`
- `LIMPIEZA`
- `JARDINERIA`
- `PISCINA`
- `DESCALCIFICADOR`
- `MANTENIMIENTO`

`LUZ` se normaliza como `ELECTRICIDAD`, `FACSA` como `AGUA`, y `EXTINCAS` como
`EXTINTORES`. Los proveedores de la columna libre y las categorías desconocidas se
guardan como `MANTENIMIENTO`.

### 3.3 Buzones

Los buzones se añaden desde **Correos**. La contraseña se cifra antes de almacenarse.
Cada buzón se conecta de forma independiente: un error en una cuenta no impide revisar
las demás.

Para IONOS se utiliza:

```text
Servidor: imap.ionos.es
Puerto: 993
Seguridad: SSL/TLS
Usuario: dirección de correo completa
```

Para Gmail personal se utiliza:

```text
Servidor: imap.gmail.com
Puerto: 993
Seguridad: SSL/TLS
Usuario: dirección de Gmail completa
Contraseña: contraseña de aplicación de Google, no la contraseña normal
```

Las cuentas nuevas se guardan desactivadas por defecto. El botón **Probar conexión**
comprueba las credenciales aunque la cuenta esté desactivada; solo al marcar **Activar
procesamiento automático** podrá leerla el worker.

### 3.4 Google Drive

La autenticación actual es OAuth2 con un usuario de Google autorizado. El token se
renueva automáticamente y permanece fuera de Git. La carpeta raíz configurada es la
carpeta de pruebas `COMUNIDADES`; el Drive real de la empresa todavía no se utiliza.

El panel **Almacenamiento** debe mostrar que Google Drive está correctamente
configurado.

## 4. Cómo empieza un ciclo

El punto de entrada es `public/cron.php` en el hosting, o `bin/worker.php` por línea de
comandos. El despliegue actual utiliza un workflow de GitHub Actions cada cinco minutos.
Un cron nativo del servidor sería la opción adecuada si se necesita un intervalo más
estricto o más corto.

Al comenzar un ciclo:

1. Se aplican de forma segura las tablas nuevas que falten en MySQL.
2. Se comprueba si corresponde realizar el cierre anual de Drive.
3. Se obtiene un bloqueo MySQL para impedir que dos workers se solapen.
4. Se crea un registro de ejecución con sus contadores.
5. Se recorren, uno por uno, todos los buzones activos.

## 5. Lectura del correo

Para cada buzón, el sistema:

1. Abre una conexión IMAP cifrada.
2. Selecciona la carpeta de entrada configurada.
3. Obtiene los UID de los mensajes.
4. Consulta MySQL antes de descargar cada mensaje.
5. Interpreta correctamente el contenido MIME y multipart.
6. Selecciona únicamente documentos admitidos, especialmente adjuntos PDF.
7. Conserva como metadato el nombre original, remitente, asunto y fecha del mensaje.
8. Cierra la conexión aunque ocurra una excepción.

No se depende de `UNSEEN`: aunque una persona haya abierto el correo, el mensaje se
procesará si su identificador todavía no consta como terminado.

Un correo sin documentos admitidos se registra como ignorado y permanece en `INBOX`,
porque puede ser correspondencia normal que deba ver una persona.

## 6. Control de duplicados

Existen dos controles distintos.

### 6.1 Mensaje ya procesado

La identidad de un mensaje se forma con:

```text
buzón + UIDVALIDITY + UID IMAP
```

Esto evita confundir UID iguales procedentes de cuentas distintas y protege frente a
la regeneración de UID que puede ocurrir en un servidor IMAP.

### 6.2 PDF ya procesado

Cada adjunto recibe un hash SHA-256 calculado sobre sus bytes. Si el mismo PDF vuelve a
llegar reenviado, en otro mensaje o con otro nombre, se registra como duplicado y no se
extrae ni se sube otra vez.

## 7. Extracción con OpenAI

El adjunto se guarda primero en una zona privada temporal. Después se envía a la API de
OpenAI junto con contexto útil del correo: remitente, asunto, nombre del adjunto y
cuerpo del mensaje.

La respuesta se exige mediante un esquema JSON estricto. Entre otros datos se extraen:

- Código, nombre, CIF y dirección de la comunidad cuando aparecen.
- Proveedor y CIF del proveedor.
- Fecha y número de factura.
- Importe y moneda.
- Tipo de servicio.
- Identificadores auxiliares como CUPS, contrato o referencia de cliente.

OpenAI propone datos; no decide por sí solo el ID interno de la comunidad. La decisión
definitiva se hace localmente contra MySQL.

## 8. Clasificación de la comunidad

Las señales se comprueban aproximadamente en este orden:

1. Código de comunidad exacto.
2. CUPS, contrato o referencia de cliente exactos.
3. CIF de la comunidad exacto.
4. Dirección, nombre y alias mediante coincidencia aproximada.

Las señales exactas producen confianza completa. La coincidencia aproximada debe
superar el umbral configurado, actualmente 92 %. Si no lo supera, no se fuerza una
comunidad.

Por eso un documento que mencione los códigos excluidos 100 o 200, o una comunidad
desconocida, queda sin clasificar.

## 9. Resolución del proveedor

Una vez identificada la comunidad, el sistema solo busca entre los proveedores que
están asignados a ella. Compara el proveedor extraído y el remitente con los nombres
normalizados y exige una coincidencia mínima del 92 %.

Hay tres resultados posibles:

| Comunidad | Proveedor asignado | Resultado |
|---|---|---|
| Sí | Sí | Clasificada automáticamente |
| Sí | No | Pendiente de revisión |
| No | No aplicable | Sin clasificar |

Este paso impide que un proveedor con un nombre parecido se asigne a una comunidad con
la que no tiene relación configurada.

## 10. Archivado del PDF

Primero se conserva una copia privada local para trazabilidad. Solo las facturas
completamente clasificadas se suben a Google Drive.

### 10.1 Facturas del año actual

Durante 2026, la ruta es:

```text
COMUNIDADES/
└── 118 - ILLES COLUMBRETES 5/
    └── Doc año en Vigor/
        └── ELECTRICIDAD/
            └── 2026-08-17_ENDESA_CASO-118.pdf
```

No existe una carpeta intermedia `2026` mientras 2026 sea el año en vigor.

### 10.2 Facturas atrasadas

Si en 2026 llega una factura válida de 2025, se guarda directamente en el histórico:

```text
Doc año en Vigor/2025/ELECTRICIDAD/2025-12-20_IBERDROLA_F-123.pdf
```

Una factura fechada en un año futuro se rechaza para evitar archivarla en una ubicación
incorrecta.

### 10.3 Nombre final

La convención es:

```text
AAAA-MM-DD_PROVEEDOR.pdf
AAAA-MM-DD_PROVEEDOR_NUMFACTURA.pdf
```

Los caracteres se normalizan para que el nombre sea seguro. Si ya existe, se crea:

```text
AAAA-MM-DD_PROVEEDOR_NUMFACTURA (2).pdf
```

Después `(3)`, `(4)`, etc. Nunca se sobrescribe un archivo.

## 11. Qué ocurre con el correo después

Una vez tratados todos sus adjuntos:

- Si todos están clasificados y pertenecen a una sola comunidad, el mensaje se marca
  como leído y se mueve a `Facturas/{comunidad}`.
- Si todos quedan sin comunidad, se mueve a `Facturas/Sin clasificar`.
- Si hay una comunidad posible pero falta resolver el proveedor, o hay resultados
  mezclados, se mueve a `Facturas/Pendientes de revisión`.
- Si se produce un error técnico, se intenta mover a `Facturas/Errores`.

Las carpetas IMAP se crean si faltan. Si Drive se ha archivado correctamente pero el
servidor no permite mover el correo, la factura no se considera perdida: el archivo y
el resultado permanecen registrados, y se guarda la incidencia IMAP.

## 12. Correos con varios adjuntos

Cada PDF se procesa de forma independiente. Después se toma una decisión para el
mensaje completo:

- Varios PDF de la misma comunidad y todos clasificados: correo a la carpeta de esa
  comunidad.
- PDF de comunidades distintas: correo a pendientes de revisión.
- Mezcla de clasificados y dudosos: correo a pendientes de revisión.
- Error en un adjunto: correo a errores, sin perder el original del buzón.

## 13. Registro y trazabilidad

MySQL conserva:

- Buzón, UIDVALIDITY y UID.
- Remitente, asunto, fecha y nombre original.
- Hash SHA-256 y tamaño.
- Datos extraídos por OpenAI.
- Comunidad, proveedor, servicio y confianza.
- Nombre y ruta final local.
- ID y ruta del archivo de Drive.
- Estado, destino IMAP y posibles errores.
- Resumen de cada ejecución y consumo de tokens.

Los estados intermedios del adjunto incluyen `downloaded` y `processing`. Los finales
incluyen `classified`, `unclassified`, `needs_review`, `duplicate` y `error`.

Las contraseñas IMAP, claves de API y tokens OAuth nunca deben escribirse en logs ni
subirse a Git.

## 14. Cierre automático de año

MySQL mantiene el año activo en `drive_year_state`. En el primer ciclo de 2027, antes
de leer los buzones, el sistema:

1. Detecta que el año activo era 2026.
2. Recorre las 65 comunidades existentes.
3. Busca los PDF de las categorías directas.
4. Crea `Doc año en Vigor/2026/<CATEGORÍA>/` solo cuando haga falta.
5. Mueve allí los PDF de 2026.
6. Deja las carpetas directas disponibles para las facturas nuevas de 2027.
7. Registra el resultado en `drive_year_rollovers`.
8. Actualiza el año activo a 2027 solamente después de completar el cierre.

El proceso es idempotente. Si se interrumpe después de mover algunos documentos, el
siguiente ciclo continúa con los restantes. Las colisiones de nombre reciben un sufijo
y no se sobrescribe ningún documento.

## 15. Modo de prueba

Por línea de comandos puede comprobarse la lectura sin modificar buzones ni archivar:

```bash
php bin/worker.php --dry-run --max-emails 10
php bin/worker.php --dry-run --mailbox facturas@empresa.com
```

El modo `dry-run` conecta y cuenta los adjuntos que procesaría. No marca mensajes como
leídos, no los mueve, no llama a la extracción y no archiva documentos. Tampoco ejecuta
el cierre anual.

## 16. Comportamiento ante fallos

- **Un buzón no autentica:** se registra el error y continúa el siguiente buzón.
- **OpenAI falla:** el adjunto queda en error y el correo se deriva a errores.
- **Drive falla:** no se afirma que la factura esté archivada; se registra el error.
- **Movimiento IMAP falla tras archivar:** se conserva como incidencia, sin duplicar el
  PDF en Drive.
- **Correo normal sin PDF:** se ignora y permanece visible en INBOX.
- **Comunidad o proveedor dudoso:** no se fuerza una asignación y se solicita revisión.
- **Ciclo solapado:** el bloqueo MySQL impide que arranque un segundo worker.

## 17. Qué ve y qué hace la secretaria

En el funcionamiento cotidiano, la secretaria solo necesita:

1. Mantener comunidades y sus datos identificativos.
2. Mantener proveedores y relaciones con comunidades.
3. Añadir o actualizar las cuentas de correo.
4. Consultar **Revisar** cuando el panel indique una incidencia.

No necesita iniciar ciclos manualmente, organizar la bandeja de entrada ni crear
carpetas de Drive. Si todo está correcto, el panel muestra que no hay nada que requiera
atención.

Comunidades, proveedores y correos ofrecen dos niveles de retirada. **Archivar** o
**Desactivar** es reversible y debe ser la opción habitual. **Eliminar** pide una
confirmación y borra permanentemente el registro de MySQL. Al eliminar un correo se
elimina también su historial de mensajes y adjuntos; al eliminar una comunidad o un
proveedor se eliminan sus relaciones configuradas. Esta operación nunca borra PDF o
carpetas de Google Drive ni mensajes del servidor IMAP.

## 18. Lista de comprobación antes de usar el entorno real

### Datos

- [ ] Confirmar las 65 comunidades y sus códigos.
- [ ] Confirmar direcciones, CIF, alias e identificadores disponibles.
- [ ] Confirmar todos los proveedores y sus categorías por comunidad.
- [ ] Mantener excluidos los códigos 100 y 200 hasta recibir sus datos.

### Correo

- [ ] Configurar los buzones reales desde la interfaz.
- [ ] Probar la conexión de cada cuenta.
- [ ] Confirmar que las carpetas `Facturas/...` pueden crearse y recibir mensajes.
- [ ] Asegurar que no hay otro worker antiguo leyendo esos mismos buzones.

### Drive

- [ ] Cambiar el ID de la carpeta de pruebas por la carpeta real autorizada.
- [ ] Verificar permisos de lectura, creación y subida.
- [ ] Confirmar que los nombres `<código> - <comunidad>` coinciden.
- [ ] Hacer primero una prueba con una sola factura sintética.
- [ ] Confirmar visualmente la ruta y el nombre antes de activar el resto.

### Seguridad y operación

- [ ] Realizar una copia de seguridad de MySQL y de los archivos privados.
- [ ] Conservar fuera de Git `config.php`, el cliente OAuth y el refresh token.
- [ ] Confirmar HTTPS, permisos privados y bloqueo HTTP de `config/`, `database/`,
  `src/` y `storage/`.
- [ ] Confirmar el cron y revisar su primera ejecución.
- [ ] Definir quién atenderá pendientes y errores.

## 19. Prueba final recomendada

Antes de conectar el Drive real, enviar al buzón de pruebas un solo correo con:

1. Una factura sintética de una comunidad conocida y proveedor configurado.
2. Una copia idéntica del PDF con otro nombre, para verificar duplicados.
3. Una factura de proveedor desconocido, para verificar revisión.

Hay que comprobar conjuntamente:

- El PDF clasificado aparece una sola vez en la ruta correcta de Drive.
- El nombre sigue la convención acordada.
- El duplicado no genera una segunda subida.
- El caso desconocido no sube nada a Drive.
- Cada correo termina en su carpeta IMAP correspondiente.
- El panel y MySQL reflejan los resultados sin secretos en los logs.

Solo después de esta validación debe sustituirse la carpeta de pruebas por la carpeta
real de la empresa.
