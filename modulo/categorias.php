<?php

require_once __DIR__ . '/../BD/config.php';
require_once __DIR__ . '/../funciones.php';

// Se instancia la conexión a la base de datos una sola vez,
$db  = new Database();      // Crea un objeto de la clase Database
$pdo = $db->conectar();     // Llama al método conectar() y guarda la conexión PDO en $pdo


$mensaje = "";        // Variable donde se guardará el texto de la alerta a mostrar (éxito o error)
$tipo_mensaje = "";    // Variable que guarda el tipo de alerta de Bootstrap (success, danger, etc.)

// Si la conexión falló (devolvió null), se detiene toda la ejecución del script
if ($pdo === null) {
    die("Error de conexión a la base de datos");
}

// Verifica si el formulario fue enviado mediante método POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Obtiene el valor del campo oculto "accion" que dice qué operación hacer;
    // si no viene definido, usa cadena vacía para evitar errores (null coalescing ??)
    $accion = $_POST['accion'] ?? '';

    // ------------------------------------------------------------
    // ACCIÓN: crear categoría nueva
    // ------------------------------------------------------------
    if ($accion == 'crear_categoria') {

        // Toma el nombre enviado desde el formulario y le quita espacios sobrantes con trim()
        $nombre = trim($_POST['nombre_categoria'] ?? '');

        // Valida que el nombre no esté vacío
        if (empty($nombre)) {
            $mensaje = "⚠️ El nombre de la categoría es obligatorio.";
            $tipo_mensaje = "danger"; // Color rojo de alerta (Bootstrap)
        } else {
            try {
                // Llama a la función que inserta la categoría en la base de datos
                crearCategoria($pdo, $nombre);
                $mensaje = "✅ Categoría creada exitosamente.";
                $tipo_mensaje = "success"; // Color verde de alerta
            } catch (PDOException $e) {
                // Código 23000 = violación de restricción UNIQUE
                // (ya existe una categoría con ese nombre)
                $mensaje = ($e->getCode() == 23000)
                    ? "❌ Ya existe una categoría con ese nombre."   // Si el código de error es 23000
                    : "❌ Error: " . $e->getMessage();               // Si es cualquier otro error, muestra el mensaje real
                $tipo_mensaje = "danger";
            }
        }

        // ------------------------------------------------------------
        // ACCIÓN: actualizar categoría existente
        // ------------------------------------------------------------
    } elseif ($accion == 'actualizar_categoria') {

        // Recupera el id de la categoría a editar (viene de un input hidden)
        $id_categoria = trim($_POST['id_categoria'] ?? '');
        // Recupera el nuevo nombre escrito en el formulario
        $nombre = trim($_POST['nombre_categoria'] ?? '');

        // Valida que ambos campos tengan datos
        if (empty($id_categoria) || empty($nombre)) {
            $mensaje = "⚠️ Todos los campos son obligatorios.";
            $tipo_mensaje = "danger";
        } else {
            try {
                // Llama a la función que actualiza el registro en la base de datos
                actualizarCategoria($pdo, $id_categoria, $nombre);
                $mensaje = "✅ Categoría actualizada exitosamente.";
                $tipo_mensaje = "success";
            } catch (PDOException $e) {
                // Igual que en la creación: distingue si el error es por nombre duplicado
                $mensaje = ($e->getCode() == 23000)
                    ? "❌ Ya existe una categoría con ese nombre."
                    : "❌ Error: " . $e->getMessage();
                $tipo_mensaje = "danger";
            }
        }

        // ------------------------------------------------------------
        // ACCIÓN: activar/inactivar categoría (borrado lógico)
        // ------------------------------------------------------------
    } elseif ($accion == 'estado_categoria') {

        // ID de la categoría a la que se le va a cambiar el estado
        $id_categoria = trim($_POST['id_categoria'] ?? '');
        // Nuevo estado que se quiere asignar ('activo' o 'inactivo')
        $nuevo_estado = trim($_POST['nuevo_estado'] ?? '');

        // in_array() verifica que el valor recibido sea EXACTAMENTE
        // 'activo' o 'inactivo' -> evita que alguien manipule el
        // formulario y mande un valor inválido
        if (empty($id_categoria) || !in_array($nuevo_estado, ['activo', 'inactivo'])) {
            $mensaje = "⚠️ Solicitud no válida.";
            $tipo_mensaje = "danger";
        } else {
            try {
                // Actualiza en la base de datos el campo "estado" de la categoría
                cambiarEstadoCategoria($pdo, $id_categoria, $nuevo_estado);
                // Elige el texto del mensaje según el nuevo estado
                $mensaje = $nuevo_estado == 'activo' ? "✅ Categoría activada." : "✅ Categoría inactivada.";
                $tipo_mensaje = "success";
            } catch (PDOException $e) {
                $mensaje = "❌ Error: " . $e->getMessage();
                $tipo_mensaje = "danger";
            }
        }
    }
}

