# Finanzas API

API en Laravel 12 para una app de finanzas personales: sueldo semanal, gastos, recibos (bills), metas de ahorro (individuales y grupales), tandas y un calendario financiero que combina todo lo anterior.

## Stack

- PHP 8.2+, Laravel 12
- Autenticación por token con [Laravel Sanctum](https://laravel.com/docs/sanctum)
- SQLite en desarrollo/testing, MySQL disponible vía `docker-compose.yml`

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # si usas SQLite (default de .env.example)
php artisan migrate
php artisan serve
```

Para correr con MySQL en Docker: `docker compose up -d` y ajusta `DB_*` en `.env` acorde a las variables de `docker-compose.yml` (`DB_ROOT_PASSWORD`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, con defaults de desarrollo si no las defines).

Configura `CORS_ALLOWED_ORIGINS` en `.env` con la URL del frontend que consumirá la API (separadas por coma si son varias).

## Tests

```bash
php artisan test
```

## Autenticación

Todas las rutas bajo `auth:sanctum` requieren el header `Authorization: Bearer <token>`, obtenido de `/api/auth/register` o `/api/auth/login`.

| Método | Endpoint | Descripción |
|---|---|---|
| POST | `/api/auth/register` | Crea un usuario y devuelve `{ token, user }` |
| POST | `/api/auth/login` | Devuelve `{ token, user }` |
| POST | `/api/auth/logout` | Revoca el token actual |
| GET | `/api/auth/me` | Usuario autenticado |

`register`/`login` están limitadas a 5 intentos por minuto por IP.

## Endpoints principales

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/api/dashboard` | Resumen: ahorro, recibos, metas, tandas, calendario, sueldo/gasto semanal |
| POST | `/api/dashboard/weekly-income` | Registra/actualiza el sueldo de la semana actual |
| GET/POST/PUT/DELETE | `/api/bills` | CRUD de recibos (`due_date`, `status`, etc.) |
| GET | `/api/expenses` | Gastos por semana o mes (`?scope=week\|month&date=YYYY-MM-DD`) |
| POST/DELETE | `/api/expenses` | Registrar/eliminar un gasto |
| GET | `/api/saving-goals` | Metas de ahorro del usuario (propias o donde participa) |
| POST | `/api/saving-goals` | Crear meta |
| POST | `/api/saving-goals/{id}/contribute` | Aportar a una meta (solo dueño o participante) |
| POST | `/api/saving-goals/{id}/members` | Agregar participante por email (solo el dueño) |
| GET | `/api/tandas` | Tandas del usuario (dueño o miembro) |
| POST | `/api/tandas` | Crear tanda |
| POST | `/api/tandas/{id}/members` | Agregar miembro por email (solo el dueño) |
| POST | `/api/tandas/{id}/payments` | Registrar un pago (solo dueño o miembro); avanza la vuelta y la próxima fecha de pago |
| GET | `/api/calendar` | Eventos combinados (bills, tandas, metas, manuales) + gastos diarios en un rango `?start_date&end_date` |
| GET | `/api/calendar/events` | Misma idea, agrupado por mes (`?month=YYYY-MM`) |

Los controles de acceso a metas de ahorro y tandas usan Policies (`app/Policies`), no checks manuales repetidos por controlador.

## Manejo de errores

Las respuestas de error de la API son JSON homogéneo (`{ "message": "...", "errors"?: {...} }`) manejado centralmente en `bootstrap/app.php`. Con `APP_DEBUG=false` no se exponen stack traces ni detalles internos.
