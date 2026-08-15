![Uanna](uanna-banner.png)

# Uanna

Uanna es un servidor de micro-blogging para comunidades por invitacion dentro del Fediverso. Esta pensado para grupos menores de 80 personas que prefieren una herramienta sencilla, auditable y facil de mantener antes que una plataforma grande con muchas dependencias.

Uanna funciona con archivos JSON y XML. No usa SQLite, MySQL, MariaDB, Redis ni ningun otro motor de base de datos.

## Caracteristicas

- Publicacion de notas locales con visibilidad publica, seguidores o privada.
- Timeline personal para usuarios autenticados, con scroll infinito hasta cargar todas las publicaciones disponibles.
- Portada publica con presentacion editable de la instancia.
- Perfiles publicos en rutas tipo `/@usuario`.
- Perfiles internos para actores federados cacheados, manteniendo enlace al perfil original.
- Favoritos e impulsos, con avatares enlazados a los perfiles y acciones reversibles.
- Arboles de respuestas basados en `inReplyTo`, sin confundir enlaces con respuestas.
- Menciones `@usuario` y `@usuario@servidor` con enlaces y avisos.
- Hasta cuatro adjuntos de imagen por publicacion por defecto, cada uno con texto alternativo y visible en modal separado para centrar el timeline en la lectura y las personas, y reducir el estres del scroll.
- Edicion y borrado de publicaciones propias con envio de actividades ActivityPub.
- Panel de usuario organizado como menu de vistas independientes para perfil, notificaciones, red, buscador del timeline, mensajes privados, exportacion/migracion y descarga de app.
- La busqueda del timeline revisa publicaciones antiguas accesibles mas alla de la vista corta del panel; `timeline_search_limit` controla esa ventana.
- Grafo social del nodo en PNG, generado bajo demanda por el administrador y descargable desde la caja Red de cada usuario.
- Vista `Conectados con...` en Red, con perfiles remotos seguidos por miembros del nodo, ordenados por numero de seguidores locales y con controles para seguir, dejar de seguir, silenciar y bloquear.
- Interfaz multidioma por archivos JSON, con idioma por defecto de instancia e idioma elegido por cada usuario.
- Exportacion de usuario desde el panel en ZIP, con `archive.xml` y adjuntos locales incluidos.
- Notificaciones con contador de no leidas y pendientes, enlaces a perfiles originales y publicaciones afectadas.
- Panel de administracion para configurar imagenes, nombre, presentacion, usuarios, actualizaciones y bloqueos.
- Compilacion de app Android desde el panel de administracion, usando el nombre y favicon de la instancia.
- Importacion de usuarios desde XML o ZIP Uanna, restaurando perfil, posts y adjuntos.
- Moderacion para altas, seguimientos, publicaciones pendientes, bloqueos de actores y servidores.
- Actualizacion de recepcion y envios por cron o al detectarse actividad, sin saturar visitas normales.
- Importacion y herramientas de diagnostico para migraciones desde snac.
- Almacenamiento atomico en archivos para reducir corrupcion ante cortes o fallos.

## Licencia

Uanna se distribuye bajo la Licencia Publica de la Union Europea, version 1.2, EUPL-1.2. El texto completo esta en `LICENSE.txt`.

## Requisitos

- PHP 8.1 o superior.
- Servidor web con soporte PHP, por ejemplo Apache con PHP-FPM, Nginx con PHP-FPM o el servidor integrado de PHP para pruebas.
- Extensiones PHP: `json`, `openssl`, `mbstring`, `fileinfo`, `gd`, `dom` y `zip`.
- Un dominio con HTTPS valido para federar correctamente.
- Permisos de escritura para PHP en `oannes/data` y `oannes/public/assets`.
- Opcional para compilar app Android desde el panel: JDK 17, Gradle y Android SDK con platform/build-tools compatibles.

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

Crea los directorios de datos y assets, y da permisos de escritura al usuario del servidor web. Uanna escribe desde PHP, asi que el propietario operativo debe ser el usuario real de Apache/PHP-FPM (`www-data`, `apache`, `nginx`, `nobody` u otro segun el servidor). Si tambien vas a lanzar tareas desde consola, anade tu usuario al mismo grupo.

