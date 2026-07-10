<?php
// ================================================================
// INDEX.PHP - Sistema de Biblioteca
// ----------------------------------------------------------------
// Este archivo maneja SOLO el módulo de Préstamos.
// Los módulos de Categorías y Libros viven en sus propios archivos
// (categorias.php y libros.php).
//
// Todas las consultas SQL viven en funciones.php. Este archivo
// solo LLAMA a esas funciones y arma el HTML con los resultados.
// ================================================================

require_once __DIR__ . '/BD/config.php';
require_once __DIR__ . '/funciones.php';


$db  = new Database();
$pdo = $db->conectar();
$mensaje = "";
$tipo_mensaje = "";

if ($pdo === null) {
    die("Error de conexión a la base de datos");
}


// ================================================================
// ROUTER DE ACCIONES POST
// ----------------------------------------------------------------
// Este bloque completo solo se ejecuta cuando el usuario ENVÍA
// un formulario (POST), nunca cuando solo está viendo la página
// (GET). El campo oculto "accion" identifica cuál de todos los
// formularios del sistema fue el que se envió.
// ================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $accion = $_POST['accion'] ?? '';

    // ------------------------------------------------------------
    // ACCIÓN: prestar un libro
    // ------------------------------------------------------------
    if ($accion == 'prestar') {

        // trim() quita espacios en blanco sobrantes al inicio/final
        // ?? '' evita error si el campo no llegó por alguna razón
        $documento    = trim($_POST['documento'] ?? '');
        $id_libro     = trim($_POST['id_libro'] ?? '');
        $fecha_limite = trim($_POST['fecha_limite'] ?? '');

        // Validación 1: campos vacíos
        if (empty($documento) || empty($id_libro) || empty($fecha_limite)) {
            $mensaje = "⚠️ Todos los campos son obligatorios.";
            $tipo_mensaje = "danger";

            // Validación 2: la fecha límite no puede ser anterior a hoy
            // (comparación de texto funciona porque el formato Y-m-d
            // ordena igual como texto que como fecha real)
        } elseif ($fecha_limite < date('Y-m-d')) {
            $mensaje = "⚠️ La fecha límite no puede ser anterior a hoy.";
            $tipo_mensaje = "danger";
        } else {
            try {
                // Verificar que el usuario existe y está activo
                $usuario = obtenerUsuarioActivoPorDocumento($pdo, $documento);

                if (!$usuario) {
                    $mensaje = "❌ El documento '$documento' no está registrado o está inactivo.";
                    $tipo_mensaje = "danger";
                } else {

                    // Verificar que el libro sigue disponible
                    // (por si alguien más lo prestó mientras tanto)
                    $libro = obtenerLibroDisponiblePorId($pdo, $id_libro);

                    if (!$libro) {
                        $mensaje = "❌ El libro seleccionado ya no está disponible.";
                        $tipo_mensaje = "danger";
                    } else {

                        $fecha_prestamo = date('Y-m-d H:i:s');

                        // Transacción: si insertar el préstamo funciona
                        // pero marcar el libro como prestado falla (o
                        // viceversa), NINGUNA de las dos operaciones
                        // queda guardada -> evita datos inconsistentes.
                        $pdo->beginTransaction();
                        insertarPrestamo($pdo, $documento, $id_libro, $fecha_prestamo, $fecha_limite);
                        marcarLibroComoPrestado($pdo, $id_libro);
                        $pdo->commit();

                        // htmlspecialchars() convierte caracteres HTML
                        // especiales a texto plano, para prevenir que
                        // datos del usuario se interpreten como código
                        $mensaje = "✅ Libro prestado exitosamente a <strong>" . htmlspecialchars($usuario['nombre']) . "</strong><br>" .
                            "Fecha límite: <strong>$fecha_limite</strong>";
                        $tipo_mensaje = "success";
                    }
                }
            } catch (PDOException $e) {
                // Si algo falla dentro del try, deshacer todo lo
                // que la transacción alcanzó a hacer
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensaje = "❌ Error al procesar el préstamo: " . $e->getMessage();
                $tipo_mensaje = "danger";
            }
        }

        // ------------------------------------------------------------
        // ACCIÓN: devolver un libro
        // ------------------------------------------------------------
    } elseif ($accion == 'devolver') {

        $id_prestamo = trim($_POST['id_prestamo'] ?? '');

        if (empty($id_prestamo)) {
            $mensaje = "⚠️ Préstamo no válido.";
            $tipo_mensaje = "danger";
        } else {
            try {
                // Confirmar que el préstamo existe y sigue activo
                $prestamo = obtenerPrestamoActivoPorId($pdo, $id_prestamo);

                if (!$prestamo) {
                    $mensaje = "❌ El préstamo no existe o ya fue devuelto.";
                    $tipo_mensaje = "danger";
                } else {

                    $fecha_devol = date('Y-m-d H:i:s');
                    $hoy         = date('Y-m-d');

                    // ------------------------------------------------
                    // CÁLCULO AUTOMÁTICO DE DÍAS DE ATRASO
                    // strtotime() convierte una fecha de texto a un
                    // número (segundos desde 1970), para poder restar
                    // fechas como si fueran números.
                    // Dividir esa diferencia entre 86400 (segundos
                    // que tiene un día) da el resultado en días.
                    // ------------------------------------------------
                    $diferencia  = (strtotime($hoy) - strtotime($prestamo['fecha_limite'])) / 86400;

                    // Si la diferencia es positiva, hubo atraso; si es
                    // cero o negativa (devolvió a tiempo o antes), 0.
                    // (int) redondea hacia abajo y quita decimales.
                    $dias_atraso = $diferencia > 0 ? (int) $diferencia : 0;

                    // Transacción: actualizar el préstamo + liberar el
                    // libro deben pasar juntas o ninguna
                    $pdo->beginTransaction();
                    registrarDevolucion($pdo, $id_prestamo, $fecha_devol, $dias_atraso);
                    marcarLibroComoDisponible($pdo, $prestamo['id_libro']);
                    $pdo->commit();

                    $mensaje = "✅ Libro devuelto exitosamente.";
                    if ($dias_atraso > 0) {
                        $mensaje .= " Se registraron <strong>$dias_atraso</strong> día(s) de atraso.";
                    }
                    $tipo_mensaje = "success";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensaje = "❌ Error al procesar la devolución: " . $e->getMessage();
                $tipo_mensaje = "danger";
            }
        }
    }
}


