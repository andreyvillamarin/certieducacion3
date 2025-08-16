<?php
// admin/ajax_student_handler.php

// Establecer la ruta raíz del proyecto de forma robusta
// __DIR__ es el directorio del archivo actual (admin)
// dirname(__DIR__) sube un nivel a la raíz del proyecto (ej: 'certieducacion')
$project_root = dirname(__DIR__);

// Incluir config.php primero
if (file_exists($project_root . '/config.php')) {
    require_once $project_root . '/config.php';
} else {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'message' => 'Error crítico: El archivo de configuración principal (config.php) no se encuentra en la ruta esperada: ' . $project_root . '/config.php']);
    exit;
}

// Incluir database.php
if (file_exists($project_root . '/includes/database.php')) {
    require_once $project_root . '/includes/database.php';
} else {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'message' => 'Error crítico: El archivo de base de datos (database.php) no se encuentra en la ruta esperada: ' . $project_root . '/includes/database.php']);
    exit;
}

// Incluir logger.php
if (file_exists($project_root . '/includes/logger.php')) {
    require_once $project_root . '/includes/logger.php';
} else {
    // No es tan crítico como la BD, pero se podría loguear o enviar una respuesta de error si el logging es mandatorio.
    // Por ahora, solo un error_log para no interrumpir la funcionalidad principal si el logger falla.
    error_log('Advertencia: El archivo logger.php no se encuentra en la ruta esperada: ' . $project_root . '/includes/logger.php');
}


// ESTA ES LA CABECERA IMPORTANTE. Debe estar antes de CUALQUIER echo o salida.
if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
  

// ESTA ES LA CABECERA IMPORTANTE. Debe estar antes de CUALQUIER echo o salida.
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// Iniciar sesión si es necesaria y no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$current_admin_id = $_SESSION['admin_id'] ?? null; // Obtener el admin_id para el logging

