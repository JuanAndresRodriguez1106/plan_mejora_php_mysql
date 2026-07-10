<?php

// ================================================================
// SECCIÓN: USUARIOS ver todo los usuarios activos
// ================================================================
function obtenerUsuarios(PDO $pdo): array
{
    // Consulta que trae documento, nombre, email y estado
    // solo de los usuarios cuyo estado sea 'activo'
    $sql = "SELECT documento, nombre, email, estado
            FROM usuarios
            WHERE estado = 'activo'
            ORDER BY nombre ASC";

    // Ejecuta la consulta directa (sin parámetros externos) y
    // devuelve TODAS las filas como un arreglo asociativo
    return $pdo->query($sql)->fetchAll();
}

// ================================================================
// SECCIÓN: LIBROS
// ================================================================

// ----------------------------------------------------------------
// Trae todos los libros que están disponibles para prestar,
// junto con el nombre de su categoría (por eso el INNER JOIN).
// Se usa tanto en el <select> del formulario de préstamo como
// en la tabla "Libros Disponibles".
// No recibe datos externos, por eso usa query() en vez de prepare().
// ----------------------------------------------------------------
function obtenerLibrosDisponibles(PDO $pdo) {
    // Selecciona id y nombre del libro, más el nombre de su categoría
    $sql = "SELECT libros.id_libro, libros.nombre, categorias.nombre_categoria
            FROM libros
            INNER JOIN categorias ON libros.id_categoria = categorias.id_categoria
            WHERE libros.estado = 'disponible'
            ORDER BY libros.nombre ASC";
    // Ejecuta y devuelve todas las filas encontradas
    return $pdo->query($sql)->fetchAll();
}

// ----------------------------------------------------------------
// Busca UN libro específico por su id, pero solo si sigue
// disponible. Se usa para validar, justo antes de prestarlo,
// que nadie más se lo haya llevado mientras el usuario llenaba
// el formulario.
// Recibe $id_libro (viene del formulario) -> usa prepare()/execute()
// para protegerse de inyección SQL.
// fetch() (sin "All") porque solo esperamos UNA fila o ninguna.
// ----------------------------------------------------------------
function obtenerLibroDisponiblePorId(PDO $pdo,  int $id_libro) {
    // Consulta con "?" como marcador de posición (parámetro seguro)
    $sql = "SELECT id_libro, nombre FROM libros WHERE id_libro = ? AND estado = 'disponible'";
    // Prepara la consulta (la deja lista, pero aún no la ejecuta)
    $stmt = $pdo->prepare($sql);
    // Ejecuta la consulta reemplazando el "?" por el valor real de $id_libro
    $stmt->execute([$id_libro]);
    // Devuelve una sola fila (o false si no encontró nada)
    return $stmt->fetch();
}

// ----------------------------------------------------------------
// Cambia el estado de un libro a 'prestado'.
// Se llama justo después de insertar el préstamo, para que ese
// libro deje de aparecer en la lista de disponibles.
// ----------------------------------------------------------------
function marcarLibroComoPrestado(PDO $pdo,  int $id_libro) {
    // Actualiza el campo "estado" solo del libro con ese id
    $sql = "UPDATE libros SET estado = 'prestado' WHERE id_libro = ?";
    $stmt = $pdo->prepare($sql);       // Prepara la consulta
    $stmt->execute([$id_libro]);       // La ejecuta pasando el id como parámetro
}

// ----------------------------------------------------------------
// Cambia el estado de un libro de vuelta a 'disponible'.
// Se llama al momento de procesar una devolución.
// ----------------------------------------------------------------
function marcarLibroComoDisponible(PDO $pdo,  int $id_libro) {
    // Actualiza el campo "estado" de vuelta a disponible
    $sql = "UPDATE libros SET estado = 'disponible' WHERE id_libro = ?";
    $stmt = $pdo->prepare($sql);       // Prepara la consulta
    $stmt->execute([$id_libro]);       // La ejecuta con el id recibido
}


// ================================================================
// SECCIÓN: USUARIOS
// ================================================================

// ----------------------------------------------------------------
// Busca un usuario por su documento, pero solo si está 'activo'.
// Un usuario inactivo (borrado lógico) no puede pedir préstamos,
// aunque su documento siga existiendo en la tabla.
// ----------------------------------------------------------------
function obtenerUsuarioActivoPorDocumento(PDO $pdo, string $documento) {
    // Busca por documento exacto y que además esté activo
    $sql = "SELECT documento, nombre FROM usuarios WHERE documento = ? AND estado = 'activo'";
    $stmt = $pdo->prepare($sql);        // Prepara la consulta
    $stmt->execute([$documento]);       // Ejecuta pasando el documento buscado
    return $stmt->fetch();              // Devuelve el usuario encontrado (o false)
}


// ================================================================
// SECCIÓN: PRESTAMOS
// ================================================================