```sh
cd /ruta/a/uanna

# Cambia estos valores por el usuario/grupo real de PHP en tu servidor.
WEB_USER=www-data
WEB_GROUP=www-data

sudo install -d -m 2775 -o "$WEB_USER" -g "$WEB_GROUP" oannes/data oannes/public/assets
sudo chown -R "$WEB_USER:$WEB_GROUP" oannes/data oannes/public/assets
sudo find oannes/data oannes/public/assets -type d -exec chmod 2775 {} +
sudo find oannes/data oannes/public/assets -type f -exec chmod 0664 {} +

# Opcional, solo si vas a ejecutar comandos de mantenimiento desde tu usuario shell.
sudo usermod -aG "$WEB_GROUP" "$USER"
```

Cierra sesion y vuelve a entrar si has cambiado grupos con `usermod`.

Para detectar el usuario de PHP mira los procesos web que no sean `root`:

```sh
ps -eo user,comm,args | grep -E 'apache2|httpd|php-fpm|nginx' | grep -v root
```

En Apache con PHP-FPM de Debian/Ubuntu suele ser `www-data:www-data`; en Apache de algunas instalaciones compartidas puede ser `nobody:nogroup`; en CentOS/RHEL puede ser `apache:apache`; en Nginx con PHP-FPM depende del `user` configurado en el pool.

No mezcles datos creados por usuarios distintos sin grupo comun escribible. Si unos ficheros quedan como `david` y otros como `nobody`, las notificaciones, respuestas, adjuntos o colas pueden fallar aunque el sitio parezca cargar bien.

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
- `Usar cron`: Uanna no procesa colas durante las visitas. El administrador debe programar los comandos de cola en el crontab del mismo usuario operativo que ejecuta PHP, normalmente `www-data` en Debian/Ubuntu. Es el modo recomendado para instancias con mas trafico federado o para que todo avance aunque nadie visite la web durante horas.

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

Instala esas lineas en el crontab del usuario web/PHP, no en el crontab de root y no usando `sudo` dentro del comando de cron. En Debian/Ubuntu suele ser:

```sh
sudo crontab -u www-data -e
```

Para comprobar donde estan instaladas las tareas:

```sh
sudo crontab -u www-data -l
sudo crontab -l
crontab -l
```

Si las colas corren como `root` mientras la web escribe como `www-data`, pueden quedar archivos en `oannes/data` con propietario incompatible y despues fallar operaciones de escritura. Siempre que sea posible, el usuario de cron y el usuario de PHP deben ser el mismo.

## App Android

El repositorio incluye un proyecto Android ligero en `android/`. La app abre la instancia en una WebView a pantalla completa, mantiene la sesion, permite subir archivos y muestra notificaciones locales mientras esta abierta cuando sube el contador del panel.

Desde `Panel de administracion > Compilar app`, el administrador puede generar un APK para la instancia:

- El nombre de la app se toma del nombre de instancia.
- El icono se genera a partir del favicon de la instancia.
- La URL cargada por la app se toma de `base_url`.
- El APK resultante se publica en `oannes/public/assets/instance/uanna-app.apk`.
- Cuando existe un APK compilado, todos los usuarios ven la caja `Descargar app` en su panel.

Si faltan dependencias, la caja `Compilar app` indica que debe instalarse. Para compilar en el servidor hacen falta:

- JDK 17.
- Gradle.
- Android SDK con platform `android-35` y build-tools.

En Debian/Ubuntu, el JDK puede instalarse con:

```sh
sudo apt install openjdk-17-jdk
```

Gradle y Android SDK pueden instalarse con Android Studio, con los command line tools de Android o dejando una toolchain local en:

```text
android/.toolchain/jdk
android/.toolchain/gradle/current
android/.toolchain/sdk
```

La toolchain, los builds, APKs, claves y `local.properties` no forman parte del repositorio.

## Comandos utiles

```sh
php oannes/bin/oannes.php rebuild-index
php oannes/bin/oannes.php validate-threads
php oannes/bin/oannes.php queue-list
php oannes/bin/oannes.php auth-audit
php oannes/bin/oannes.php readiness 20
php oannes/bin/oannes.php backfill-boosts 50
```

## Exportacion e importacion de usuarios

Cada usuario puede descargar desde `Panel > Exportar / Migrar` un archivo ZIP de migracion. El paquete contiene:

```text
usuario-uanna.zip
archive.xml
media/
```

`archive.xml` incluye el perfil y todos los posts locales del usuario. Si esos posts tienen imagenes o documentos adjuntos almacenados en la instancia, Uanna los copia dentro de `media/` y reescribe sus URLs como rutas relativas.

