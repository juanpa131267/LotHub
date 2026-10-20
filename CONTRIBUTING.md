# Flujo de trabajo

## Ramas

| Rama | Uso |
|---|---|
| `main` | Versión estable. Solo recibe merges desde `develop` al cerrar un sprint (o `hotfix/*`). |
| `develop` | Integración continua del sprint en curso. |
| `feature/sNN-<tema>` | Trabajo de un sprint o historia (ej. `feature/s04-reservas`). Parte de `develop`. |
| `hotfix/<tema>` | Corrección urgente sobre `main`; se integra también en `develop`. |

Los merges se hacen con `--no-ff` para conservar la historia de cada rama.

## Commits

Se usa [Conventional Commits](https://www.conventionalcommits.org/es/):

```
<tipo>(<módulo>): <descripción en imperativo>

feat(seguridad): bloqueo de cuenta tras 5 intentos fallidos (RN-25)
fix(lotes): validar área mayor que cero
docs(planos): instalación de LibreDWG
```

Tipos: `feat`, `fix`, `docs`, `refactor`, `style`, `test`, `chore`, `build`.
Módulos: `core`, `db`, `seguridad`, `catalogos`, `comercial`, `planos`, `ficha`, `clientes`, `publico`, `portal`.

Cuando aplique, se cita el requerimiento del documento de referencia (RF-xx, RN-xx, CU-xx).

## Versiones

Se sigue [SemVer](https://semver.org/lang/es/): `v0.N.0` al cerrar el sprint N y `v1.0.0` en la puesta en producción (S11).

## Nuevo módulo CRUD

Ver [docs/NUEVO_MODULO.md](docs/NUEVO_MODULO.md).
