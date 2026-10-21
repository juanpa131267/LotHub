# Puesta en producción (Sprint 11)

Fecha objetivo de salida a producción: **28 de diciembre de 2026**, después de cumplir los criterios
de salida de la [operación en paralelo](OPERACION_PARALELA.md).

## 1. Servidor

| Componente | Versión / configuración |
|---|---|
| Sistema operativo | Ubuntu Server 22.04 o 24.04 LTS |
| PHP | 8.2 o superior con `gd`, `zip`, `pdo_mysql`, `mbstring`, `intl` y PHP-FPM |
| Base de datos | MySQL 8 o MariaDB 10.6+ con `event_scheduler = ON` |
| Servidor web | Nginx (plantilla en `tools/deploy/nginx-lothub.conf`) |
| Certificado | Let's Encrypt (`certbot --nginx`) |
| Python 3.10+ | Solo para el procesador de planos (`tools/plan-processor`) |
| Correo | Cuenta SMTP del dominio (recuperación de contraseña, activación, avisos) |

`php.ini`: `upload_max_filesize = 60M`, `post_max_size = 60M`, `memory_limit = 512M`, `date.timezone = America/Bogota`.

## 2. Instalación inicial

```bash
sudo mkdir -p /var/www/lothub && sudo chown $USER:www-data /var/www/lothub
git clone https://github.com/juanpa131267/LotHub.git /var/www/lothub && cd /var/www/lothub
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```

Valores de `.env` para producción (además de la base de datos):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cartera.ejemplo.com
FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
TRUSTED_PROXIES=            # solo si hay un balanceador o Cloudflare delante
LOG_LEVEL=warning

MAIL_MAILER=smtp            # y MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS
USURY_MONTHLY_RATE=...      # certificación vigente de la Superfinanciera, en % mensual

WOMPI_ENV=production
WOMPI_PUBLIC_KEY=pub_prod_...
WOMPI_PRIVATE_KEY=prv_prod_...
WOMPI_INTEGRITY_SECRET=prod_integrity_...
WOMPI_EVENTS_SECRET=prod_events_...

BACKUP_PATH=/var/backups/lothub
```

Base de datos, parches y datos base:

```bash
mysql -u root -p < database/schema/cartera_inmobiliaria.sql
for f in database/schema/patches/*.sql; do mysql -u root -p cartera_inmobiliaria < "$f"; done
mysql -u root -p -e "SET GLOBAL event_scheduler = ON;"   # y event_scheduler=ON en my.cnf
php artisan db:seed --force                              # la contraseña del administrador se muestra una vez
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

Planificador (una sola línea en el crontab de `www-data`):

```cron
* * * * * cd /var/www/lothub && php artisan schedule:run >> /dev/null 2>&1
```

Tareas que ejecuta: vencimiento de reservas y de pagos en línea (15 min), liquidación de mora
(00:30), programación (06:00) y envío (15 min) de comunicaciones de cobro, respaldo (02:00) y el
latido que verifica `lothub:check`.

En Wompi (panel de comercios) registra la URL de eventos `https://cartera.ejemplo.com/pasarela/wompi/eventos`.

## 3. Verificación

```bash
php artisan lothub:check          # debe terminar en «Todo listo para producción»
php artisan lothub:check --strict # sin advertencias
curl -I https://cartera.ejemplo.com/up
```

## 4. Despliegues siguientes

```bash
bash tools/deploy/deploy.sh                 # rama main
BRANCH=v1.0.0 bash tools/deploy/deploy.sh   # una versión etiquetada
```

El script toma un respaldo, actualiza el código, se detiene si hay parches de base de datos nuevos
(para aplicarlos con el respaldo recién tomado), instala dependencias, actualiza permisos y cachés y
termina con `lothub:check`. Para volver atrás: `BRANCH=<versión anterior> bash tools/deploy/deploy.sh`
y, si hubo parches, restaurar el respaldo (`gunzip -c respaldo.sql.gz | mysql ...`).

## 5. Respaldos

`php artisan lothub:backup` vuelca la base (con triggers, vistas, rutinas y eventos) en `.sql.gz` y
comprime `storage/app/private` (documentos, extractos y estados de cuenta). Conserva 14 días
(`BACKUP_RETENTION_DAYS`). **Copia cada respaldo fuera del servidor** (otro disco o almacenamiento en
la nube) y prueba una restauración al menos una vez al mes.

## 6. Lista de chequeo de la salida a producción (28 de diciembre)

**Antes (semana del 21 de diciembre)**
- [ ] Criterios de salida de la operación en paralelo cumplidos y aprobados por el Product Owner.
- [ ] Servidor, dominio y certificado listos; `lothub:check --strict` sin fallas ni advertencias.
- [ ] Llaves de Wompi de producción configuradas y pago de prueba aprobado y conciliado.
- [ ] Correo SMTP probado (recuperación de contraseña y activación del portal).
- [ ] Usuarios del personal creados con su rol; cada uno ingresó y cambió su contraseña temporal.
- [ ] Respaldo automático funcionando y una restauración de prueba realizada.

**Día de la salida**
- [ ] Último corte de la operación en paralelo con 0 diferencias.
- [ ] Hoja de cálculo congelada en solo lectura (respaldo histórico).
- [ ] Invitaciones al portal enviadas a los clientes con autorización de datos y correo.
- [ ] Canales de contacto de los clientes registrados (Ley 2300).
- [ ] Comunicación a los clientes del nuevo medio de pago en línea.

**Después (primeras dos semanas)**
- [ ] Revisión diaria de la cola de asignación, la conciliación de la pasarela y los reclamos.
- [ ] Revisión del registro de errores (`storage/logs`) y de `lothub:check`.
- [ ] Actualización mensual de `USURY_MONTHLY_RATE`.
