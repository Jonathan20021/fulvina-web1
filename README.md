# SCH MEDICOS Redesign + CRM

Proyecto PHP puro para XAMPP local con sitio publico, SEO tecnico y CRM operativo para SCH MEDICOS.

## Requisitos

- XAMPP con Apache y MySQL activos
- PHP 8.2 o superior
- Navegador con internet para cargar Tailwind, Alpine, Lucide y Chart.js por CDN

## Instalacion local

1. Abre XAMPP y enciende Apache y MySQL.
2. Visita `http://localhost/fulvina-web/install.php`.
3. Haz clic en `Crear / actualizar base de datos`.
4. Entra al CRM en `http://localhost/fulvina-web/crm/login.php`.

Credenciales iniciales:

- Correo: `admin@sch.local`
- Contrasena: `admin123`

## Estructura

- `index.php`, `servicios.php`, `proyectos.php`, `soporte.php`, `contacto.php`: sitio publico con SEO.
- `crm/`: panel CRM con clientes, equipos, cotizaciones, tickets, reportes y usuarios.
- `database/schema.sql`: tablas MySQL.
- `assets/media/`: multimedia extraida del sitio publico de SCH.
- `assets/concepts/`: conceptos visuales generados para guiar el rediseño.

## Seguridad antes de produccion

- Cambia las credenciales de `config/database.php`.
- Cambia o elimina el usuario `admin@sch.local`.
- Protege o elimina `install.php`.
- Configura HTTPS y dominio canonico final.
