# VPP

Virtual Product Pages plugin for WordPress.

## Descargar el ZIP del plugin

Dado que este repositorio no puede adjuntar binarios directamente, el archivo listo para subir a WordPress está guardado como texto base64 en `virtual-product-pages-1.3.0.zip.base64`.

Para obtener el ZIP ejecutá en tu terminal:

```bash
base64 -d virtual-product-pages-1.3.0.zip.base64 > virtual-product-pages-1.3.0.zip
```

Luego subí `virtual-product-pages-1.3.0.zip` desde la pantalla de plugins de WordPress (Plugins → Añadir nuevo → Subir plugin).

## Novedades 1.3.0

- Ping automático a Google y Bing después de regenerar los sitemaps.
- Nueva pestaña **VPP Status** con métricas básicas, estado de TiDB/Algolia y acceso al log.
- Registro de errores en `wp-content/uploads/vpp-logs/vpp.log`, con opciones para descargar o limpiar el archivo.
