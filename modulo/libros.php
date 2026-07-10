<?php

require_once __DIR__ . '/../BD/config.php';
require_once __DIR__ . '/../funciones.php';


$db  = new Database();
$pdo = $db->conectar();


if ($pdo === null) {
    die("Error de conexión a la base de datos");
}


$mensaje = "";        // Variable donde se guardará el texto de la alerta a mostrar (éxito o error)
$tipo_mensaje = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ?? '' evita un error si por alguna razón "accion" no llegó
    $accion = $_POST['accion'] ?? '';

    // ------------------------------------------------------------
    // ACCIÓN: crear libro
    // ------------------------------------------------------------
    if ($accion == 'crear_libro') {

        // trim() quita espacios en blanco sobrantes al inicio/final
        $nombre       = trim($_POST['nombre_libro'] ?? '');
        $id_categoria = trim($_POST['id_categoria_libro'] ?? '');

        // Validación: ningún campo puede llegar vacío
        if (empty($nombre) || empty($id_categoria)) {
            $mensaje = "⚠️ Todos los campos son obligatorios.";
            $tipo_mensaje = "danger";
        } else {
            try {
                // Al crear, el libro entra por defecto en estado
                // "disponible" (eso lo maneja crearLibro() internamente)
                crearLibro($pdo, $nombre, $id_categoria);
                $mensaje = "✅ Libro creado exitosamente.";
                $tipo_mensaje = "success";
            } catch (PDOException $e) {
                // Cualquier error de base de datos (por ejemplo, la
                // categoría no existe) se muestra al usuario
                $mensaje = "❌ Error: " . $e->getMessage();
                $tipo_mensaje = "danger";
            }
        }

        // ------------------------------------------------------------
        // ACCIÓN: actualizar libro
        // ------------------------------------------------------------
    } elseif ($accion == 'actualizar_libro') {

        // Aquí, a diferencia de "crear", también llega el id
        // del libro que se está editando (viene de un campo oculto)
        $id_libro     = trim($_POST['id_libro'] ?? '');
        $nombre       = trim($_POST['nombre_libro'] ?? '');
        $id_categoria = trim($_POST['id_categoria_libro'] ?? '');

        if (empty($id_libro) || empty($nombre) || empty($id_categoria)) {
            $mensaje = "⚠️ Todos los campos son obligatorios.";
            $tipo_mensaje = "danger";
        } else {
            try {
                actualizarLibro($pdo, $id_libro, $nombre, $id_categoria);
                $mensaje = "✅ Libro actualizado exitosamente.";
                $tipo_mensaje = "success";
            } catch (PDOException $e) {
                $mensaje = "❌ Error: " . $e->getMessage();
                $tipo_mensaje = "danger";
            }
        }

        // ------------------------------------------------------------
        // ACCIÓN: activar/inactivar libro (borrado lógico)
        // ----------------------------------------------------------
        // "Borrado lógico" significa que el libro nunca se elimina
        // de la base de datos: solo se le cambia el campo "estado"
        // a "inactivo", para que deje de aparecer como disponible
        // para préstamo pero su historial se conserve.
        // ------------------------------------------------------------
    } elseif ($accion == 'estado_libro') {

        $id_libro     = trim($_POST['id_libro'] ?? '');
        $nuevo_estado = trim($_POST['nuevo_estado'] ?? '');

        // in_array() verifica que el valor recibido sea EXACTAMENTE
        // 'disponible' o 'inactivo' -> evita que alguien manipule el
        // formulario (con las herramientas del navegador, por ejemplo)
        // y mande un valor inválido a la base de datos
        if (empty($id_libro) || !in_array($nuevo_estado, ['disponible', 'inactivo'])) {
            $mensaje = "⚠️ Solicitud no válida.";
            $tipo_mensaje = "danger";
        } else {
            try {
                // Se vuelve a consultar el libro en la BD (no se confía
                // en el estado que traiga el formulario) para revisar
                // su estado real antes de dejar cambiarlo
                $libro_actual = obtenerLibroPorId($pdo, $id_libro);

                if ($libro_actual && $libro_actual['estado'] == 'prestado') {
                    // Un libro prestado no se puede activar/inactivar
                    // hasta que sea devuelto
                    $mensaje = "❌ No puedes cambiar el estado de un libro que está prestado.";
                    $tipo_mensaje = "danger";
                } else {
                    cambiarEstadoLibro($pdo, $id_libro, $nuevo_estado);
                    $mensaje = $nuevo_estado == 'disponible' ? "✅ Libro activado." : "✅ Libro inactivado.";
                    $tipo_mensaje = "success";
                }
            } catch (PDOException $e) {
                $mensaje = "❌ Error: " . $e->getMessage();
                $tipo_mensaje = "danger";
            }
        }
    }
}




// Si la URL trae ?editar=5, se busca ese libro en la base de
// datos para precargar sus datos en el formulario (modo edición).
// Si no viene "editar" en la URL, $libro_editar queda en null y
// el formulario se muestra vacío, en modo creación.
$libro_editar = isset($_GET['editar']) ? obtenerLibroPorId($pdo, trim($_GET['editar'])) : null;

// Lista completa de libros (disponibles, prestados e inactivos)
// para pintar la tabla de abajo
$libros = obtenerLibrosTodos($pdo);

