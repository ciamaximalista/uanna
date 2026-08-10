# Uanna Android

Aplicacion Android minima para abrir una instancia Uanna en una WebView a pantalla completa, sin barras ni botones del navegador.

## Funciones

- Carga la URL configurada en `app/src/main/res/values/strings.xml`.
- Mantiene cookies y sesion de la WebView.
- Permite JavaScript, almacenamiento local y subida de archivos para adjuntos e imagenes.
- Usa el boton atras del movil para navegar dentro de la web.
- Abre enlaces externos en el navegador del sistema.
- Solicita permiso de notificaciones en Android 13+.
- Observa la bolita `.nav-badge` de Uanna mientras la app esta abierta y muestra una notificacion local si sube el contador.

## Limite de las notificaciones

Estas notificaciones son locales y dependen de que la app este abierta o en memoria. Para notificaciones reales con la app cerrada hace falta integrar Firebase Cloud Messaging u otro servicio push, y anadir soporte servidor en Uanna para registrar dispositivos y enviar avisos.

## Compilar desde Uanna

El administrador de instancia puede compilar desde `Panel de administracion > Compilar app`.

Uanna actualiza antes de compilar:

- `app_name`, usando el nombre de instancia.
- `site_url`, usando `base_url`.
- Iconos de launcher, usando el favicon de instancia.

El APK generado se publica en:

```text
oannes/public/assets/instance/uanna-app.apk
```

## Compilar manualmente

Abre la carpeta `android/` con Android Studio y ejecuta:

```sh
gradle assembleDebug
```

El APK debug quedara en:

```text
android/app/build/outputs/apk/debug/app-debug.apk
```

Para publicar o instalar de forma estable conviene crear una firma release propia.

La toolchain local, `local.properties`, builds, APKs y claves no deben subirse al repositorio.