// ----------------------------------------------------------------
// Trae todos los préstamos que siguen activos (no devueltos),
// uniendo las tres tablas relacionadas para mostrar nombres
// legibles (usuario y libro) en vez de solo ids.
// Se usa para la tabla "Préstamos Activos" en pantalla.
// ----------------------------------------------------------------
function obtenerPrestamosActivos(PDO $pdo) {
    // Trae id y fecha límite del préstamo, más el nombre del
    // usuario y del libro (gracias a los dos INNER JOIN)
    $sql = "SELECT prestamos.id_prestamo, prestamos.fecha_limite,
                    usuarios.nombre AS usuario, libros.nombre AS libro
             FROM prestamos
             INNER JOIN usuarios ON prestamos.documento = usuarios.documento
             INNER JOIN libros ON prestamos.id_libro = libros.id_libro
             WHERE prestamos.estado = 'activo'
             ORDER BY prestamos.fecha_limite ASC";
    // Ejecuta directo (no recibe parámetros externos) y trae todas las filas
    return $pdo->query($sql)->fetchAll();
}

// ----------------------------------------------------------------
// Busca UN préstamo específico, pero solo si sigue activo.
// Se usa justo antes de procesar una devolución, para confirmar
// que ese préstamo existe y no fue devuelto ya (evita errores si
// alguien hace doble clic en "Devolver", por ejemplo).
// ----------------------------------------------------------------
function obtenerPrestamoActivoPorId(PDO $pdo, int $id_prestamo) {
    // Trae los datos necesarios del préstamo, solo si está activo
    $sql = "SELECT id_prestamo, id_libro, fecha_limite
            FROM prestamos
            WHERE id_prestamo = ? AND estado = 'activo'";
    $stmt = $pdo->prepare($sql);          // Prepara la consulta
    $stmt->execute([$id_prestamo]);       // Ejecuta con el id del préstamo
    return $stmt->fetch();                // Devuelve la fila encontrada (o false)
}

// ----------------------------------------------------------------
// Inserta un nuevo préstamo en la base de datos.
// Recibe todos los datos ya calculados y validados desde index.php
// (documento, id_libro, fecha_prestamo, fecha_limite).
// ----------------------------------------------------------------
function insertarPrestamo(PDO $pdo, string $documento, int $id_libro, string $fecha_prestamo, string $fecha_limite) {
    // Inserta una fila nueva con los 4 datos del préstamo
    $sql = "INSERT INTO prestamos (documento, id_libro, fecha_prestamo, fecha_limite)
            VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);          // Prepara la consulta
    // Ejecuta reemplazando los 4 "?" en el mismo orden en que aparecen
    $stmt->execute([$documento, $id_libro, $fecha_prestamo, $fecha_limite]);
}

// ----------------------------------------------------------------
// Actualiza un préstamo para marcarlo como devuelto.
// Guarda la fecha real de devolución y los días de atraso
// (ya calculados en index.php con DATEDIFF manual).
// ----------------------------------------------------------------
function registrarDevolucion(PDO $pdo, int $id_prestamo, string $fecha_devolucion, int $dias_atraso) {
    // Actualiza fecha de devolución, días de atraso y cambia el estado a 'devuelto'
    $sql = "UPDATE prestamos
            SET fecha_devolucion = ?, dias_atraso = ?, estado = 'devuelto'
            WHERE id_prestamo = ?";
    $stmt = $pdo->prepare($sql);          // Prepara la consulta
    // Ejecuta pasando los 3 valores en el mismo orden de los "?"
    $stmt->execute([$fecha_devolucion, $dias_atraso, $id_prestamo]);
}


// ================================================================
// SECCIÓN: CATEGORIAS
// ================================================================

// ----------------------------------------------------------------
// Trae TODAS las categorías (activas E inactivas), porque esta
// función alimenta la tabla del CRUD, donde el administrador
// necesita ver también las inactivas para poder reactivarlas.
// ----------------------------------------------------------------
function obtenerCategoriasTodas(PDO $pdo) {
    // Sin WHERE de estado -> trae absolutamente todas las categorías
    $sql = "SELECT id_categoria, nombre_categoria, estado FROM categorias ORDER BY nombre_categoria ASC";
    return $pdo->query($sql)->fetchAll();   // Ejecuta y devuelve todas las filas
}

// ----------------------------------------------------------------
// Busca una categoría específica por su id.
// Se usa cuando el usuario hace clic en "Editar": carga los
// datos actuales de esa categoría para llenar el formulario.
// ----------------------------------------------------------------
function obtenerCategoriaPorId(PDO $pdo, int $id_categoria) {
    // Busca solo id y nombre de la categoría con ese id
    $sql = "SELECT id_categoria, nombre_categoria FROM categorias WHERE id_categoria = ?";
    $stmt = $pdo->prepare($sql);            // Prepara la consulta
    $stmt->execute([$id_categoria]);        // Ejecuta con el id recibido
    return $stmt->fetch();                  // Devuelve la fila encontrada (o false)
}

