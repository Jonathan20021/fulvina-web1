<?php
/**
 * Sirve una foto del anexo de una cotización. Las imágenes viven bajo /uploads,
 * que está bloqueado por .htaccess: el único acceso es por aquí y exige sesión
 * con permiso de lectura de cotizaciones.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('cotizaciones.view');

$id = (int) ($_GET['id'] ?? 0);
$attachment = $id > 0 && db(false) && table_exists('quote_attachments')
    ? fetch_one('SELECT * FROM quote_attachments WHERE id=?', [$id])
    : null;

$path = $attachment ? quote_photo_path($attachment) : null;
if ($path === null) {
    http_response_code(404);
    exit('Adjunto no encontrado.');
}

$mime = (string) ($attachment['mime'] ?? 'image/jpeg');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    $mime = 'image/jpeg';
}

// Miniatura opcional para la galería del CRM: evita mandar la foto completa.
if (isset($_GET['thumb']) && extension_loaded('gd')) {
    $img = image_load($path, $mime);
    if ($img !== null) {
        $img = image_scale_to_max($img, 420);
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        imagejpeg($img, null, 78);
        imagedestroy($img);
        exit;
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($attachment['original_name'] ?? 'foto.jpg')) . '"');
readfile($path);
exit;