// Solo las categorías activas se ofrecen en el <select>, para no
// dejar asignar un libro nuevo a una categoría ya inactivada
$categorias_activas = obtenerCategoriasActivas($pdo);

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Biblioteca</title>
    <!-- Bootstrap local, sin depender de internet -->
    <link href="../bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <!-- Estilos propios del proyecto -->
    <link href="../css/style.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="overlay">
        <div class="container py-4">

            <h1 class="text-center mb-4 titulo-principal">libros</h1>

            <!-- ==========================================================
             MENÚ DE NAVEGACIÓN
             Como cada módulo ahora es su propio archivo .php, el
             link activo se marca "a mano" con la clase "active"
             en el <a> que corresponde a esta página (Libros),
             en vez de comparar con una variable $vista.
        =========================================================== -->
            <ul class="nav nav-pills justify-content-center mb-4">
                <li class="nav-item">
                    <a class="nav-link" href="../index.php">📖 Préstamos</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="categorias.php">🏷️ Categorías</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="libros.php">📚 Libros</a>
                </li>
            </ul>

            <!-- ==========================================================
             MÓDULO: LIBROS (CRUD completo)
        =========================================================== -->
            <div class="row">

                <!-- ------------------------------------------------
                 Formulario: sirve tanto para crear como para editar.
                 Cambia de "modo" según si $libro_editar tiene datos
                 (viene de la BD porque llegó ?editar=X) o es null.
                ------------------------------------------------- -->
                <div class="col-lg-4 mb-4">
                    <div class="card card-biblioteca">
                        <div class="card-header-biblioteca">
                            <?php echo $libro_editar ? '✏️ Editar Libro' : '➕ Nuevo Libro'; ?>
                        </div>
                        <div class="card-body">

                            <!-- Alerta con el resultado del último formulario enviado -->
                            <?php if ($mensaje != ''): ?>
                                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                                    <?php echo $mensaje; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="libros.php">
                                <!-- El valor de "accion" cambia dinámicamente entre
                                 crear_libro / actualizar_libro según el modo -->
                                <input type="hidden" name="accion" value="<?php echo $libro_editar ? 'actualizar_libro' : 'crear_libro'; ?>">

                                <!-- Solo en modo edición se manda el id del libro,
                                 para que el PHP de arriba sepa CUÁL actualizar -->
                                <?php if ($libro_editar): ?>
                                    <input type="hidden" name="id_libro" value="<?php echo $libro_editar['id_libro']; ?>">
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label for="nombre_libro" class="form-label fw-bold">Nombre:</label>
                                    <input type="text" class="form-control" id="nombre_libro" name="nombre_libro"
                                        placeholder="Ej: El Quijote"
                                        value="<?php echo $libro_editar ? htmlspecialchars($libro_editar['nombre']) : ''; ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label for="id_categoria_libro" class="form-label fw-bold">Categoría:</label>
                                    <select class="form-select" id="id_categoria_libro" name="id_categoria_libro" required>
                                        <option value="">-- Seleccione una categoría --</option>
                                        <?php foreach ($categorias_activas as $c): ?>
                                            <!-- "selected" se agrega solo si, en modo edición, esta es
                                             la categoría que el libro ya tenía asignada -->
                                            <option value="<?php echo $c['id_categoria']; ?>"
                                                <?php echo ($libro_editar && $libro_editar['id_categoria'] == $c['id_categoria']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($c['nombre_categoria']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-biblioteca w-100">
                                    <?php echo $libro_editar ? 'Guardar cambios' : 'Crear libro'; ?>
                                </button>

                                <!-- El botón "Cancelar" solo aparece en modo edición,
                                 y simplemente recarga la página sin ?editar=X -->
                                <?php if ($libro_editar): ?>
                                    <a href="libros.php" class="btn btn-secondary w-100 mt-2">Cancelar</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ------------------------------------------------
                 Listado de libros con su estado y acciones
                ------------------------------------------------- -->
                <div class="col-lg-8 mb-4">
                    <div class="card card-biblioteca">
                        <div class="card-header-biblioteca">📋 Listado de Libros</div>
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover mb-0 tabla-biblioteca">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Categoría</th>
                                        <th>Estado</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($libros) == 0): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No hay libros registrados</td>
                                        </tr>
                                        <?php else: foreach ($libros as $l): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($l['nombre']); ?></td>
                                                <td><?php echo htmlspecialchars($l['nombre_categoria']); ?></td>
                                                <td>
                                                    <!-- El libro puede estar en 3 estados distintos,
                                                     cada uno con su propio color de badge -->
                                                    <?php if ($l['estado'] == 'disponible'): ?>
                                                        <span class="badge badge-disponible">Disponible</span>
                                                    <?php elseif ($l['estado'] == 'prestado'): ?>
                                                        <span class="badge bg-warning text-dark">Prestado</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-inactivo">Inactivo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <!-- Editar: link con GET que carga este libro en el formulario -->
                                                    <a href="libros.php?editar=<?php echo $l['id_libro']; ?>" class="btn btn-sm btn-outline-light">Editar</a>

                                                    <!-- El botón de Activar/Inactivar SOLO se muestra si el
                                                     libro no está prestado (un libro prestado no se puede
                                                     tocar hasta que sea devuelto) -->
                                                    <?php if ($l['estado'] != 'prestado'): ?>
                                                        <form method="POST" action="libros.php" class="d-inline">
                                                            <input type="hidden" name="accion" value="estado_libro">
                                                            <input type="hidden" name="id_libro" value="<?php echo $l['id_libro']; ?>">
                                                            <!-- El valor y el texto del botón cambian según
                                                             el estado actual del libro -->
                                                            <?php if ($l['estado'] == 'disponible'): ?>
                                                                <input type="hidden" name="nuevo_estado" value="inactivo">
                                                                <button type="submit" class="btn btn-sm btn-danger">Inactivar</button>
                                                            <?php else: ?>
                                                                <input type="hidden" name="nuevo_estado" value="disponible">
                                                                <button type="submit" class="btn btn-sm btn-success">Activar</button>
                                                            <?php endif; ?>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                    <?php endforeach;
                                    endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Bootstrap local (JS), necesario para el botón de cerrar alertas -->
    <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
</body>

</html>