// ----------------------------------------------------------------
// Inserta una categoría nueva.
// Si el nombre ya existe, MySQL rechaza la operación por la
// restricción UNIQUE de la columna (el error se captura en
// index.php, no aquí).
// ----------------------------------------------------------------
function crearCategoria(PDO $pdo, string $nombre) {
    // Inserta solo el nombre; el id es autoincremental y el
    // estado toma su valor por defecto ('activo') en la BD
    $sql = "INSERT INTO categorias (nombre_categoria) VALUES (?)";
    $stmt = $pdo->prepare($sql);       // Prepara la consulta
    $stmt->execute([$nombre]);         // Ejecuta pasando el nombre
}

// ----------------------------------------------------------------
// Actualiza el nombre de una categoría existente.
// ----------------------------------------------------------------
function actualizarCategoria(PDO $pdo, int $id_categoria, string $nombre) {
    // Cambia el nombre de la categoría que tenga ese id
    $sql = "UPDATE categorias SET nombre_categoria = ? WHERE id_categoria = ?";
    $stmt = $pdo->prepare($sql);                    // Prepara la consulta
    $stmt->execute([$nombre, $id_categoria]);       // Ejecuta con nombre nuevo e id
}

// ----------------------------------------------------------------
// Cambia el estado de una categoría entre 'activo' e 'inactivo'.
// Esto es borrado lógico: nunca se hace DELETE, para no romper
// los libros que ya tengan esta categoría asignada.
// ----------------------------------------------------------------
function cambiarEstadoCategoria(PDO $pdo, int $id_categoria, string $nuevo_estado) {
    // Solo actualiza la columna "estado"
    $sql = "UPDATE categorias SET estado = ? WHERE id_categoria = ?";
    $stmt = $pdo->prepare($sql);                        // Prepara la consulta
    $stmt->execute([$nuevo_estado, $id_categoria]);     // Ejecuta con el nuevo estado y el id
}
// ================================================================
// SECCIÓN: LIBROS (CRUD)
// ================================================================

// Trae TODOS los libros (cualquier estado) con su categoría,
// para el listado del CRUD.
function obtenerLibrosTodos(PDO $pdo) {
    // Trae id, nombre, estado y categoría de CADA libro sin filtrar por estado
    $sql = "SELECT libros.id_libro, libros.nombre, libros.estado, libros.id_categoria,
                   categorias.nombre_categoria
            FROM libros
            INNER JOIN categorias ON libros.id_categoria = categorias.id_categoria
            ORDER BY libros.nombre ASC";
    return $pdo->query($sql)->fetchAll();   // Ejecuta y devuelve todas las filas
}

// Busca un libro por id, para cargarlo en modo edición o para
// validar su estado actual antes de inactivarlo.
function obtenerLibroPorId(PDO $pdo, int $id_libro) {
    // Trae los datos completos de un solo libro
    $sql = "SELECT id_libro, nombre, id_categoria, estado FROM libros WHERE id_libro = ?";
    $stmt = $pdo->prepare($sql);         // Prepara la consulta
    $stmt->execute([$id_libro]);         // Ejecuta con el id del libro
    return $stmt->fetch();               // Devuelve la fila encontrada (o false)
}

// Solo categorías activas, para el <select> del formulario
// (no tiene sentido asignar un libro a una categoría inactiva).
function obtenerCategoriasActivas(PDO $pdo) {
    // Filtra únicamente las categorías con estado 'activo'
    $sql = "SELECT id_categoria, nombre_categoria FROM categorias WHERE estado = 'activo' ORDER BY nombre_categoria ASC";
    return $pdo->query($sql)->fetchAll();   // Ejecuta y devuelve todas las filas
}

function crearLibro(PDO $pdo, string $nombre, int $id_categoria) {
    // No se manda "estado": la columna ya tiene DEFAULT 'disponible'
    $sql = "INSERT INTO libros (nombre, id_categoria) VALUES (?, ?)";
    $stmt = $pdo->prepare($sql);                      // Prepara la consulta
    $stmt->execute([$nombre, $id_categoria]);         // Ejecuta con nombre y categoría
}

function actualizarLibro(PDO $pdo, int $id_libro, string $nombre, int $id_categoria) {
    // Ojo: no toca "estado" a propósito, para no pisar si está prestado/inactivo
    $sql = "UPDATE libros SET nombre = ?, id_categoria = ? WHERE id_libro = ?";
    $stmt = $pdo->prepare($sql);                              // Prepara la consulta
    $stmt->execute([$nombre, $id_categoria, $id_libro]);      // Ejecuta con nombre, categoría e id
}

function cambiarEstadoLibro(PDO $pdo, int $id_libro, string $nuevo_estado) {
    // Solo actualiza la columna "estado" del libro
    $sql = "UPDATE libros SET estado = ? WHERE id_libro = ?";
    $stmt = $pdo->prepare($sql);                    // Prepara la consulta
    $stmt->execute([$nuevo_estado, $id_libro]);     // Ejecuta con el nuevo estado y el id
}