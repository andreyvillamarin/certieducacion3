<?php
// admin/delete_certificate.php
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__); // Asumiendo que delete_certificate.php está en /admin
}
include 'includes/header.php'; // Assumes this handles session, auth, and $pdo
require_once ROOT_PATH . '/../includes/logger.php'; // Ajustar ruta para incluir logger.php

// Ensure only admin access (assuming header.php or a session check does this)
// if (!is_admin()) {
//     header('Location: login.php'); // Or some other appropriate action
//     exit;
// }

$deleted_count = 0;
$error_count = 0;
$messages = [];

// --- Function to delete a single certificate ---
function deleteSingleCertificate($pdo, $certificate_id) {
    global $deleted_count, $error_count;

    // Validate ID
    if (!filter_var($certificate_id, FILTER_VALIDATE_INT)) {
        $error_count++;
        return "ID de certificado inválido.";
    }
    $certificate_id = intval($certificate_id);

    try {
        $pdo->beginTransaction();

        // 1. Fetch certificate details for logging and file deletion
        $stmt = $pdo->prepare("SELECT c.pdf_path, c.course_name, s.name as student_name, s.identification as student_identification
                               FROM certificates c
                               JOIN students s ON c.student_id = s.id
                               WHERE c.id = ?");
        $stmt->execute([$certificate_id]);
        $certificate_data = $stmt->fetch();

        if (!$certificate_data) {
            $pdo->rollBack();
            $error_count++;
            return "Certificado no encontrado (ID: $certificate_id).";
        }

        $pdf_file_path = dirname(__DIR__) . '/' . $certificate_data['pdf_path']; // Ajuste de ruta para que sea desde la raíz del proyecto.
                                                                             // ROOT_PATH en este contexto es /admin, así que dirname(__DIR__) es la raíz.

        // 2. Delete record from database
        $stmt_delete = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
        $delete_success = $stmt_delete->execute([$certificate_id]);

        if ($delete_success) {
            $file_deleted_successfully = false;
            $file_delete_error_message = '';

            // 3. Delete PDF file
            if (file_exists($pdf_file_path)) {
                if (unlink($pdf_file_path)) {
                    $file_deleted_successfully = true;
                } else {
                    $file_delete_error_message = "Error al eliminar el archivo PDF físico {$pdf_file_path}.";
                    // No hacemos rollback aquí, el registro de la BD ya se eliminó.
                    // Se registrará en el log de actividad y en el error_log del servidor.
                    error_log($file_delete_error_message . " para certificado ID $certificate_id.");
                }
            } else {
                // El archivo no existe, puede que ya haya sido borrado o hubo un problema antes.
                // Se considera la eliminación de la BD como la acción principal.
                $file_delete_error_message = "El archivo PDF {$pdf_file_path} no fue encontrado durante la eliminación del certificado ID $certificate_id.";
                error_log($file_delete_error_message);
            }

            $pdo->commit(); // Commit de la transacción de BD independientemente del borrado del archivo.
            $deleted_count++;

            // Registrar actividad
            $admin_id = $_SESSION['admin_id'] ?? null;
            $log_details = "Certificado eliminado: ID {$certificate_id}, Estudiante: {$certificate_data['student_name']} ({$certificate_data['student_identification']}), Curso: {$certificate_data['course_name']}.";
            if (!$file_deleted_successfully && !empty($file_delete_error_message)) {
                $log_details .= " Problema con archivo físico: " . ($file_exists($pdf_file_path) ? "no se pudo borrar." : "no encontrado.");
            }
            log_activity($pdo, $admin_id, 'certificado_eliminado', $certificate_id, 'certificates', $log_details);

            return "Certificado ID $certificate_id eliminado exitosamente de la base de datos." . ($file_delete_error_message ? " ($file_delete_error_message)" : "");

        } else {
            $pdo->rollBack();
            $error_count++;
            return "Error al eliminar el certificado ID $certificate_id de la base de datos.";
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_count++;
        error_log("Error PDO al eliminar certificado ID $certificate_id: " . $e->getMessage()); // Loguear el error real de PDO
        return "Error de base de datos al eliminar el certificado ID $certificate_id.";
    }
}

// --- Handle Single Deletion (GET request) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $certificate_id = $_GET['id'];
    $message = deleteSingleCertificate($pdo, $certificate_id);
    if ($deleted_count > 0) {
        header('Location: history.php?message=' . urlencode($message) . '&type=success');
    } else {
        header('Location: history.php?message=' . urlencode($message) . '&type=error');
    }
    exit;
}

// --- Handle Bulk Deletion (POST request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if (isset($_POST['certificate_ids']) && is_array($_POST['certificate_ids'])) {
        $certificate_ids = $_POST['certificate_ids'];
        if (empty($certificate_ids)) {
            header('Location: history.php?message=' . urlencode('No se seleccionaron certificados para eliminar.') . '&type=info');
            exit;
        }

        foreach ($certificate_ids as $id) {
            $result_message = deleteSingleCertificate($pdo, $id);
            // Store individual messages if needed, or just rely on counts
            // For simplicity, we'll just use counts for the final message
        }

        $final_message = "";
        if ($deleted_count > 0) {
            $final_message .= "$deleted_count certificado(s) eliminado(s) exitosamente. ";
        }
        if ($error_count > 0) {
            $final_message .= "$error_count error(es) al eliminar certificados.";
        }

        if ($deleted_count > 0 && $error_count == 0) {
            header('Location: history.php?message=' . urlencode($final_message) . '&type=success');
        } elseif ($deleted_count > 0 && $error_count > 0) {
            header('Location: history.php?message=' . urlencode($final_message) . '&type=warning'); // Partial success
        } elseif ($error_count > 0) {
            header('Location: history.php?message=' . urlencode($final_message ?: 'No se pudieron eliminar los certificados seleccionados.') . '&type=error');
        } else { // Should not happen if IDs were selected but nothing processed
             header('Location: history.php?message=' . urlencode('No se procesó ningún certificado.') . '&type=info');
        }
        exit;

    } else {
        header('Location: history.php?message=' . urlencode('No se seleccionaron certificados para eliminar.') . '&type=info');
        exit;
    }
}

// If accessed directly without proper parameters or method
header('Location: history.php?message=' . urlencode('Acción no válida.') . '&type=error');
exit;
?>
