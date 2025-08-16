<?php
// admin/students.php (Versión Definitiva con Modales Restaurados)
$page_title = 'Gestión de Estudiantes';
$page_specific_js = 'js/students.js';
include 'includes/header.php'; // Solo se incluye el header, que ya debería incluir config.php y database.php
// Incluir logger.php si no está ya en el header.php
if (file_exists(__DIR__ . '/../includes/logger.php')) {
    require_once __DIR__ . '/../includes/logger.php';
} else {
    error_log('Advertencia CRÍTICA: logger.php no encontrado desde students.php');
}


// CORRECCIÓN: Se procesa la eliminación aquí, no en un handler AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $student_id_to_delete = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);

    if ($student_id_to_delete) {
        try {
            // Obtener datos del estudiante ANTES de eliminar para el log
            $stmt_get = $pdo->prepare("SELECT name, identification FROM students WHERE id = ?");
            $stmt_get->execute([$student_id_to_delete]);
            $student_data_for_log = $stmt_get->fetch(PDO::FETCH_ASSOC);

            $stmt_delete = $pdo->prepare("DELETE FROM students WHERE id = ?");
            $stmt_delete->execute([$student_id_to_delete]);

            if ($stmt_delete->rowCount() > 0) {
                $_SESSION['notification'] = ['type' => 'success', 'message' => 'Estudiante eliminado correctamente.'];
                // Registrar actividad
                if (function_exists('log_activity') && $student_data_for_log) {
                    $current_admin_id_for_log = $_SESSION['admin_id'] ?? null;
                    $log_details_delete = "Estudiante eliminado: Nombre: {$student_data_for_log['name']}, ID SIS: {$student_id_to_delete}, Identificación: {$student_data_for_log['identification']}.";
                    log_activity($pdo, $current_admin_id_for_log, 'estudiante_eliminado', $student_id_to_delete, 'students', $log_details_delete);
                }
            } else {
                $_SESSION['notification'] = ['type' => 'warning', 'message' => 'No se encontró el estudiante para eliminar o ya fue eliminado.'];
            }
        } catch (PDOException $e) {
            error_log("Error al eliminar estudiante (students.php): " . $e->getMessage());
            $_SESSION['notification'] = ['type' => 'danger', 'message' => 'Error de base de datos al eliminar el estudiante. Verifique si tiene certificados asociados.'];
        }
    } else {
        $_SESSION['notification'] = ['type' => 'danger', 'message' => 'ID de estudiante no válido para eliminar.'];
    }
    header("Location: students.php");
    exit;
}

$notification = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}
$search_term = $_GET['search'] ?? '';
$sql = "SELECT id, name, identification, phone, email FROM students";
if (!empty($search_term)) {
    $sql .= " WHERE name LIKE ? OR identification LIKE ?";
}
$sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($sql);
$params = !empty($search_term) ? ["%$search_term%", "%$search_term%"] : [];
$stmt->execute($params);
$students = $stmt->fetchAll();
?>

<h1 class="mt-4">Gestión de Estudiantes</h1>
<p>Administra la base de datos de estudiantes.</p>

<?php if ($notification): ?>
<div class="alert alert-<?php echo htmlspecialchars($notification['type']); ?> alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($notification['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Listado de Estudiantes</h5>
        <div>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#uploadCsvModal"><i class="fas fa-file-csv me-1"></i> Cargar CSV</button>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStudentModal"><i class="fas fa-plus me-1"></i> Agregar Estudiante</button>
        </div>
    </div>
    <div class="card-body">
        <form action="students.php" method="GET" class="mb-3">
            <div class="input-group">
                <input type="text" name="search" class="form-control" placeholder="Buscar por nombre o identificación..." value="<?php echo htmlspecialchars($search_term); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                 <thead class="table-dark"><tr><th>Nombre</th><th>Identificación</th><th>Teléfono</th><th>Email</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr><td colspan="5" class="text-center">No se encontraron estudiantes.</td></tr>
                    <?php else: foreach ($students as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                            <td><?php echo htmlspecialchars($student['identification']); ?></td>
                            <td><?php echo htmlspecialchars($student['phone']); ?></td>
                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                            <td class="text-end">
                                <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#editStudentModal" data-id="<?php echo $student['id']; ?>"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal" data-id="<?php echo $student['id']; ?>" data-name="<?php echo htmlspecialchars($student['name']); ?>"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODALES CON SU CONTENIDO COMPLETO Y RESTAURADO -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Agregar Nuevo Estudiante</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="addStudentForm" action="ajax_student_handler.php" method="POST">
            <div class="modal-body"><input type="hidden" name="action" value="add_student"><div class="mb-3"><label class="form-label">Nombre</label><input type="text" class="form-control" name="name" required></div><div class="mb-3"><label class="form-label">Identificación</label><input type="text" class="form-control" name="identification" required></div><div class="mb-3"><label class="form-label">Teléfono</label><input type="tel" class="form-control" name="phone"></div><div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email"></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
        </form>
    </div></div>
</div>
<div class="modal fade" id="editStudentModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Editar Estudiante</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="editStudentForm" action="ajax_student_handler.php" method="POST">
            <div class="modal-body"><input type="hidden" name="action" value="update_student"><input type="hidden" id="edit_student_id" name="student_id"><div class="mb-3"><label class="form-label">Nombre</label><input type="text" class="form-control" id="edit_name" name="name" required></div><div class="mb-3"><label class="form-label">Identificación</label><input type="text" class="form-control" id="edit_identification" name="identification" required></div><div class="mb-3"><label class="form-label">Teléfono</label><input type="tel" class="form-control" id="edit_phone" name="phone"></div><div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" id="edit_email" name="email"></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar Cambios</button></div>
        </form>
    </div></div>
</div>
<div class="modal fade" id="uploadCsvModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Cargar desde CSV</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="uploadCsvForm" action="ajax_student_handler.php" method="POST" enctype="multipart/form-data">
            <div class="modal-body"><input type="hidden" name="action" value="upload_csv"><div class="alert alert-info"><p class="mb-1"><strong>Instrucciones:</strong></p><ul class="mb-0 small"><li>Archivo debe ser CSV.</li><li>Cabeceras: <code>nombre,identificacion,telefono,email</code></li><li>No se registrarán identificaciones duplicadas.</li></ul></div><div class="mb-3"><label class="form-label">Seleccionar archivo CSV</label><input class="form-control" type="file" name="csv_file" accept=".csv" required></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Subir y Procesar</button></div>
        </form>
    </div></div>
</div>
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Confirmar Eliminación</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">¿Estás seguro de que deseas eliminar a <strong id="student-name-to-delete"></strong>?</div>
        <div class="modal-footer">
            <form action="students.php" method="POST">
                <input type="hidden" name="action" value="delete"><input type="hidden" id="student_id_to_delete" name="student_id">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger">Sí, Eliminar</button>
            </form>
        </div>
    </div></div>
</div>

<?php include 'includes/footer.php'; ?>