Las publicaciones pueden incluir varias imagenes adjuntas. El limite por defecto es de cuatro imagenes por publicacion y puede cambiarse con `max_attachment_count` en `oannes/config/oannes.php`; `max_attachment_bytes` se aplica a cada imagen.

Desde `Panel > Exportar / Migrar` el usuario tambien puede borrar todo su contenido o dar de baja su cuenta, escribiendo su nombre de usuario como confirmacion.

El administrador puede importar usuarios desde `Panel de administracion > Importar usuario`. La importacion acepta:

- XML simple generado por Uanna.
- ZIP completo generado por Uanna, con `archive.xml` y adjuntos.

Al importar un ZIP, Uanna restaura los adjuntos en el directorio estatico del usuario de la nueva instancia, reescribe sus URLs publicas y reconstruye los indices. Si el usuario aun no existe, el administrador debe indicar una clave inicial.

Para migraciones desde snac:

```sh
php oannes/bin/oannes.php analyse-snac /ruta/a/snac
php oannes/bin/oannes.php import-snac /ruta/a/snac
php oannes/bin/oannes.php rebuild-index
php oannes/bin/oannes.php validate-threads
```

### Identidad de actores al migrar desde snac

Las instalaciones nuevas de Uanna usan actores en `/u/usuario`:

```php
'local_actor_path' => '/u',
'legacy_actor_paths' => [],
```

Si migras una instancia snac que ya federaba con actores en la raiz, por ejemplo `https://dominio.org/david`, conviene conservar esa identidad como primaria. De lo contrario, otros servidores pueden seguir al actor antiguo y no incorporar bien las publicaciones nuevas aunque reciban las entregas.

En ese caso configura la instancia asi antes de publicar contenido nuevo:

```php
'local_actor_path' => '',
'legacy_actor_paths' => [
    '/u/%s',
],
```

Con esa configuracion, el actor principal seguira siendo `https://dominio.org/usuario` y la ruta `/u/usuario` quedara como alias compatible. Usa esta opcion solo para migraciones que necesiten preservar identidades antiguas; para instalaciones nuevas es mejor mantener `/u/usuario`.

## Politica de permisos

Uanna lee y escribe archivos JSON, XML y adjuntos directamente en disco. No usa base de datos, por lo que los permisos del sistema son parte de la instalacion.

La regla recomendada es:

- El usuario que ejecuta PHP debe poder leer y escribir `oannes/data` y `oannes/public/assets`.
- Todos esos directorios deben tener setgid (`2775`) para que los archivos nuevos hereden el grupo correcto.
- Los archivos deben quedar en `0664` y los directorios en `2775`.
- Las sesiones de `oannes/data/sessions` deben ser escribibles por el mismo grupo; Uanna intenta crearlas como `0660`.
- Si se ejecutan comandos desde consola, el usuario shell debe pertenecer al mismo grupo que PHP.
- No deben mezclarse propietarios incompatibles como `david` y `nobody` si el grupo no tiene escritura.

Para reparar una instalacion existente:

```sh
cd /ruta/a/uanna

WEB_USER=www-data
WEB_GROUP=www-data

sudo chown -R "$WEB_USER:$WEB_GROUP" oannes/data oannes/public/assets
sudo find oannes/data oannes/public/assets -type d -exec chmod 2775 {} +
sudo find oannes/data oannes/public/assets -type f -exec chmod 0664 {} +
sudo usermod -aG "$WEB_GROUP" "$USER"
```

Despues de `usermod`, cierra sesion y vuelve a entrar para que el grupo se aplique a tu shell.

Si PHP esta corriendo realmente como `nobody`, la reparacion concreta seria:

```sh
cd /ruta/a/uanna

sudo chown -R nobody:nogroup oannes/data oannes/public/assets
sudo find oannes/data oannes/public/assets -type d -exec chmod 2775 {} +
sudo find oannes/data oannes/public/assets -type f -exec chmod 0664 {} +
sudo usermod -aG nogroup david
```

Si el servidor usa `www-data`, cambia `nobody:nogroup` por `www-data:www-data`. Lo importante es que PHP y las tareas de consola trabajen sobre el mismo grupo escribible.

## Datos y copias de seguridad

Los datos vivos de una instancia estan en `oannes/data` y no forman parte del repositorio. Los adjuntos y archivos estaticos de usuarios viven en `oannes/data/media/<usuario>`. Haz copias de seguridad de `oannes/data` y `oannes/public/assets`.

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