// ================================================================
// CARGA DE DATOS
// ----------------------------------------------------------------
// Esto va FUERA del bloque "if POST" a propósito: tiene que
// ejecutarse siempre, tanto si acabas de enviar un formulario
// como si solo estás entrando a ver la página por primera vez.
// ================================================================
$libros_disponibles = obtenerLibrosDisponibles($pdo);
$prestamos_activos  = obtenerPrestamosActivos($pdo);
$usuarios = obtenerUsuarios($pdo);

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Biblioteca</title>
    <!-- Bootstrap local, sin depender de internet -->
    <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <!-- Estilos propios del proyecto -->
    <link href="css/style.css?v=2" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="overlay">
        <div class="container py-4">

            <h1 class="text-center mb-4 titulo-principal">📚 Sistema de Biblioteca</h1>

            <!-- ==========================================================
             MENÚ DE NAVEGACIÓN
             Cada módulo es ahora su propia página, así que el link
             "activo" se marca directo, sin comparar con $vista.
        =========================================================== -->
            <ul class="nav nav-pills justify-content-center mb-4">
                <li class="nav-item">
                    <a class="nav-link active" href="index.php">📖 Préstamos</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="modulo/categorias.php">🏷️ Categorías</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="modulo/libros.php">📚 Libros</a>
                </li>
            </ul>

            <!-- ==========================================================
             MÓDULO: PRÉSTAMOS
             Contiene el formulario para registrar préstamos,
             la tabla de libros disponibles y la tabla de préstamos activos.
        =========================================================== -->
            <div class="row"><!-- row de Bootstrap que organiza el contenido en columnas -->

                <!-- Formulario para registrar un nuevo préstamo -->
                <div class="col-lg-6 mb-4"><!-- Columna de 6 espacios en pantallas grandes -->
                    <div class="card card-biblioteca"><!-- Tarjeta que contiene el formulario -->
                        <div class="card-header-biblioteca">📖 Registrar Préstamo</div><!-- Encabezado de la tarjeta -->
                        <div class="card-body"><!-- Cuerpo de la tarjeta -->

                            <?php if ($mensaje != ''): ?><!-- Si existe un mensaje, mostrarlo -->
                                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert"><!-- Alerta Bootstrap -->
                                    <?php echo $mensaje; ?><!-- Imprime el mensaje -->
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button><!-- Botón para cerrar la alerta -->
                                </div>
                            <?php endif; ?><!-- Fin de la validación del mensaje -->

                            <form method="POST" action="index.php"><!-- Formulario enviado por POST -->
                                <!-- Campo oculto: identifica qué acción procesar arriba -->
                                <input type="hidden" name="accion" value="prestar"><!-- Indica que se ejecutará la acción "prestar" -->

                                <div class="mb-3"><!-- Margen inferior -->
                                    <label for="documento" class="form-label fw-bold">Documento del usuario:</label><!-- Etiqueta del campo -->
                                    <input type="text" class="form-control" id="documento" name="documento" placeholder="Ej: 1234567890" required><!-- Campo para escribir el documento -->
                                </div>

                                <div class="mb-3">
                                    <label for="id_libro" class="form-label fw-bold">Libro:</label><!-- Etiqueta del select -->
                                    <select class="form-select" id="id_libro" name="id_libro" required><!-- Lista desplegable -->
                                        <option value="">-- Seleccione un libro --</option><!-- Opción por defecto -->
                                        <?php foreach ($libros_disponibles as $l): ?><!-- Recorre todos los libros disponibles -->
                                            <option value="<?php echo $l['id_libro']; ?>"><!-- Valor enviado será el ID del libro -->
                                                <?php echo htmlspecialchars($l['nombre']) . " (" . htmlspecialchars($l['nombre_categoria']) . ")"; ?><!-- Muestra nombre y categoría -->
                                            </option>
                                        <?php endforeach; ?><!-- Fin del foreach -->
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="fecha_limite" class="form-label fw-bold">Fecha límite de devolución:</label><!-- Etiqueta -->
                                    <!-- min="" impide seleccionar una fecha pasada desde el calendario -->
                                    <input type="datetime-local" class="form-control" id="fecha_limite" name="fecha_limite" min="<?php echo date('Y-m-d\TH:i'); ?>" required><!-- Campo para seleccionar fecha y hora -->
                                </div>

                                <button type="submit" class="btn btn-biblioteca w-100">Prestar libro</button><!-- Envía el formulario -->
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Tabla de libros disponibles -->
                <div class="col-lg-6 mb-4"><!-- Segunda columna -->
                    <div class="card card-biblioteca"><!-- Tarjeta -->
                        <div class="card-header-biblioteca">✅ Libros Disponibles</div><!-- Título -->
                        <div class="card-body p-2"><!-- Cuerpo -->
                            <table class="table table-striped table-hover mb-0 tabla-biblioteca"><!-- Tabla Bootstrap -->
                                <thead>
                                    <tr>
                                        <th>Libro</th><!-- Columna nombre -->
                                        <th>Categoría</th><!-- Columna categoría -->
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($libros_disponibles) == 0): ?><!-- Si no existen libros -->
                                        <tr>
                                            <td colspan="2" class="text-center text-muted">No hay libros disponibles</td><!-- Mensaje -->
                                        </tr>
                                        <?php else: foreach ($libros_disponibles as $l): ?><!-- Recorre cada libro -->
                                            <tr>
                                                <td><?php echo htmlspecialchars($l['nombre']); ?></td><!-- Nombre del libro -->
                                                <td><?php echo htmlspecialchars($l['nombre_categoria']); ?></td><!-- Categoría -->
                                            </tr>
                                    <?php endforeach;
                                    endif; ?><!-- Fin del if -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tabla de préstamos activos, con botón de devolución -->
                <div class="col-lg-12 mb-4"><!-- Columna completa -->
                    <div class="card card-biblioteca">
                        <div class="card-header-biblioteca">🔄 Préstamos Activos</div><!-- Título -->
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover mb-0 tabla-biblioteca"><!-- Tabla -->
                                <thead>
                                    <tr>
                                        <th>Usuario</th><!-- Nombre del usuario -->
                                        <th>Libro</th><!-- Libro prestado -->
                                        <th>Fecha límite</th><!-- Fecha límite -->
                                        <th>retraso</th><!-- Tiempo de retraso -->
                                        <th></th><!-- Columna para el botón -->
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($prestamos_activos) == 0): ?><!-- Si no hay préstamos -->
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No hay préstamos activos</td>
                                        </tr>
                                        <?php else: foreach ($prestamos_activos as $p): ?><!-- Recorre todos los préstamos -->
                                            <tr>
                                                <td><?php echo htmlspecialchars($p['usuario']); ?></td><!-- Usuario -->
                                                <td><?php echo htmlspecialchars($p['libro']); ?></td><!-- Libro -->
                                                <td><?php echo htmlspecialchars($p['fecha_limite']); ?></td><!-- Fecha límite -->
                                                <td>
                                                    <?php
                                                    $ahora = new DateTime(); // Obtiene la fecha y hora actual del servidor
                                                    $fechaLimite = new DateTime($p['fecha_limite']); // Convierte la fecha límite en objeto DateTime

                                                    if ($ahora > $fechaLimite) { // Comprueba si existe retraso

                                                        $intervalo = $fechaLimite->diff($ahora); // Calcula la diferencia entre ambas fechas

                                                        echo "<span class='badge bg-danger'>"; // Muestra una insignia roja

                                                        if ($intervalo->days > 0) { // Si pasó al menos un día
                                                            echo $intervalo->days . " día(s) "; // Muestra la cantidad de días
                                                        }

                                                        echo $intervalo->h . " h " . $intervalo->i . " min"; // Muestra horas y minutos de retraso

                                                        echo "</span>";
                                                    } else { // Si aún está dentro del tiempo permitido

                                                        echo "<span class='badge bg-success'>A tiempo</span>"; // Muestra estado en verde
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <!-- Cada fila tiene su propio mini-formulario para devolver
                                                     ese préstamo específico, sin necesidad de JavaScript -->
                                                    <form method="POST" action="index.php" class="d-inline"><!-- Formulario independiente -->
                                                        <input type="hidden" name="accion" value="devolver"><!-- Acción devolver -->
                                                        <input type="hidden" name="id_prestamo" value="<?php echo $p['id_prestamo']; ?>"><!-- Envía el ID del préstamo -->
                                                        <button type="submit" class="btn btn-sm btn-success">Devolver</button><!-- Botón para devolver -->
                                                    </form>
                                                </td>
                                            </tr>
                                    <?php endforeach;
                                    endif; ?><!-- Fin del foreach e if -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- ==========================================================
                TABLA DE USUARIOS REGISTRADOS
                Esta sección muestra todos los usuarios registrados en la base de datos.
                =========================================================== -->
                <div class="col-lg-12 mb-4">
                    <!-- Tarjeta que contiene la tabla de usuarios -->
                    <div class="card card-biblioteca">
                        <!-- Encabezado de la tarjeta -->
                        <div class="card-header-biblioteca">👤 Usuarios Registrados</div>

                        <!-- Cuerpo de la tarjeta donde estará la tabla -->
                        <div class="card-body p-2">
                            <!-- Tabla con estilos de Bootstrap -->
                            <table class="table table-striped table-hover mb-0 tabla-biblioteca">
                                <thead>
                                    <tr>
                                        <!-- Encabezados de cada columna -->
                                        <th>Documento</th>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <!-- count($usuarios) cuenta cuántos usuarios hay en el arreglo -->
                                    <!-- Si el resultado es 0 significa que no existen usuarios registrados -->
                                    <?php if (count($usuarios) == 0): ?>

                                        <tr>
                                            <!-- colspan="4" hace que la celda ocupe las 4 columnas -->
                                            <td colspan="4" class="text-center text-muted">
                                                No hay usuarios registrados.
                                            </td>
                                        </tr>

                                        <!-- Si existen usuarios entra al else -->
                                    <?php else: ?>

                                        <!-- foreach recorre el arreglo $usuarios -->
                                        <!-- En cada vuelta guarda un usuario dentro de la variable $u -->
                                        <?php foreach ($usuarios as $u): ?>

                                            <tr>
                                                <!-- htmlspecialchars() evita que caracteres especiales o código HTML sean interpretados por el navegador -->
                                                <td><?php echo htmlspecialchars($u['documento']); ?></td>
                                                <td><?php echo htmlspecialchars($u['nombre']); ?></td>
                                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                                <td><?php echo htmlspecialchars($u['estado']); ?></td>
                                            </tr>

                                            <!-- Fin del foreach -->
                                        <?php endforeach; ?>

                                        <!-- Fin del if que verifica si hay usuarios -->
                                    <?php endif; ?>

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