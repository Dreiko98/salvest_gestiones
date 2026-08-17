# Despliegue y operación

## Entorno activo

- Aplicación: `https://salvest.germanmallo.com/`
- Rama desplegada: `main`
- Plataforma: hosting compartido IONOS, PHP 8.2+ y MySQL 8.
- Ejecución automática: workflow `Procesar facturas` cada cinco minutos y bajo demanda.
- Document root actual: despliegue plano; los puntos de entrada de `public/` también se
  copian a la raíz del subdominio.

Los secretos del entorno activo solo existen en `config/config.php` del hosting y en
el secreto `CRON_TOKEN` de GitHub. Ese archivo no se versiona.

## Publicar una actualización

1. Ejecutar localmente:

   ```bash
   composer test
   find . -name '*.php' -print0 | xargs -0 -n1 php -l
   composer validate --strict --no-check-publish
   ```

2. Publicar el commit en `main`.
3. Subir por SFTP los archivos modificados conservando su ruta. Si cambia un archivo
   de `public/`, actualizar también su copia plana correspondiente en la raíz.
4. Lanzar manualmente `Procesar facturas` desde GitHub Actions.
5. Comprobar:

   ```text
   https://salvest.germanmallo.com/?route=health
   ```

   Debe devolver `{"status":"ok"}`. `config/config.php`, `database/`, `src/` y
   `storage/` deben seguir siendo inaccesibles desde HTTP.

El cron y el worker CLI aplican `database/schema.sql` antes de procesar. El esquema es
idempotente; no hay que desbloquear ni repetir `install.php` para añadir tablas nuevas.

## Datos persistentes y copia de seguridad

Respaldar como una unidad coherente:

- Base de datos MySQL.
- `storage/invoices/`.
- `config/config.php`, especialmente `app.encryption_key`.
- `config/google_drive.php`, `config/google_oauth_client.json` y
  `config/google_oauth_token.json`.

No basta con guardar solo los PDF: MySQL contiene la idempotencia de mensajes y hashes,
las relaciones con comunidades, los buzones cifrados y el historial de decisiones.

El CSV real de comunidades contiene datos del cliente y no se publica. Para reimportar,
usar `bin/import-communities.php` desde un entorno privado y comprobar que devuelve
exactamente 65 comunidades. La operación sustituye maestros en una transacción, pero
conserva mensajes, adjuntos y auditoría histórica.

## Comprobación funcional

1. Añadir o comprobar una comunidad, un proveedor y un correo desde la interfaz.
2. Usar “Probar” en la pantalla Correos.
3. Enviar una factura sintética al buzón.
4. Ejecutar el workflow manual o esperar el siguiente ciclo.
5. Verificar el PDF en `storage/invoices/comunidades/...` y el correo en la carpeta
   IMAP `Facturas/{comunidad}`.
6. Reenviar exactamente el mismo PDF con otro nombre. El contador debe registrar un
   duplicado y no debe crearse un segundo archivo.

## Recuperación

- Si Anthropic o un buzón falla, los demás buzones continúan y la incidencia queda en
  MySQL; no se deben borrar filas para “reintentar”.
- Si se pierde `app.encryption_key`, las contraseñas IMAP existentes no se pueden
  descifrar y deben volver a introducirse desde la interfaz.
- Si el cron devuelve `404`, revisar que el secreto de GitHub y `app.cron_token`
  coincidan, sin imprimirlos en logs.