// ----------------------------------------------------------------
// Si la URL trae ?editar=5, cargar esa categoría para mostrarla
// en el formulario (modo edición). Si no, $categoria_editar queda
// en null y el formulario se muestra vacío (modo creación).
// ----------------------------------------------------------------
// isset() revisa si llegó el parámetro "editar" por GET (en la URL)
$categoria_editar = isset($_GET['editar']) ? obtenerCategoriaPorId($pdo, trim($_GET['editar'])) : null;
// Trae TODAS las categorías (activas e inactivas) para pintarlas en la tabla
$categorias = obtenerCategoriasTodas($pdo);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8"><!-- Define la codificación de caracteres (tildes, ñ, emojis) -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0"><!-- Hace que la página sea adaptable a dispositivos móviles -->
    <title>Categorías</title><!-- Título que aparece en la pestaña del navegador -->

    <link href="../bootstrap/css/bootstrap.min.css" rel="stylesheet"><!-- Hoja de estilos de Bootstrap -->
    <link href="../css/style.css" rel="stylesheet"><!-- Hoja de estilos personalizada del proyecto -->
</head>

<body class="bg-light"><!-- Fondo gris claro para toda la página -->

    <div class="overlay"><!-- Capa/contenedor decorativo definido en style.css -->
        <div class="container py-4"><!-- Contenedor centrado de Bootstrap con espacio arriba/abajo -->

            <h1 class="text-center mb-4 titulo-principal">🏷️ Categorías</h1><!-- Título principal de la página -->
            <!-- MENÚ -->
            <ul class="nav nav-pills justify-content-center mb-4"><!-- Menú de navegación -->
                <li class="nav-item"><!-- Opción del menú -->
                    <a class="nav-link" href="../index.php">📖 Préstamos</a><!-- Enlace al módulo de préstamos -->
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="categorias.php">🏷️ Categorías</a><!-- Módulo actual (resaltado con "active") -->
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="libros.php">📚 Libros</a><!-- Enlace al módulo de libros -->
                </li>
            </ul>

            <div class="row"><!-- Fila de Bootstrap que divide la pantalla en columnas -->

                <!-- FORMULARIO -->
                <div class="col-lg-4 mb-4"><!-- Columna izquierda (4 de 12) en pantallas grandes -->

                    <div class="card card-biblioteca"><!-- Tarjeta del formulario -->

                        <div class="card-header-biblioteca"><!-- Encabezado de la tarjeta -->
                            <?php echo $categoria_editar ? '✏️ Editar Categoría' : '➕ Nueva Categoría'; ?><!-- Si existe una categoría para editar muestra "Editar", de lo contrario muestra "Nueva" -->
                        </div>

                        <div class="card-body"><!-- Cuerpo de la tarjeta -->

                            <?php if ($mensaje != ''): ?><!-- Solo muestra la alerta si hay un mensaje guardado -->
                                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show"><!-- Alerta Bootstrap; el color depende de $tipo_mensaje -->
                                    <?php echo $mensaje; ?><!-- Imprime el mensaje -->
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button><!-- Botón para cerrar la alerta -->
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="categorias.php"><!-- Formulario enviado por POST hacia esta misma página -->

                                <input type="hidden"
                                    name="accion"
                                    value="<?php echo $categoria_editar ? 'actualizar_categoria' : 'crear_categoria'; ?>"><!-- Campo oculto que indica la acción a realizar (crear o actualizar) -->

                                <?php if ($categoria_editar): ?><!-- Solo se ejecuta cuando se está editando -->

                                    <input type="hidden"
                                        name="id_categoria"
                                        value="<?php echo $categoria_editar['id_categoria']; ?>"><!-- Envía el ID de la categoría que se está editando -->

                                <?php endif; ?>

                                <div class="mb-3"><!-- Espacio inferior entre campos -->
                                    <label class="form-label fw-bold">Nombre:</label><!-- Etiqueta del campo -->

                                    <input
                                        type="text" class="form-control" name="nombre_categoria" placeholder="Ej: Ficción"
                                        value="<?php echo $categoria_editar ? htmlspecialchars($categoria_editar['nombre_categoria']) : ''; ?>"
                                        required><!-- Campo de texto; si se está editando trae el nombre actual (escapado con htmlspecialchars para evitar XSS); "required" impide enviarlo vacío -->
                                </div>

                                <button class="btn btn-biblioteca w-100"><!-- Botón que ocupa todo el ancho -->
                                    <?php
                                    echo $categoria_editar ? 'Guardar cambios' : 'Crear categoría'; // Cambia el texto del botón según el modo (crear o editar)
                                    ?>
                                </button>

                                <?php if ($categoria_editar): ?><!-- Solo aparece cuando se está editando -->
                                    <a href="categorias.php" class="btn btn-secondary w-100 mt-2">
                                        Cancelar<!-- Regresa al modo creación (limpia el ?editar= de la URL) -->
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- TABLA -->
                <div class="col-lg-8 mb-4"><!-- Columna derecha (8 de 12) en pantallas grandes -->
                    <div class="card card-biblioteca"><!-- Tarjeta -->
                        <div class="card-header-biblioteca">
                            📋 Listado de Categorías
                        </div>

                        <div class="card-body p-2"><!-- Poco padding para que la tabla se vea más amplia -->
                            <table class="table table-striped table-hover mb-0 tabla-biblioteca"><!-- Tabla Bootstrap con filas alternadas y efecto hover -->

                                <thead>

                                    <tr>
                                        <th>Nombre</th>
                                        <th>Estado</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>

                                </thead>
                                <tbody>
                                    <?php if (count($categorias) == 0): ?><!-- Si no existen categorías -->

                                        <tr>
                                            <td colspan="3" class="text-center text-muted">
                                                No hay categorías registradas
                                            </td>
                                        </tr>

                                    <?php else: ?><!-- Si existen categorías -->

                                        <?php foreach ($categorias as $c): ?><!-- Recorre cada categoría del arreglo $categorias -->

                                            <tr>

                                                <td>
                                                    <?php echo htmlspecialchars($c['nombre_categoria']); ?><!-- Muestra el nombre (escapado para evitar inyección de HTML) -->
                                                </td>

                                                <td>
                                                    <?php if ($c['estado'] == 'activo'): ?><!-- Si está activa -->
                                                        <span class="badge badge-disponible">
                                                            Activo
                                                        </span>
                                                    <?php else: ?><!-- Si está inactiva -->
                                                        <span class="badge badge-inactivo">
                                                            Inactivo
                                                        </span>
                                                    <?php endif; ?>

                                                </td>

                                                <td class="text-end"><!-- Columna de acciones alineada a la derecha -->

                                                    <a
                                                        href="categorias.php?editar=<?php echo $c['id_categoria']; ?>"
                                                        class="btn btn-sm btn-outline-light">
                                                        Editar<!-- Recarga la página en modo edición pasando el ID por GET -->
                                                    </a>

                                                    <form
                                                        method="POST" action="categorias.php" class="d-inline"><!-- Formulario para cambiar estado; d-inline evita que se vaya a otra línea -->
                                                        <input
                                                            type="hidden" name="accion" value="estado_categoria"><!-- Indica al PHP que la acción es cambiar el estado -->
                                                        <input
                                                            type="hidden" name="id_categoria" value="<?php echo $c['id_categoria']; ?>"><!-- ID de la categoría sobre la que se actúa -->
                                                        <?php if ($c['estado'] == 'activo'): ?><!-- Si está activa, el botón debe inactivarla -->
                                                            <input
                                                                type="hidden" name="nuevo_estado" value="inactivo"><!-- El estado que se enviará al hacer clic -->
                                                            <button
                                                                class="btn btn-sm btn-danger">
                                                                Inactivar
                                                            </button>
                                                        <?php else: ?><!-- Si está inactiva, el botón debe activarla -->
                                                            <input
                                                                type="hidden" name="nuevo_estado" value="activo">

                                                            <button
                                                                class="btn btn-sm btn-success">
                                                                Activar
                                                            </button>
                                                        <?php endif; ?>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../bootstrap/js/bootstrap.bundle.min.js"></script><!-- Bootstrap JS (necesario para que funcionen alertas cerrables, etc.) -->

</body>

</html>