// Función para unificar respuestas
function send_response($success, $message = '', $data = []) {
    // Si las cabeceras no se enviaron antes (ej. por un error de file_exists), intentarlo aquí.
    // Aunque lo ideal es que la cabecera principal ya se haya enviado.
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

// Verificar si $pdo se inicializó correctamente en database.php
if (!isset($pdo) || !$pdo instanceof PDO) {
    // No usar send_response aquí si $pdo es necesario para ella (aunque en este caso no lo es directamente)
    // Pero es mejor un error genérico si la BD no está.
    error_log('Error crítico: La conexión a la base de datos ($pdo) no está disponible en ajax_student_handler.php.');
    send_response(false, 'Error crítico: La conexión a la base de datos no está disponible.');
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add_student':
        $name = trim($_POST['name'] ?? '');
        $id_num = trim($_POST['identification'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($name) || empty($id_num)) {
            send_response(false, 'Nombre e identificación son requeridos.');
        }

        try {
            $stmt_check_duplicate = $pdo->prepare("SELECT id FROM students WHERE identification = ?");
            $stmt_check_duplicate->execute([$id_num]);
            if ($stmt_check_duplicate->fetch()) {
                send_response(false, 'La identificación ingresada ya pertenece a otro estudiante.');
            }

            $sql = "INSERT INTO students (name, identification, phone, email) VALUES (?, ?, ?, ?)";
            $params = [$name, $id_num, $phone, $email];
            $stmt_action = $pdo->prepare($sql);
            $stmt_action->execute($params);
            $new_student_id = $pdo->lastInsertId();

            // Registrar actividad
            if (function_exists('log_activity')) {
                $log_details = "Estudiante agregado: Nombre: {$name}, ID: {$id_num}.";
                log_activity($pdo, $current_admin_id, 'estudiante_creado', $new_student_id, 'students', $log_details);
            }

            send_response(true, 'Estudiante agregado con éxito.', ['id' => $new_student_id, 'name' => $name, 'identification' => $id_num, 'phone' => $phone, 'email' => $email]);

        } catch (PDOException $e) {
            error_log("Error en add_student (student): " . $e->getMessage());
            send_response(false, 'Error de base de datos al agregar estudiante.');
        }
        break;

    case 'update_student':
        $name = trim($_POST['name'] ?? '');
        $id_num = trim($_POST['identification'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

        if (empty($name) || empty($id_num)) {
            send_response(false, 'Nombre e identificación son requeridos.');
        }
        if ($student_id === 0) {
            send_response(false, 'ID de estudiante no proporcionado o no válido para actualizar.');
        }

        try {
            // Obtener datos antiguos para comparar
            $stmt_old_data = $pdo->prepare("SELECT name, identification, phone, email FROM students WHERE id = ?");
            $stmt_old_data->execute([$student_id]);
            $old_data = $stmt_old_data->fetch(PDO::FETCH_ASSOC);

            if (!$old_data) {
                send_response(false, 'Estudiante no encontrado para actualizar.');
            }

            $stmt_check_duplicate = $pdo->prepare("SELECT id FROM students WHERE identification = ? AND id != ?");
            $stmt_check_duplicate->execute([$id_num, $student_id]);
            if ($stmt_check_duplicate->fetch()) {
                send_response(false, 'La identificación ingresada ya pertenece a otro estudiante.');
            }

            $sql = "UPDATE students SET name = ?, identification = ?, phone = ?, email = ? WHERE id = ?";
            $params = [$name, $id_num, $phone, $email, $student_id];
            $stmt_action = $pdo->prepare($sql);
            $stmt_action->execute($params);

            // Registrar actividad
            if (function_exists('log_activity')) {
                $changes = [];
                if ($old_data['name'] !== $name) $changes[] = "Nombre: '{$old_data['name']}' -> '{$name}'";
                if ($old_data['identification'] !== $id_num) $changes[] = "Identificación: '{$old_data['identification']}' -> '{$id_num}'";
                if ($old_data['phone'] !== $phone) $changes[] = "Teléfono: '{$old_data['phone']}' -> '{$phone}'";
                if ($old_data['email'] !== $email) $changes[] = "Email: '{$old_data['email']}' -> '{$email}'";

                if (!empty($changes)) {
                    $log_details = "Estudiante ID {$student_id} actualizado. Cambios: " . implode(', ', $changes) . ".";
                    log_activity($pdo, $current_admin_id, 'student_updated', $student_id, 'students', $log_details);
                }
            }
            send_response(true, 'Estudiante actualizado con éxito.');

        } catch (PDOException $e) {
            error_log("Error en update_student (student): " . $e->getMessage());
            send_response(false, 'Error de base de datos al actualizar estudiante.');
        }
        break;

    case 'get_student':
        $student_id_get = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($student_id_get === 0) {
            send_response(false, 'ID de estudiante no proporcionado para get_student.');
        }
        try {
            $stmt = $pdo->prepare("SELECT id, name, identification, phone, email FROM students WHERE id = ?");
            $stmt->execute([$student_id_get]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC); 
            if ($student) {
                send_response(true, 'Estudiante encontrado.', $student); 
            } else {
                send_response(false, 'Estudiante no encontrado.');
            }
        } catch (PDOException $e) {
            error_log("Error en get_student: " . $e->getMessage()); 
            send_response(false, 'Error de base de datos al obtener estudiante.'); 
        }
        break;
    
    case 'upload_csv':
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            if (($handle = fopen($file, "r")) !== FALSE) {
                // Validar cabeceras
                $header = fgetcsv($handle, 1000, ",");
                if ($header !== ['nombre', 'identificacion', 'telefono', 'email']) {
                    fclose($handle);
                    send_response(false, 'Las cabeceras del CSV son incorrectas. Deben ser: nombre,identificacion,telefono,email');
                }

                $added = 0;
                $skipped = 0;
                $pdo->beginTransaction();
                try {
                    $stmt_check = $pdo->prepare("SELECT id FROM students WHERE identification = ?");
                    $stmt_insert = $pdo->prepare("INSERT INTO students (name, identification, phone, email) VALUES (?, ?, ?, ?)");

                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if (count($data) !== 4) { $skipped++; continue; }
                        
                        $csv_name = trim($data[0]);
                        $csv_identification = trim($data[1]);
                        $csv_phone = trim($data[2]);
                        $csv_email = trim($data[3]);

                        if (empty($csv_identification)) { $skipped++; continue; }
                        
                        $stmt_check->execute([$csv_identification]);
                        if ($stmt_check->fetch()) { $skipped++; continue; }

                        $stmt_insert->execute([$csv_name, $csv_identification, $csv_phone, $csv_email]);
                        $newly_added_student_id = $pdo->lastInsertId();
                        $added++;

                        // Registrar actividad para cada estudiante agregado por CSV
                        if (function_exists('log_activity')) {
                            $log_details_csv = "Estudiante agregado vía CSV: Nombre: {$csv_name}, ID: {$csv_identification}.";
                            log_activity($pdo, $current_admin_id, 'estudiante_creado_csv', $newly_added_student_id, 'students', $log_details_csv);
                        }
                    }
                    $pdo->commit();
                    fclose($handle);
                    send_response(true, "Proceso completado. Estudiantes agregados: $added. Duplicados/omitidos o con errores: $skipped.");
                } catch (Exception $e) {
                    $pdo->rollBack();
                    fclose($handle);
                    error_log("Error en carga CSV: " . $e->getMessage());
                    send_response(false, 'Ocurrió un error durante la carga masiva.');
                }
            } else {
                 send_response(false, 'No se pudo abrir el archivo CSV subido.');
            }
        } else {
            $upload_error = $_FILES['csv_file']['error'] ?? 'No file';
            send_response(false, 'Error al subir el archivo (código: '.$upload_error.') o archivo no seleccionado.');
        }
        break;

    default:
        send_response(false, 'Acción desconocida: ' . htmlspecialchars($action));
        break;
}

?>