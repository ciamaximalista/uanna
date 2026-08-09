# Uanna

Uanna es un servidor de micro-blogging para comunidades pequenas y cerradas dentro del Fediverso. Esta pensado para grupos que prefieren una herramienta sencilla, auditable y facil de mantener antes que una plataforma grande con muchas dependencias.

Uanna funciona con archivos JSON y XML. No usa SQLite, MySQL, MariaDB, Redis ni ningun otro motor de base de datos.

## Caracteristicas

- Publicacion de notas locales con visibilidad publica, seguidores o privada.
- Timeline personal para usuarios autenticados.
- Portada publica con presentacion editable de la instancia.
- Perfiles publicos en rutas tipo `/@usuario`.
- Favoritos e impulsos, con avatares enlazados a los perfiles.
- Arboles de respuestas basados en `inReplyTo`, sin confundir enlaces con respuestas.
- Menciones `@usuario@servidor` con enlaces y avisos.
- Adjuntos de imagen con texto alternativo.
- Edicion y borrado de publicaciones propias con envio de actividades ActivityPub.
- Panel de usuario con perfil, notificaciones, seguidores, seguidos, busqueda y mensajes privados.
- Panel de administracion para configurar imagenes de instancia, usuarios y bloqueos.
- Moderacion para altas, seguimientos, bloqueos de actores y servidores.
- Importacion y herramientas de diagnostico para migraciones desde snac.
- Almacenamiento atomico en archivos para reducir corrupcion ante cortes o fallos.

## Licencia

Uanna se distribuye bajo la Licencia Publica de la Union Europea, version 1.2, EUPL-1.2. El texto completo esta en `LICENSE.txt`.

## Requisitos

- PHP 8.1 o superior.
- Servidor web con soporte PHP, por ejemplo Apache con PHP-FPM, Nginx con PHP-FPM o el servidor integrado de PHP para pruebas.
- Extensiones PHP: `json`, `openssl`, `mbstring`, `fileinfo`, `gd` y `dom`.
- Un dominio con HTTPS valido para federar correctamente.
- Permisos de escritura para PHP en `oannes/data` y `oannes/public/assets`.

## Instalacion

Clona el repositorio:

```sh
git clone https://github.com/ciamaximalista/uanna.git
cd uanna
```

Crea la configuracion local:

```sh
cp oannes/config/oannes.example.php oannes/config/oannes.php
```

Edita `oannes/config/oannes.php` y cambia al menos:

```php
'host' => 'tu-dominio.org',
'base_url' => 'https://tu-dominio.org',
'timezone' => 'Europe/Madrid',
```

Crea los directorios de datos y assets, y da permisos de escritura al usuario del servidor web:

```sh
mkdir -p oannes/data oannes/public/assets
chmod -R u+rwX,o-rwx oannes/data oannes/public/assets
```

Configura el servidor web para que el document root apunte a:

```text
oannes/public
```

Todas las rutas deben terminar en `oannes/public/index.php` salvo los archivos estaticos existentes. En Apache puedes usar una regla equivalente a:

```apache
DocumentRoot /ruta/a/uanna/oannes/public

<Directory /ruta/a/uanna/oannes/public>
    AllowOverride All
    Require all granted
    FallbackResource /index.php
</Directory>
```

En Nginx, una configuracion minima con PHP-FPM seria:

```nginx
server {
    server_name tu-dominio.org;
    root /ruta/a/uanna/oannes/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Abre la web en el navegador. Si no existe ningun usuario local, Uanna mostrara la pagina de registro del primer administrador. Ese usuario podra crear y editar usuarios, configurar la instancia y administrar bloqueos.

## Actualizacion de colas

La recepcion y los envios ActivityPub se configuran desde el panel de administracion, en la caja `Actualizaciones`.

Hay dos modos:

- `Actualizacion al detectarse actividad (lenta)`: Uanna procesa recepcion y envios de forma oportunista durante las visitas web normales. Cada peticion puede ejecutar un lote pequeno de inbox y entregas, con bloqueo de concurrencia y enfriamiento para no saturar la carga. No requiere cron, pero puede tardar mas si la instancia tiene poca actividad web.
- `Usar cron`: Uanna no procesa colas durante las visitas. El administrador debe programar los comandos de cola en el crontab del servidor. Es el modo recomendado para instancias con mas trafico federado o para que todo avance aunque nadie visite la web durante horas.

Los limites se ajustan en `oannes/config/oannes.php`:

```php
'opportunistic_workers_enabled' => true,
'opportunistic_workers_cooldown_seconds' => 15,
'opportunistic_inbox_limit' => 5,
'opportunistic_delivery_limit' => 2,
```

Si eliges `Usar cron`, el panel calcula la ruta de la instalacion y muestra los comandos exactos para esa instancia. Los comandos base son:

```sh
php oannes/bin/oannes.php queue-run 25
php oannes/bin/oannes.php inbox-run 25
```

Tambien muestra el bloque de `crontab` recomendado, sustituyendo `/ruta/a/uanna` por la ruta real. La recomendacion por defecto es ejecutar recepcion y envios cada minuto:

```cron
* * * * * cd /ruta/a/uanna && php oannes/bin/oannes.php queue-run 25 >/dev/null 2>&1
* * * * * cd /ruta/a/uanna && php oannes/bin/oannes.php inbox-run 25 >/dev/null 2>&1
```

El panel incluye ademas un comando para guardar esas lineas en el crontab del usuario actual.

## Comandos utiles

```sh
php oannes/bin/oannes.php rebuild-index
php oannes/bin/oannes.php validate-threads
php oannes/bin/oannes.php queue-list
php oannes/bin/oannes.php auth-audit
php oannes/bin/oannes.php readiness 20
```

Para migraciones desde snac:

```sh
php oannes/bin/oannes.php analyse-snac /ruta/a/snac
php oannes/bin/oannes.php import-snac /ruta/a/snac
php oannes/bin/oannes.php rebuild-index
php oannes/bin/oannes.php validate-threads
```

## Datos y copias de seguridad

Los datos vivos de una instancia estan en `oannes/data` y no forman parte del repositorio. Haz copias de seguridad de ese directorio y de `oannes/public/assets`.

No publiques nunca:

- `oannes/data`
- `oannes/public/assets`
- `oannes/config/oannes.php`
- backups de migracion
- claves locales, sesiones, colas o indices de una comunidad real

## Desarrollo local

Para probar sin configurar Apache o Nginx:

```sh
php -S 127.0.0.1:8000 -t oannes/public oannes/public/server.php
```

Luego abre:

```text
http://127.0.0.1:8000
```

En desarrollo local, la federacion real requiere HTTPS y un dominio accesible desde otros servidores.

# uanna
