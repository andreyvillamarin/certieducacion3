<?php
// admin/generate_certificate_handler.php

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
require_once ROOT_PATH . '/config.php';
require_once ROOT_PATH . '/includes/database.php';
require_once ROOT_PATH . '/includes/logger.php'; // <--- AÑADIR LOGGER
require_once ROOT_PATH . '/libs/PHPQRCode/qrlib.php';
require_once ROOT_PATH . '/libs/TCPDF/tcpdf.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['student_ids'])) {
    $_SESSION['notification'] = ['type' => 'danger', 'message' => 'No se seleccionó ningún estudiante o la solicitud es incorrecta.'];
    header('Location: certificates.php');
    exit;
}

$student_ids = $_POST['student_ids'];
$course_name = trim($_POST['course_name'] ?? 'Curso Desconocido');
$duration = trim($_POST['duration'] ?? 'N/A');
$issue_date_str = $_POST['issue_date'] ?? date('Y-m-d');
$signature_file = basename($_POST['signature_file'] ?? 'director.png');

$success_count = 0;
$error_count = 0;

$uploads_dir_abs = ROOT_PATH . '/uploads';
$certificates_generated_dir_abs = ROOT_PATH . '/certificates_generated';
$assets_img_dir_abs = ROOT_PATH . '/assets/img';
$admin_dir_abs = ROOT_PATH . '/admin';

if (!is_dir($uploads_dir_abs)) { @mkdir($uploads_dir_abs, 0775, true); }
if (!is_dir($certificates_generated_dir_abs)) { @mkdir($certificates_generated_dir_abs, 0775, true); }

