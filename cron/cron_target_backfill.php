<?php

/**
 * Cron para procesar el backfill de target edad/género.
 * 
 * https://lafrikileria.com/modules/redactor/cron/cron_target_backfill.php?token=5FdW5oVFDP2mOrMH52rTqCraVVIL1lRI&limit=20&max_seconds=300
 */

// --------------------------------------------------
// 1. Configuración básica
// --------------------------------------------------

@ini_set('memory_limit', '512M');
@set_time_limit(0);

$cronToken = '5FdW5oVFDP2mOrMH52rTqCraVVIL1lRI';

// --------------------------------------------------
// 2. Seguridad básica para acceso web
// --------------------------------------------------

if (php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($token !== $cronToken) {
        header('HTTP/1.1 403 Forbidden');
        exit('Acceso no autorizado');
    }
}

// --------------------------------------------------
// 3. Cargar Prestashop
// --------------------------------------------------

require_once dirname(__FILE__) . '/../../../config/config.inc.php';
require_once dirname(__FILE__) . '/../../../init.php';

// --------------------------------------------------
// 4. Cargar clase del backfill
// --------------------------------------------------

require_once dirname(__FILE__) . '/../classes/TargetBackfillManager.php';

// --------------------------------------------------
// 5. Parámetros opcionales
// --------------------------------------------------

// Puedes pasarlos por CLI o por GET si quieres flexibilidad
$limit = 10;
$maxSeconds = 240;

if (php_sapi_name() === 'cli') {
    global $argv;

    if (isset($argv[1]) && (int) $argv[1] > 0) {
        $limit = (int) $argv[1];
    }

    if (isset($argv[2]) && (int) $argv[2] > 0) {
        $maxSeconds = (int) $argv[2];
    }
} else {
    if (isset($_GET['limit']) && (int) $_GET['limit'] > 0) {
        $limit = (int) $_GET['limit'];
    }

    if (isset($_GET['max_seconds']) && (int) $_GET['max_seconds'] > 0) {
        $maxSeconds = (int) $_GET['max_seconds'];
    }
}

// --------------------------------------------------
// 6. Ejecutar
// --------------------------------------------------

echo "====================================<br>";
echo "CRON TARGET BACKFILL<br>";
echo "Fecha: " . date('Y-m-d H:i:s') . "<br>";
echo "Limit: " . (int) $limit . "<br>";
echo "MaxSeconds: " . (int) $maxSeconds . "<br>";
echo "====================================<br>";

try {
    $manager = new TargetBackfillManager();
    $processed = $manager->run($limit, $maxSeconds);

    echo "Procesados: " . (int) $processed . "<br>";
    echo "Fin correcto<br>";
    exit(0);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "<br>";
    exit(1);
}