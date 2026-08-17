# Salvest Gestiones — PHP/MySQL

Aplicación web para recibir facturas desde varios buzones IMAP, extraer sus datos con
OpenAI, clasificarlas por comunidad y archivarlas. Esta edición está diseñada para un
hosting compartido PHP 8.2+ con MySQL 8 y tareas programadas; no requiere Docker ni un
proceso residente.

## Arquitectura

```text
cron de IONOS (cada 5 minutos)
        │
        ▼
bin/worker.php o public/cron.php
        ├── IMAP SSL de cada buzón
        ├── OpenAI Responses API
        ├── MySQL: configuración, auditoría e idempotencia
        ├── storage/invoices/comunidades/{comunidad}/{año}/{mes}/
        └── carpetas IMAP Facturas/{comunidad|revisión|errores}

navegador ──► public/index.php ──► panel administrativo
```

La aplicación usa `UIDVALIDITY + UID + buzón` para identificar mensajes y SHA-256 para
no procesar dos veces el mismo documento, aunque llegue reenviado o con otro nombre.
En cada comunidad se pueden guardar direcciones alternativas y referencias como CUPS
o número de contrato; en cada proveedor se pueden añadir nombres o dominios alternativos.
Esto permite reconocer formatos distintos sin reducir el umbral general de confianza.

## Requisitos

- PHP 8.2 o posterior.
- Extensiones `curl`, `json`, `mbstring`, `openssl`, `pdo_mysql` y `sodium`.
- MySQL 8 con `utf8mb4`.
- HTTPS.
- Tarea cron por CLI o URL HTTPS.
- Permiso de escritura en `storage/incoming` y `storage/invoices`.

El cliente IMAP está implementado con sockets TLS de PHP y no requiere `ext-imap`.

## Configuración local

```bash
cp config/config.example.php config/config.php
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Completa `config/config.php`. Este archivo contiene secretos y está excluido de Git.
Nunca cambies `app.encryption_key` después de guardar buzones: se usa para cifrar sus
contraseñas.

Inicialización por CLI:

```bash
php bin/migrate.php
php bin/create-admin.php admin 'una-contraseña-larga'
php bin/diagnose.php
```

Servidor local:

```bash
php -S 127.0.0.1:8080 -t public
```

## Instalación en hosting sin SSH

1. Sube todo el contenido por SFTP, incluido un `config/config.php` real.
2. Asegura permisos de escritura para `storage/incoming` y `storage/invoices`.
3. Visita una sola vez:

   ```text
   https://salvest.germanmallo.com/install.php?token=TOKEN_CRON
   ```

4. Crea el administrador. El instalador crea `storage/install.lock` y no vuelve a
   ejecutarse.
5. Entra en la web y configura comunidades, proveedores y correos.
6. Programa el worker.

Si el subdominio permite definir el *document root*, oriéntalo a `public/`. En planes
IONOS donde `mod_rewrite` no esté disponible, copia también `public/index.php`,
`public/install.php`, `public/cron.php` y `public/assets/` a la raíz. La navegación por
`?route=` funciona sin reglas Apache.

## Cron

Opción preferida, si IONOS permite ejecutar PHP CLI:

```cron
*/5 * * * * /usr/bin/php /ruta/absoluta/bin/worker.php >> /ruta/privada/worker.log 2>&1
```

Comprobaciones puntuales por CLI:

```bash
php bin/worker.php --dry-run --max-emails 10
php bin/worker.php --mailbox facturas@empresa.com
```

Opción URL:

```text
https://salvest.germanmallo.com/cron.php?token=TOKEN_CRON
```

El repositorio incluye también `.github/workflows/process-invoices.yml`, que llama a
esa URL cada cinco minutos. Requiere crear el secreto de GitHub `CRON_TOKEN` con el
mismo valor de `app.cron_token`. `workflow_dispatch` permite lanzar un ciclo manual.
Las ejecuciones programadas de GitHub pueden sufrir algunos minutos de retraso; para
intervalos garantizados debe usarse el cron nativo del hosting.

El bloqueo MySQL `GET_LOCK` evita ejecuciones solapadas. Un buzón que falle no detiene
los demás. La contraseña y el token nunca deben aparecer en repositorios o capturas.

El panel limita a cinco los accesos fallidos por usuario e IP durante quince minutos.
Las claves IMAP se guardan cifradas y las contraseñas de usuarios solo como hashes
generados por `password_hash`.

## Flujo IMAP

- Correo sin documentos admitidos: se registra como ignorado y permanece en INBOX.
- Todos los documentos de una comunidad: se marca como leído y se mueve a
  `Facturas/{comunidad}`.
- Varias comunidades o clasificación dudosa: los archivos se conservan y el correo va
  a `Facturas/Pendientes de revisión`.
- Todos sin comunidad: `Facturas/Sin clasificar`.
- Error de procesamiento: `Facturas/Errores`.

Las carpetas con espacios y acentos usan modified UTF-7 para compatibilidad con IONOS.

## OpenAI

Los PDF se envían como `input_file` y las imágenes como `input_image` mediante Responses
API. La salida se limita con JSON Schema estricto y no se aceptan IDs de comunidad
propuestos por el modelo. La clasificación contra los maestros se hace localmente.

Documentación oficial:

- [File inputs](https://developers.openai.com/api/docs/guides/file-inputs)
- [Structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs)

## Pruebas

```bash
composer test
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Las pruebas no contactan IONOS, OpenAI ni la base remota.

## Copias de seguridad

Respaldar conjuntamente:

- La base MySQL.
- `storage/invoices/`.
- `config/config.php`, especialmente `app.encryption_key`.

No publicar ninguno de estos elementos en GitHub.
