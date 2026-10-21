<?php

namespace Database\Seeders;

use App\Models\Security\Permission;
use App\Models\Security\Role;
use Illuminate\Database\Seeder;

/**
 * Catálogo de permisos y su asignación a los roles definidos en el script SQL
 * (Tabla 9 del documento: actores del sistema). Es idempotente: se puede volver
 * a ejecutar para agregar permisos nuevos sin perder asignaciones manuales.
 *
 *   php artisan db:seed --class=PermissionSeeder
 */
class PermissionSeeder extends Seeder
{
    /** Código => descripción. Los marcados "(próximo sprint)" aún no tienen pantalla. */
    public const PERMISSIONS = [
        'sistema.acceso_total'             => 'Acceso total a todas las funciones del sistema',
        'panel.acceder'                    => 'Acceder al panel administrativo',

        'usuarios.ver'                     => 'Ver usuarios',
        'usuarios.crear'                   => 'Crear usuarios',
        'usuarios.editar'                  => 'Editar usuarios y asignar sus roles',
        'usuarios.eliminar'                => 'Eliminar / restaurar usuarios',
        'roles.ver'                        => 'Ver roles',
        'roles.crear'                      => 'Crear roles',
        'roles.editar'                     => 'Editar roles',
        'roles.eliminar'                   => 'Eliminar roles sin usuarios',
        'roles.asignar_permisos'           => 'Asignar permisos a roles',
        'permisos.ver'                     => 'Ver permisos',
        'permisos.crear'                   => 'Crear permisos',
        'permisos.editar'                  => 'Editar permisos',
        'permisos.eliminar'                => 'Eliminar permisos sin uso',
        'auditoria.ver'                    => 'Consultar la auditoría',
        'catalogos.ver'                    => 'Ver catálogos parametrizables',
        'catalogos.crear'                  => 'Crear valores de catálogo',
        'catalogos.editar'                 => 'Editar valores de catálogo',
        'catalogos.eliminar'               => 'Eliminar valores de catálogo sin uso',

        'proyectos.ver'                    => 'Ver proyectos y etapas',
        'proyectos.crear'                  => 'Crear proyectos y etapas',
        'proyectos.editar'                 => 'Editar proyectos y etapas',
        'proyectos.eliminar'               => 'Eliminar proyectos y etapas sin lotes',
        'lotes.ver'                        => 'Consultar lotes y su disponibilidad',
        'lotes.crear'                      => 'Registrar lotes',
        'lotes.editar'                     => 'Editar lotes',
        'lotes.eliminar'                   => 'Eliminar lotes sin historial',
        'lotes.bloquear'                   => 'Bloquear / desbloquear lotes',
        'clientes.ver'                     => 'Ver clientes',
        'clientes.crear'                   => 'Registrar clientes',
        'clientes.editar'                  => 'Editar clientes',
        'clientes.eliminar'                => 'Eliminar clientes sin historial',
        'clientes.portal'                  => 'Habilitar o revocar el acceso del cliente al portal',
        'solicitudes.ver'                  => 'Ver solicitudes de información',
        'solicitudes.editar'               => 'Atender solicitudes de información',

        'reservas.ver'                     => 'Ver reservas (próximo sprint)',
        'reservas.crear'                   => 'Registrar reservas (próximo sprint)',
        'reservas.anular'                  => 'Anular reservas (próximo sprint)',
        'negociaciones.ver'                => 'Ver negociaciones (próximo sprint)',
        'negociaciones.crear'              => 'Registrar negociaciones (próximo sprint)',
        'negociaciones.solicitar_anulacion'=> 'Solicitar anulación o desistimiento (próximo sprint)',
        'negociaciones.aprobar_anulacion'  => 'Aprobar anulación o desistimiento (próximo sprint)',
        'acuerdos.ver'                     => 'Ver acuerdos de pago (próximo sprint)',
        'acuerdos.crear'                   => 'Definir acuerdos de pago (próximo sprint)',
        'acuerdos.reestructurar'           => 'Reestructurar acuerdos de pago (próximo sprint)',
        'abonos.ver'                       => 'Ver abonos (próximo sprint)',
        'abonos.crear'                     => 'Registrar abonos manuales (próximo sprint)',
        'abonos.reversar'                  => 'Reversar abonos (próximo sprint)',
        'conciliacion.ver'                 => 'Conciliar transacciones de la pasarela (próximo sprint)',
        'cartera.ver'                      => 'Consultar cartera y mora',
        'cartera.exportar'                 => 'Exportar reportes de cartera (próximo sprint)',
        'estados_cuenta.ver'               => 'Consultar y generar estados de cuenta (próximo sprint)',
        'reclamos.ver'                     => 'Ver consultas y reclamos (próximo sprint)',
        'reclamos.atender'                 => 'Responder consultas y reclamos (próximo sprint)',
        'documentos.ver'                   => 'Ver documentos soporte (próximo sprint)',
        'documentos.cargar'                => 'Cargar documentos soporte (próximo sprint)',

        'portal.acceder'                   => 'Acceder al portal del cliente',
        'portal.pagar'                     => 'Pagar en línea (próximo sprint)',
        'portal.reclamos'                  => 'Radicar consultas o reclamos (próximo sprint)',
    ];

    private const ASESOR = [
        'panel.acceder', 'proyectos.ver', 'lotes.ver', 'lotes.exportar',
        'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.portal',
        'solicitudes.ver', 'solicitudes.editar',
        'reservas.ver', 'reservas.crear', 'reservas.anular',
        'negociaciones.ver', 'negociaciones.crear', 'negociaciones.solicitar_anulacion',
        'documentos.ver', 'documentos.cargar',
    ];

    /** El Director comercial especializa al Asesor (generalización de actores). */
    private const DIRECTOR_EXTRA = [
        'proyectos.crear', 'proyectos.editar', 'proyectos.eliminar',
        'lotes.crear', 'lotes.editar', 'lotes.eliminar', 'lotes.bloquear', 'lotes.importar',
        'clientes.eliminar', 'negociaciones.aprobar_anulacion', 'cartera.ver', 'cartera.exportar',
    ];

    private const AUXILIAR = [
        'panel.acceder', 'proyectos.ver', 'lotes.ver', 'clientes.ver',
        'acuerdos.ver', 'acuerdos.crear', 'acuerdos.reestructurar',
        'abonos.ver', 'abonos.crear', 'abonos.reversar', 'conciliacion.ver',
        'cartera.ver', 'estados_cuenta.ver', 'reclamos.ver', 'reclamos.atender',
        'documentos.ver', 'documentos.cargar',
    ];

    private const CLIENTE = ['portal.acceder', 'portal.pagar', 'portal.reclamos'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $code => $description) {
            Permission::updateOrCreate(['code' => $code], ['description' => $description, 'is_active' => true]);
        }

        $assign = function (string $role, array $codes) {
            Role::where('name', $role)->first()
                ?->permissions()->syncWithoutDetaching(Permission::whereIn('code', $codes)->pluck('id'));
        };

        $assign('ADMINISTRADOR', array_keys(self::PERMISSIONS));
        $assign('ASESOR_COMERCIAL', self::ASESOR);
        $assign('DIRECTOR_COMERCIAL', [...self::ASESOR, ...self::DIRECTOR_EXTRA]);
        $assign('AUXILIAR_CARTERA', self::AUXILIAR);
        $assign('CLIENTE', self::CLIENTE);
    }
}