foreach ($student_ids as $student_id) {
    $student_id = (int) $student_id;
    $qr_absolute_path = '';

    try {
        $stmt = $pdo->prepare("SELECT name, identification FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            error_log("Estudiante ID {$student_id} no encontrado.");
            $error_count++;
            continue;
        }

        $validation_code = 'CERT-' . strtoupper(uniqid()) . '-' . date('Y');
        $pdf_filename_only = 'certificado-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $student['identification']) . '-' . time() . '.pdf';
        
        $qr_absolute_path = $uploads_dir_abs . '/qr_' . $validation_code . '.png';
        $pdf_absolute_save_path = $certificates_generated_dir_abs . '/' . $pdf_filename_only;
        $pdf_url_path_for_db = 'certificates_generated/' . $pdf_filename_only; 
        
        QRcode::png($validation_code, $qr_absolute_path, QR_ECLEVEL_L, 3);

        $template_html_path = $admin_dir_abs . '/certificate_template.php'; 
        if (!file_exists($template_html_path)) {
            throw new Exception("Archivo de plantilla de certificado no encontrado en: {$template_html_path}");
        }
        $template_html_content = file_get_contents($template_html_path);
        
        $date = new DateTime($issue_date_str, new DateTimeZone('America/Bogota'));
        $months_es = ["", "enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
        $issue_date_formatted = $date->format('d') . " días del mes de " . $months_es[(int)$date->format('n')] . " de " . $date->format('Y');
        
        $replacements = [
            '{{student_name}}' => htmlspecialchars($student['name']),
            '{{student_identification}}' => number_format($student['identification'], 0, ',', '.'),
            '{{course_name}}' => htmlspecialchars($course_name),
            '{{duration}}' => htmlspecialchars($duration),
            '{{issue_date}}' => $issue_date_formatted,
            '{{validation_code}}' => $validation_code,
            '{{director_name}}' => '&nbsp;', 
            '{{qr_code_path}}' => $qr_absolute_path, 
            '{{signature_path}}' => $assets_img_dir_abs . '/signatures/' . $signature_file
            // No se necesita {{background_image_path_for_html}} porque el fondo se dibuja con $pdf->Image()
        ];
        
        $final_html = str_replace(array_keys($replacements), array_values($replacements), $template_html_content);

        // Usar 'L' para Landscape, y 'mm' para unidades (A4 Landscape: 297mm x 210mm)
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false); 
        
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('Comfamiliar Huila'); 
        $pdf->SetTitle('Certificado - ' . htmlspecialchars($student['name']) . ' - ' . htmlspecialchars($course_name));
        $pdf->SetSubject('Certificado de Curso');
        
        // Definir márgenes para el contenido HTML.
        // El fondo (dibujado con $pdf->Image) ignorará estos márgenes si se dibuja en coordenadas absolutas (0,0).
        $marginLeft = 15; // mm
        $marginTop = 10;  // mm
        $marginRight = 15; // mm
        $marginBottom = 10; // mm (para el auto page break)

        $pdf->SetMargins($marginLeft, $marginTop, $marginRight);
        $pdf->SetAutoPageBreak(TRUE, $marginBottom); 

        $pdf->setPrintHeader(false); 
        $pdf->setPrintFooter(false);

        $pdf->AddPage();

        // ***** DIBUJAR EL FONDO DIRECTAMENTE EN LA PÁGINA *****
        $background_image_path = $assets_img_dir_abs . '/certificado.jpg';
        if (file_exists($background_image_path)) {
            // Guardar el estado actual de AutoPageBreak
            $bMargin_backup = $pdf->getBreakMargin();
            $auto_page_break_backup = $pdf->getAutoPageBreak();
            
            $pdf->SetAutoPageBreak(false, 0); // Desactivar para dibujar el fondo completo
            
            // Dibujar la imagen de fondo para que cubra toda la página
            $pdf->Image($background_image_path, 0, 0, $pdf->getPageWidth(), $pdf->getPageHeight(), 'JPG', '', '', false, 300, '', false, false, 0, false, false, false);
            
            // Restaurar AutoPageBreak y márgenes
            $pdf->SetAutoPageBreak($auto_page_break_backup, $bMargin_backup);
            // Restaurar el puntero al inicio del ÁREA DE CONTENIDO (después de los márgenes establecidos)
            $pdf->setPageMark(); // Restaura el puntero a la posición justo después de AddPage() y SetMargins()
            // $pdf->SetXY($marginLeft, $marginTop); // Mover explícitamente a la esquina de los márgenes

        } else {
            error_log("ADVERTENCIA CRÍTICA: La imagen de fondo ('certificado.jpg') NO FUE ENCONTRADA en: " . $background_image_path);
        }
        // ****************************************************
        
        // Escribir el HTML. Ahora debería escribirse DENTRO de los márgenes definidos.
        $pdf->writeHTML($final_html, true, false, true, false, '');
        
        $pdf->Output($pdf_absolute_save_path, 'F');
        
        // Recuperar admin_id de la sesión
        $admin_id = $_SESSION['admin_id'] ?? null; // Usar null coalescing operator por si acaso

        // La columna generation_timestamp se llenará automáticamente por MySQL (DEFAULT CURRENT_TIMESTAMP)
        $stmt_insert = $pdo->prepare("INSERT INTO certificates (student_id, course_name, issue_date, validation_code, pdf_path, generated_by_user_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_insert->execute([$student_id, $course_name, $issue_date_str, $validation_code, $pdf_url_path_for_db, $admin_id]);

        $certificate_id = $pdo->lastInsertId(); // Obtener el ID del certificado recién insertado
        
        // Registrar actividad
        $log_details = "Certificado generado para estudiante: {$student['name']} (ID: {$student_id}), Curso: {$course_name}, Código: {$validation_code}";
        log_activity($pdo, $admin_id, 'certificado_creado', $certificate_id, 'certificates', $log_details);

        $success_count++;

    } catch (Exception $e) {
        $error_count++;
        error_log("Error al generar certificado para estudiante ID {$student_id}: " . $e->getMessage());
    } finally {
        if (!empty($qr_absolute_path) && file_exists($qr_absolute_path)) {
            unlink($qr_absolute_path);
        }
    }
}

$message = "Proceso completado. Certificados generados: {$success_count}. Errores: {$error_count}.";
if ($error_count > 0) {
    $message .= " Revise el log de errores del servidor para más detalles.";
}
$_SESSION['notification'] = ['type' => $error_count > 0 ? 'warning' : 'success', 'message' => $message];
header('Location: certificates.php');
exit;
?>