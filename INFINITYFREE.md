# Publicar Reads en InfinityFree

## Qué se va a publicar

Reads es una aplicación web en PHP y MySQL. No genera un APK Android por sí sola. Esta preparación añade una página de presentación en `public/instalar.php` y la posibilidad de instalar la web como PWA desde un navegador compatible. La PWA sigue necesitando Internet para iniciar sesión y guardar datos.

## 1. Crear el hosting y activar HTTPS

1. Crea una cuenta en InfinityFree y añade un sitio usando un subdominio gratuito o un dominio tuyo.
2. En el panel del sitio, activa/solicita el certificado SSL gratuito y espera a que el panel indique que HTTPS está listo.
3. Antes de probar la instalación PWA, abre el sitio con `https://` y comprueba que no hay aviso de certificado.

Los nombres exactos de botones del panel pueden cambiar. Usa los datos que el propio panel muestre para tu cuenta.

## 2. Crear la base de datos

1. En el panel, abre **MySQL Databases** y crea una base de datos y su usuario si el panel lo pide.
2. Copia exactamente el servidor/hostname, el nombre de la base de datos, el usuario y la contraseña que indica InfinityFree. No supongas que el host es `localhost`.
3. Si ya tienes Reads funcionando en tu ordenador, exporta su base de datos completa desde phpMyAdmin como SQL. En InfinityFree abre phpMyAdmin para la base nueva e importa ese `.sql`.
4. Guarda una copia de seguridad local. No compartas ni subas el `.sql` si contiene datos privados.

**Importante:** en el repositorio no se encontró un volcado SQL de la base principal. Algunos módulos crean tablas auxiliares automáticamente, pero eso no sustituye a exportar e importar las tablas principales de usuarios, biblioteca y comunidad. Si no tienes una base local que ya funcione, primero hay que localizar/crear su esquema; no inventes una base vacía y esperes que la app funcione.

## 3. Preparar los archivos

1. Descarga el repositorio como ZIP desde GitHub y descomprímelo en tu ordenador.
2. En el gestor de archivos/FTP de InfinityFree, entra en `htdocs`.
3. Crea dentro una carpeta llamada `Reads`.
4. Sube **el contenido de la raíz del proyecto** dentro de `htdocs/Reads`. Debe quedar, por ejemplo, `htdocs/Reads/config/`, `htdocs/Reads/src/`, `htdocs/Reads/public/`, `htdocs/Reads/temas/` y `htdocs/Reads/img/`.
5. No subas el proyecto dentro de `htdocs/Reads/public/`: `src` y `config` deben quedar al mismo nivel que `public`.

La ubicación `Reads` es intencional: el código tiene rutas `/Reads` definidas para desarrollo. Con esta estructura, la URL de presentación será `https://TU-DOMINIO/Reads/public/instalar.php` y la aplicación estará en la misma carpeta `public`.

## 4. Configurar la conexión MySQL

1. En el gestor de archivos, abre `htdocs/Reads/config/`.
2. Copia `config.local.example.php` y llama a la copia `config.local.php`.
3. Edita solo la copia `config.local.php`; completa los cuatro valores con los datos del panel MySQL de InfinityFree. Conserva las demás claves opcionales vacías si no las usas.

```php
'db_host' => 'HOST_MYSQL_INDICADO_POR_INFINITYFREE',
'db_name' => 'NOMBRE_COMPLETO_DE_LA_BASE',
'db_user' => 'USUARIO_MYSQL_COMPLETO',
'db_pass' => 'CONTRASEÑA_MYSQL',
```

4. Guarda el archivo. `config/config.local.php` está incluido en `.gitignore`: no lo publiques en GitHub ni envíes una captura con sus contraseñas.
5. En InfinityFree, importa en phpMyAdmin el `.sql` exportado de tu base local.

## 5. Abrir la web y probarla

Abre `https://TU-DOMINIO/Reads/public/instalar.php`. Desde ahí, crea una cuenta de prueba o entra a tu cuenta y comprueba estas funciones:

- registro e inicio/cierre de sesión;
- búsqueda, biblioteca y actualización del progreso;
- perfil y subida de avatar/portada;
- páginas de comunidad y estadísticas;
- correo de recuperación, si lo configuras (puede requerir ajustes SMTP y probarse por separado).

Si aparece un error de base de datos, revisa primero que el hostname, nombre, usuario y contraseña sean exactamente los del panel y que el `.sql` esté importado. Si la página abre pero faltan estilos/imágenes, comprueba que la carpeta está realmente en `htdocs/Reads`, no en otra ruta, y conserva `/Reads` en la URL.

## 6. Instalar Reads en Android o iPhone

La PWA no es un APK y no se descarga como archivo. La persona abre el sitio HTTPS y lo añade a su pantalla de inicio:

- **Android/Chrome:** abre la URL de instalación, toca **Instalar Reads** si aparece; si no, abre el menú ⋮ y elige **Instalar aplicación** o **Añadir a pantalla de inicio**.
- **iPhone/Safari:** abre la URL, toca **Compartir** y luego **Añadir a pantalla de inicio**.

La landing ofrece los botones de registro/login y una guía de instalación. El botón automático depende del navegador y puede no aparecer en todos; la opción del menú sigue siendo la alternativa. La app necesita conectividad para utilizar el backend PHP/MySQL.

## Nota sobre APK y tiendas

Para ofrecer un `.apk` descargable hace falta crear y firmar una aplicación Android que envuelva o rehaga el sitio, publicar ese archivo en una ubicación de descarga y mantenerlo actualizado. No basta con copiar el PHP a InfinityFree. La ruta PWA de esta guía evita esa compilación y suele ser gratuita. La publicación en Google Play es un proceso separado y puede tener costes y requisitos propios.
