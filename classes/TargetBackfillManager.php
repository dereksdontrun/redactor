<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';
require_once(dirname(__FILE__) . '/OpenAIClasificador.php');

/**
 * TargetBackfillManager
 *
 * Clase independiente para completar retrospectivamente ("backfill")
 * las características target de edad y género en productos antiguos.
 *
 * Flujo general:
 *
 * 1. Lee productos pendientes desde la cola técnica:
 *    lafrips_redactor_target_backfill
 *
 * 2. Antes de procesar cada producto, comprueba su situación real actual:
 *    - si existe en lafrips_redactor_clasificador_categorias
 *    - si su estado sigue siendo "completo"
 *    - si target_edad y target_genero siguen vacíos
 *
 * 3. Si procede, obtiene la información del producto desde Prestashop:
 *    nombre, descripción, marca, precio, combinaciones, categoría principal,
 *    tipo de producto y otras categorías.
 *
 * 4. Envía esa información a OpenAIClasificador::obtenerTarget()
 *
 * 5. Si la respuesta es válida:
 *    - aplica las features target edad (id_feature = 9)
 *    - aplica las features target género (id_feature = 17)
 *    - actualiza target_edad y target_genero en la tabla
 *      lafrips_redactor_clasificador_categorias si el producto está allí
 *    - marca la cola como done
 *
 * 6. Si falla:
 *    - marca la cola como error
 *    - registra el error en log
 *
 * Notas:
 * - La cola técnica NO es la fuente de verdad funcional.
 *   La tabla funcional sigue siendo lafrips_redactor_clasificador_categorias.
 * - Esta clase puede procesar también productos que no estén en RCC,
 *   siempre que tengan datos suficientes ya aplicados en Prestashop.
 */
class TargetBackfillManager
{
    /**
     * Tabla de cola técnica del backfill.
     */
    const TABLE_BACKFILL = 'redactor_target_backfill';

    /**
     * Tabla principal del clasificador.
     */
    const TABLE_RCC = 'redactor_clasificador_categorias';

    /**
     * ID feature "Edad".
     */
    const FEATURE_EDAD = 9;

    /**
     * ID feature "Género".
     */
    const FEATURE_GENERO = 17;

    /**
     * ID feature "Tipo de producto".
     * Se usa para obtener el contexto del producto antes de clasificar target.
     */
    const FEATURE_TIPO_PRODUCTO = 8;

    /**
     * Logger del proceso.
     *
     * @var LoggerFrik
     */
    protected $logger;

    /**
     * Idioma por defecto para extraer nombres / valores de texto.
     * Español = 1.
     *
     * @var int
     */
    protected $idLang = 1;

    /**
     * Ruta del archivo de log.
     *
     * @var string
     */
    protected $logFile;

    /**
     * Constructor.
     *
     * Ruta personalizada para el log.
     * Si no se pasa, se usa una ruta por defecto dentro de /log/
     *
     * @param string|null $logFile
     */
    public function __construct($logFile = null)
    {
        $this->logFile = $logFile
            ? $logFile
            : _PS_ROOT_DIR_ . '/modules/redactor/log/target_backfill_' . date('Y-m-d') . '.log';

        $this->logger = new LoggerFrik($this->logFile);

        $this->log('=== Inicio de instancia TargetBackfillManager ===', 'INFO', false);
    }

    /**
     * Ejecuta un lote del backfill de target.
     *
     * Flujo:
     * - libera bloqueados antiguos
     * - inserta nuevos productos en cola si no estaban
     * - recalcula prioridades
     * - selecciona solo productos pendientes/error que ahora sean vendibles
     * - procesa hasta agotar límite o tiempo
     *
     * @param int $limit
     * @param int $maxSeconds
     * @return int
     */
    public function run($limit = 20, $maxSeconds = 240)
    {
        $limit = (int) $limit;
        $maxSeconds = (int) $maxSeconds;

        if ($limit <= 0) {
            $limit = 20;
        }

        if ($maxSeconds <= 0) {
            $maxSeconds = 240;
        }

        $start = time();
        $processed = 0;

        $this->log('Inicio run() | limit=' . $limit . ' | maxSeconds=' . $maxSeconds, 'INFO');

        // Recuperar posibles bloqueados antiguos
        $this->releaseStaleProcessing(30);

        // Insertar nuevos productos no presentes aún en la cola
        //Por ahora no lo activamos ya que ne principio los nuevos productos tienen que pasar por el redactor, que ya lleva incluido este proceso
        // $this->seedNewProductsForTargetBackfill();

        // Recalcular prioridades de los pendientes / error
        $this->recalcularPrioridadesPendientes();

        // Obtener solo productos actualmente vendibles
        $items = $this->getNextPendingProducts($limit);

        if (empty($items)) {
            $this->log('No hay productos vendibles pendientes de procesar.', 'INFO');
            return 0;
        }

        foreach ($items as $item) {
            if ((time() - $start) >= $maxSeconds) {
                $this->log('Tiempo máximo alcanzado, se detiene el proceso.', 'INFO');
                break;
            }

            $idProduct = (int) $item['id_product'];
            $this->processProduct($idProduct);
            $processed++;
        }

        $this->log('Fin run() | procesados=' . $processed, 'INFO');

        return $processed;
    }

    /**
     * Devuelve los siguientes productos pendientes de procesar.
     *
     * La selección se hace por prioridad ascendente y luego por id_backfill.
     * Así se respetan las prioridades calculadas al insertar la cola.
     *
     * @param int $limit
     * @return array
     */
    // public function getNextPendingProducts($limit = 20)
    // {
    //     $limit = (int) $limit;

    //     $sql = '
    //         SELECT id_backfill, id_product, origen, status, prioridad
    //         FROM lafrips_' . self::TABLE_BACKFILL . '
    //         WHERE status IN ("pending", "error")
    //         ORDER BY prioridad ASC, id_backfill ASC
    //         LIMIT ' . $limit;

    //     return Db::getInstance()->executeS($sql);
    // }

    //27/04/2026 Una vez procesados todos los productos "viejos" y vendibles, para no procesar otros 70-80 mil activos pero no vendibles, cambiamos el proceso para obtener en cada ejecución los que no estén procesado y hayan recibido stock o se haya marcado permitir pedido
    /**
     * Devuelve los siguientes productos pendientes de procesar,
     * pero únicamente si ahora mismo son vendibles.
     *
     * Vendible = stock > 0 o permitir pedido (out_of_stock = 1) y activo
     *
     * @param int $limit
     * @return array
     */
    public function getNextPendingProducts($limit = 20)
    {
        $limit = (int) $limit;

        if ($limit <= 0) {
            $limit = 20;
        }

        $sql = '
            SELECT
                b.id_backfill,
                b.id_product,
                b.origen,
                b.status,
                b.prioridad
            FROM ' . _DB_PREFIX_ . self::TABLE_BACKFILL . ' b
            JOIN ' . _DB_PREFIX_ . 'product p
                ON p.id_product = b.id_product
            LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa
                ON sa.id_product = p.id_product
            AND sa.id_product_attribute = 0
            WHERE b.status IN ("pending", "error")
            AND p.active = 1
            AND (
                    IFNULL(sa.quantity, 0) > 0
                    OR IFNULL(sa.out_of_stock, 0) = 1
            )
            ORDER BY b.prioridad ASC, b.id_backfill ASC
            LIMIT ' . $limit;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Procesa un único producto.
     *
     * Lógica:
     * - marca el producto como processing
     * - comprueba la situación actual en RCC
     * - si ya está resuelto, lo marca como skipped
     * - si procede, obtiene contexto del producto y llama al clasificador
     * - aplica las features
     * - actualiza RCC si existe
     * - marca done o error
     *
     * @param int $id_product
     * @return bool
     */
    public function processProduct($id_product)
    {
        $id_product = (int) $id_product;

        if ($id_product <= 0) {
            return false;
        }

        try {
            $this->markProcessing($id_product);

            $rcc = $this->getRccStatus($id_product);

            if (empty($rcc['exists'])) {
                $this->log('Producto ' . $id_product . ': no existe en RCC, se procesa desde datos reales del producto.', 'DEBUG');
            }

            // Si el producto existe en RCC, mandan las reglas de RCC.
            if (!empty($rcc['exists'])) {
                // Si el estado ya no es completo, no tiene sentido procesarlo aquí.
                if ($rcc['estado'] !== 'completo') {
                    $this->markSkipped($id_product, 'Existe en RCC pero estado != completo');
                    $this->log('Producto ' . $id_product . ': saltado, RCC no completo.', 'INFO');
                    return false;
                }

                // Si ya tiene target resuelto, no se vuelve a llamar a OpenAI.
                if ($this->hasResolvedTargetInRcc($rcc)) {
                    $this->markSkipped($id_product, 'Target ya resuelto en RCC');
                    $this->log('Producto ' . $id_product . ': ya tiene target en RCC, no se reprocesa.', 'INFO');
                    return true;
                }
            }

            // Obtener contexto del producto tal y como está realmente en Prestashop.
            $infoTarget = $this->getInfoTargetFromProduct($id_product);

            if (!$this->hasMinimumInfoForTarget($infoTarget)) {
                throw new Exception('Información insuficiente del producto para clasificar target.');
            }

            // Llamada al clasificador IA.
            $resultadoTarget = OpenAIClasificador::obtenerTarget($infoTarget);

            // Validación mínima imprescindible de la respuesta.
            if (
                !$resultadoTarget
                || !isset($resultadoTarget['target_edad'])
                || !isset($resultadoTarget['target_genero'])
                || trim($resultadoTarget['target_edad']) === ''
                || trim($resultadoTarget['target_genero']) === ''
            ) {
                throw new Exception(
                    'Respuesta OpenAI inválida: ' .
                    json_encode($resultadoTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }

            $targetEdad = trim($resultadoTarget['target_edad']);
            $targetGenero = trim($resultadoTarget['target_genero']);

            // Aplicar features reales al producto.
            $this->applyTargetFeatures($id_product, $targetEdad, $targetGenero);

            // Si existe en RCC, persistimos también el target allí para no repetir.
            if (!empty($rcc['exists'])) {
                if (!$this->updateTargetInRcc($id_product, $targetEdad, $targetGenero)) {
                    throw new Exception('No se pudo actualizar target en RCC para el producto ' . $id_product . '.');
                }
            }

            $this->markDone($id_product, $targetEdad, $targetGenero);

            $this->log(
                'Producto ' . $id_product .
                ': Target edad = ' . $targetEdad .
                ' | Target género = ' . $targetGenero,
                'INFO'
            );

            // Log adicional si OpenAI devuelve señales útiles de diagnóstico.
            if (
                isset($resultadoTarget['afinidad_mujer']) ||
                isset($resultadoTarget['afinidad_hombre']) ||
                isset($resultadoTarget['confianza']) ||
                isset($resultadoTarget['motivo_comercial'])
            ) {
                $this->log(
                    'Producto ' . $id_product .
                    ' | Afinidad Mujer = ' . (isset($resultadoTarget['afinidad_mujer']) ? $resultadoTarget['afinidad_mujer'] : '') .
                    ' | Afinidad Hombre = ' . (isset($resultadoTarget['afinidad_hombre']) ? $resultadoTarget['afinidad_hombre'] : '') .
                    ' | Confianza = ' . (isset($resultadoTarget['confianza']) ? $resultadoTarget['confianza'] : '') .
                    ' | Motivo Comercial: ' . (isset($resultadoTarget['motivo_comercial']) ? $resultadoTarget['motivo_comercial'] : ''),
                    'DEBUG'
                );
            }

            return true;

        } catch (Exception $e) {
            $this->markError($id_product, $e->getMessage());
            $this->log('Producto ' . $id_product . ': ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Obtiene la información del producto necesaria para pasarla a OpenAI.
     *
     * Se saca desde el producto real en Prestashop, no desde RCC:
     * - nombre
     * - descripción corta limpia
     * - marca
     * - precio
     * - combinaciones
     * - categoría principal
     * - tipo de producto (feature 8)
     * - otras categorías
     *
     * @param int $id_product
     * @return array
     */
    public function getInfoTargetFromProduct($id_product)
    {
        $id_product = (int) $id_product;

        if ($id_product <= 0) {
            return array();
        }

        $sql = '
            SELECT
                pro.id_product,
                pla.name AS nombre,
                pla.description_short,
                man.name AS marca,
                pro.price AS precio_sin_iva,
                cla.name AS categoria_principal,
                attrs.atributos_combinaciones,
                tp.tipo_producto,
                cats.otras_categorias
            FROM lafrips_product pro
            JOIN lafrips_product_lang pla
                ON pla.id_product = pro.id_product
               AND pla.id_lang = ' . (int) $this->idLang . '
            LEFT JOIN lafrips_manufacturer man
                ON man.id_manufacturer = pro.id_manufacturer
            LEFT JOIN lafrips_category_lang cla
                ON cla.id_category = pro.id_category_default
               AND cla.id_lang = ' . (int) $this->idLang . '
            LEFT JOIN
            (
                SELECT
                    pat.id_product,
                    GROUP_CONCAT(DISTINCT atl.name ORDER BY atl.name SEPARATOR " | ") AS atributos_combinaciones
                FROM lafrips_product_attribute pat
                JOIN lafrips_product_attribute_combination pac
                    ON pac.id_product_attribute = pat.id_product_attribute
                JOIN lafrips_attribute_lang atl
                    ON atl.id_attribute = pac.id_attribute
                   AND atl.id_lang = ' . (int) $this->idLang . '
                GROUP BY pat.id_product
            ) attrs
                ON attrs.id_product = pro.id_product
            LEFT JOIN
            (
                SELECT
                    fp.id_product,
                    MAX(fvl.value) AS tipo_producto
                FROM lafrips_feature_product fp
                JOIN lafrips_feature_value_lang fvl
                    ON fvl.id_feature_value = fp.id_feature_value
                   AND fvl.id_lang = ' . (int) $this->idLang . '
                WHERE fp.id_feature = ' . (int) self::FEATURE_TIPO_PRODUCTO . '
                GROUP BY fp.id_product
            ) tp
                ON tp.id_product = pro.id_product
            LEFT JOIN
            (
                SELECT
                    cp.id_product,
                    GROUP_CONCAT(DISTINCT cl.name ORDER BY cl.name SEPARATOR " | ") AS otras_categorias
                FROM lafrips_category_product cp
                JOIN lafrips_product p2
                    ON p2.id_product = cp.id_product
                JOIN lafrips_category_lang cl
                    ON cl.id_category = cp.id_category
                   AND cl.id_lang = ' . (int) $this->idLang . '
                WHERE cp.id_category <> p2.id_category_default
                GROUP BY cp.id_product
            ) cats
                ON cats.id_product = pro.id_product
            WHERE pro.id_product = ' . $id_product;

        $row = Db::getInstance()->getRow($sql);

        if (empty($row)) {
            return array();
        }

        return array(
            'id_product' => (int) $row['id_product'],
            'nombre' => !empty($row['nombre']) ? trim($row['nombre']) : '',
            'descripcion' => $this->cleanHtmlForGpt($row['description_short']),
            'marca' => !empty($row['marca']) ? trim($row['marca']) : '',
            'precio' => isset($row['precio_sin_iva']) ? (float) $row['precio_sin_iva'] : 0,
            'combinaciones' => !empty($row['atributos_combinaciones']) ? trim($row['atributos_combinaciones']) : '',
            'categoria_principal' => !empty($row['categoria_principal']) ? trim($row['categoria_principal']) : '',
            'tipo_producto' => !empty($row['tipo_producto']) ? trim($row['tipo_producto']) : '',
            'otras_categorias' => !empty($row['otras_categorias']) ? trim($row['otras_categorias']) : '',
        );
    }

    /**
     * Comprueba si el contexto del producto tiene la información mínima
     * para intentar clasificar target con OpenAI.
     *
     * @param array $infoTarget
     * @return bool
     */
    protected function hasMinimumInfoForTarget(array $infoTarget)
    {
        if (empty($infoTarget)) {
            return false;
        }

        if (
            empty($infoTarget['nombre']) &&
            empty($infoTarget['descripcion']) &&
            empty($infoTarget['categoria_principal']) &&
            empty($infoTarget['tipo_producto'])
        ) {
            return false;
        }

        return true;
    }

    /**
     * Aplica las features target al producto.
     *
     * Estrategia:
     * - borra previamente las features 9 y 17 para evitar duplicados o estados viejos
     * - busca el id_feature_value por texto
     * - inserta la nueva relación con Product::addFeatureProductImport()
     *
     * @param int $id_product
     * @param string $target_edad
     * @param string $target_genero
     * @throws Exception
     */
    public function applyTargetFeatures($id_product, $target_edad, $target_genero)
    {
        $id_product = (int) $id_product;
        $target_edad = trim((string) $target_edad);
        $target_genero = trim((string) $target_genero);

        if ($id_product <= 0) {
            throw new Exception('ID de producto no válido.');
        }

        // Limpiar posibles valores previos para no duplicar.
        Db::getInstance()->delete('feature_product', 'id_product = ' . $id_product . ' AND id_feature = ' . (int) self::FEATURE_EDAD);
        Db::getInstance()->delete('feature_product', 'id_product = ' . $id_product . ' AND id_feature = ' . (int) self::FEATURE_GENERO);

        // Aplicar target edad
        if ($target_edad !== '') {
            $idFeatureValueEdad = $this->findFeatureValueIdByText(self::FEATURE_EDAD, $target_edad);

            if (!$idFeatureValueEdad) {
                throw new Exception('Target edad no válido para producto ' . $id_product . ': ' . $target_edad);
            }

            if (!Product::addFeatureProductImport($id_product, self::FEATURE_EDAD, $idFeatureValueEdad)) {
                throw new Exception('No se pudo asignar la característica Edad (' . $target_edad . ') a ' . $id_product . '.');
            }

            $this->log(
                'Producto ' . $id_product . ': Target edad aplicado -> ' . $target_edad . ' id ' . $idFeatureValueEdad,
                'INFO'
            );
        }

        // Aplicar target género
        if ($target_genero !== '') {
            $idFeatureValueGenero = $this->findFeatureValueIdByText(self::FEATURE_GENERO, $target_genero);

            if (!$idFeatureValueGenero) {
                throw new Exception('Target género no válido para producto ' . $id_product . ': ' . $target_genero);
            }

            if (!Product::addFeatureProductImport($id_product, self::FEATURE_GENERO, $idFeatureValueGenero)) {
                throw new Exception('No se pudo asignar la característica Target Género (' . $target_genero . ') a ' . $id_product . '.');
            }

            $this->log(
                'Producto ' . $id_product . ': Target género aplicado -> ' . $target_genero . ' id ' . $idFeatureValueGenero,
                'INFO'
            );
        }
    }

    /**
     * Busca el ID de una feature value por el texto visible.
     *
     * Ejemplo:
     * - feature 9, value "Adulto"
     * - feature 17, value "Unisex"
     *
     * @param int $id_feature
     * @param string $value
     * @return int
     */
    protected function findFeatureValueIdByText($id_feature, $value)
    {
        $id_feature = (int) $id_feature;
        $value = pSQL(trim((string) $value));

        if ($id_feature <= 0 || $value === '') {
            return 0;
        }

        $sql = '
            SELECT fvl.id_feature_value
            FROM lafrips_feature_value_lang fvl
            JOIN lafrips_feature_value fev
                ON fev.id_feature_value = fvl.id_feature_value
            WHERE fvl.id_lang = ' . (int) $this->idLang . '
              AND fev.id_feature = ' . $id_feature . '
              AND fvl.value = "' . $value . '"';

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * Devuelve el estado actual del producto en RCC.
     *
     * Sirve para decidir si el producto:
     * - existe o no en RCC
     * - sigue en estado completo
     * - ya tiene target resuelto
     *
     * @param int $id_product
     * @return array
     */
    protected function getRccStatus($id_product)
    {
        $id_product = (int) $id_product;

        $sql = '
            SELECT
                id_product,
                estado,
                target_edad,
                target_genero
            FROM lafrips_' . self::TABLE_RCC . '
            WHERE id_product = ' . $id_product;

        $row = Db::getInstance()->getRow($sql);

        if (empty($row)) {
            return array(
                'exists' => false,
                'estado' => '',
                'target_edad' => '',
                'target_genero' => '',
            );
        }

        return array(
            'exists' => true,
            'estado' => isset($row['estado']) ? (string) $row['estado'] : '',
            'target_edad' => isset($row['target_edad']) ? trim((string) $row['target_edad']) : '',
            'target_genero' => isset($row['target_genero']) ? trim((string) $row['target_genero']) : '',
        );
    }

    /**
     * Indica si en RCC ya están resueltos ambos targets.
     *
     * @param array $rcc
     * @return bool
     */
    protected function hasResolvedTargetInRcc(array $rcc)
    {
        return (
            !empty($rcc['target_edad'])
            && !empty($rcc['target_genero'])
        );
    }

    /**
     * Actualiza en RCC los campos target_edad y target_genero.
     *
     * Esto evita que el producto vuelva a entrar como pendiente
     * en futuros procesos.
     *
     * @param int $id_product
     * @param string $target_edad
     * @param string $target_genero
     * @return bool
     */
    protected function updateTargetInRcc($id_product, $target_edad, $target_genero)
    {
        $id_product = (int) $id_product;

        $data = array(
            'target_edad' => pSQL($target_edad),
            'target_genero' => pSQL($target_genero),
        );

        return Db::getInstance()->update(
            self::TABLE_RCC,
            $data,
            'id_product = ' . $id_product
        );
    }

    /**
     * Marca un producto como processing en la cola.
     *
     * También incrementa el contador de intentos
     * y deja un locked_at para poder recuperar bloqueados.
     *
     * @param int $id_product
     */
    protected function markProcessing($id_product)
    {
        $id_product = (int) $id_product;

        $sql = '
            UPDATE lafrips_' . self::TABLE_BACKFILL . '
            SET
                status = "processing",
                locked_at = NOW(),
                updated_at = NOW(),
                intento_count = intento_count + 1
            WHERE id_product = ' . $id_product;

        Db::getInstance()->execute($sql);
    }

    /**
     * Marca un producto como done en la cola.
     *
     * Guarda también los valores resultantes por trazabilidad.
     *
     * @param int $id_product
     * @param string $target_edad
     * @param string $target_genero
     */
    protected function markDone($id_product, $target_edad, $target_genero)
    {
        $id_product = (int) $id_product;

        Db::getInstance()->update(
            self::TABLE_BACKFILL,
            array(
                'status' => 'done',
                'target_edad' => pSQL($target_edad),
                'target_genero' => pSQL($target_genero),
                'last_error' => null,
                'processed_at' => date('Y-m-d H:i:s'),
                'locked_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ),
            'id_product = ' . $id_product
        );
    }

    /**
     * Marca un producto como skipped.
     *
     * Se usa cuando el producto ya no debe procesarse:
     * - porque ya se resolvió por otro proceso
     * - porque RCC ya no está en estado adecuado
     *
     * @param int $id_product
     * @param string $reason
     */
    protected function markSkipped($id_product, $reason)
    {
        $id_product = (int) $id_product;

        Db::getInstance()->update(
            self::TABLE_BACKFILL,
            array(
                'status' => 'skipped',
                'last_error' => pSQL($reason),
                'processed_at' => date('Y-m-d H:i:s'),
                'locked_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ),
            'id_product = ' . $id_product
        );
    }

    /**
     * Marca un producto como error.
     *
     * @param int $id_product
     * @param string $error
     */
    protected function markError($id_product, $error)
    {
        $id_product = (int) $id_product;

        Db::getInstance()->update(
            self::TABLE_BACKFILL,
            array(
                'status' => 'error',
                'last_error' => pSQL($error),
                'locked_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ),
            'id_product = ' . $id_product
        );
    }

    /**
     * Libera productos que se quedaron bloqueados en processing
     * más tiempo del permitido.
     *
     * Esto evita que un corte de cron o un fatal error deje productos
     * inaccesibles indefinidamente.
     *
     * @param int $minutes
     */
    protected function releaseStaleProcessing($minutes = 30)
    {
        $minutes = (int) $minutes;

        if ($minutes <= 0) {
            $minutes = 30;
        }

        $sql = '
            UPDATE lafrips_' . self::TABLE_BACKFILL . '
            SET
                status = "pending",
                locked_at = NULL,
                updated_at = NOW()
            WHERE status = "processing"
              AND locked_at IS NOT NULL
              AND locked_at < DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)';

        Db::getInstance()->execute($sql);
    }

    /**
     * Limpia HTML de la descripción para pasarla a GPT.
     *
     * Convierte etiquetas comunes en texto útil y elimina ruido.
     *
     * @param string $html
     * @return string
     */
    protected function cleanHtmlForGpt($html)
    {
        if (empty($html)) {
            return '';
        }

        $text = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n", $text);
        $text = preg_replace('/<p[^>]*>/i', '', $text);
        $text = preg_replace('/<\/li>/i', "\n", $text);
        $text = preg_replace('/<li[^>]*>/i', '- ', $text);
        $text = strip_tags($text);
        $text = str_replace("\r", '', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        $lines = explode("\n", $text);
        $cleanLines = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $cleanLines[] = $line;
            }
        }

        return trim(implode("\n", $cleanLines));
    }

    /**
     * Wrapper interno del logger.
     *
     * Usa LoggerFrik con la firma:
     * log($mensaje, $nivel = 'INFO', $relevante = true)
     *
     * @param string $message
     * @param string $level
     * @param bool $relevante
     */
    protected function log($message, $level = 'INFO', $relevante = true)
    {
        if ($this->logger instanceof LoggerFrik) {
            $this->logger->log($message, $level, $relevante);
        }
    }

    /**
     * Recalcula la prioridad de los productos pendientes o con error
     * según su situación actual real.
     *
     * Orden:
     * 1. Activos y vendibles en RCC
     * 2. Activos y vendibles sin RCC
     * 3. Activos no vendibles en RCC
     * 4. No activos en RCC
     * 5. Activos no vendibles sin RCC
     * 6. No activos sin RCC
     *
     * Se recalcula solo para status pending o error.
     *
     * @return bool
     */

    public function recalcularPrioridadesPendientes()
    {
        $sql = '
            UPDATE lafrips_' . self::TABLE_BACKFILL . ' b
            JOIN lafrips_product p
                ON p.id_product = b.id_product
            LEFT JOIN lafrips_redactor_clasificador_categorias rcc
                ON rcc.id_product = b.id_product
            LEFT JOIN lafrips_stock_available sa
                ON sa.id_product = p.id_product
            AND sa.id_product_attribute = 0
            SET
                b.prioridad = CASE
                    WHEN rcc.id_product IS NOT NULL
                        AND p.active = 1
                        AND (IFNULL(sa.quantity, 0) > 0 OR IFNULL(sa.out_of_stock, 0) = 1)
                        THEN 1000000 + b.id_product

                    WHEN rcc.id_product IS NULL
                        AND p.active = 1
                        AND (IFNULL(sa.quantity, 0) > 0 OR IFNULL(sa.out_of_stock, 0) = 1)
                        THEN 2000000 + b.id_product

                    WHEN rcc.id_product IS NOT NULL
                        AND p.active = 1
                        THEN 3000000 + b.id_product

                    WHEN rcc.id_product IS NOT NULL
                        AND p.active = 0
                        THEN 4000000 + b.id_product

                    WHEN rcc.id_product IS NULL
                        AND p.active = 1
                        THEN 5000000 + b.id_product

                    ELSE 6000000 + b.id_product
                END,
                b.updated_at = NOW()
            WHERE b.status IN ("pending", "error")
        ';

        $ok = Db::getInstance()->execute($sql);

        if ($ok) {
            $this->log('Recalculadas correctamente las prioridades de productos pending/error.', 'INFO');
        } else {
            $this->log(
                'Error al recalcular prioridades de productos pending/error. SQL error: ' . Db::getInstance()->getMsgError(),
                'ERROR'
            );
        }

        return (bool) $ok;
    }


    /**
     * Recalcula la prioridad de los productos pendientes o con error
     * según su situación actual real. PRIORIZA LOS PRODUCTOS CON REFERENCIA QUE COMIENCE POR XXX (HAR POR DEFECTO)
     *
     * Orden:
     * 1. Activos y vendibles en RCC con referencia HAR%
     * 2. Activos y vendibles en RCC sin HAR%
     * 3. Activos y vendibles sin RCC con HAR%
     * 4. Activos y vendibles sin RCC sin HAR%
     * 5. Activos no vendibles en RCC con HAR%
     * 6. Activos no vendibles en RCC sin HAR%
     * 7. No activos en RCC con HAR%
     * 8. No activos en RCC sin HAR%
     * 9. Activos no vendibles sin RCC con HAR%
     * 10. Activos no vendibles sin RCC sin HAR%
     * 11. No activos sin RCC con HAR%
     * 12. No activos sin RCC sin HAR%
     *
     * Solo recalcula para status pending o error.
     *
     * @return bool
     */
    // public function recalcularPrioridadesPendientes()
    // {
    //     $sql = '
    //         UPDATE ' . _DB_PREFIX_ . self::TABLE_BACKFILL . ' b
    //         JOIN ' . _DB_PREFIX_ . 'product p
    //             ON p.id_product = b.id_product
    //         LEFT JOIN ' . _DB_PREFIX_ . self::TABLE_RCC . ' rcc
    //             ON rcc.id_product = b.id_product
    //         LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa
    //             ON sa.id_product = p.id_product
    //         AND sa.id_product_attribute = 0
    //         SET
    //             b.prioridad = CASE
    //                 WHEN rcc.id_product IS NOT NULL
    //                     AND p.active = 1
    //                     AND (IFNULL(sa.quantity, 0) > 0 OR IFNULL(sa.out_of_stock, 0) = 1)
    //                     AND p.reference LIKE "HAR%"
    //                     THEN 1000000 + b.id_product

    //                 WHEN rcc.id_product IS NOT NULL
    //                     AND p.active = 1
    //                     AND (IFNULL(sa.quantity, 0) > 0 OR IFNULL(sa.out_of_stock, 0) = 1)
    //                     THEN 2000000 + b.id_product

    //                 WHEN rcc.id_product IS NULL
    //                     AND p.active = 1
    //                     AND (IFNULL(sa.quantity, 0) > 0 OR IFNULL(sa.out_of_stock, 0) = 1)
    //                     AND p.reference LIKE "HAR%"
    //                     THEN 3000000 + b.id_product

    //                 WHEN rcc.id_product IS NULL
    //                     AND p.active = 1
    //                     AND (IFNULL(sa.quantity, 0) > 0 OR IFNULL(sa.out_of_stock, 0) = 1)
    //                     THEN 4000000 + b.id_product

    //                 WHEN rcc.id_product IS NOT NULL
    //                     AND p.active = 1
    //                     AND p.reference LIKE "HAR%"
    //                     THEN 5000000 + b.id_product

    //                 WHEN rcc.id_product IS NOT NULL
    //                     AND p.active = 1
    //                     THEN 6000000 + b.id_product

    //                 WHEN rcc.id_product IS NOT NULL
    //                     AND p.active = 0
    //                     AND p.reference LIKE "HAR%"
    //                     THEN 7000000 + b.id_product

    //                 WHEN rcc.id_product IS NOT NULL
    //                     AND p.active = 0
    //                     THEN 8000000 + b.id_product

    //                 WHEN rcc.id_product IS NULL
    //                     AND p.active = 1
    //                     AND p.reference LIKE "HAR%"
    //                     THEN 9000000 + b.id_product

    //                 WHEN rcc.id_product IS NULL
    //                     AND p.active = 1
    //                     THEN 10000000 + b.id_product

    //                 WHEN p.reference LIKE "HAR%"
    //                     THEN 11000000 + b.id_product

    //                 ELSE 12000000 + b.id_product
    //             END,
    //             b.updated_at = NOW()
    //         WHERE b.status IN ("pending", "error")
    //     ';

    //     $ok = Db::getInstance()->execute($sql);

    //     if ($ok) {
    //         $this->log('Recalculadas correctamente las prioridades de productos pending/error.', 'INFO');
    //     } else {
    //         $this->log(
    //             'Error al recalcular prioridades de productos pending/error. SQL error: ' . Db::getInstance()->getMsgError(),
    //             'ERROR'
    //         );
    //     }

    //     return (bool) $ok;
    // }


    //esta función recogerá los nuevos productos en prestashop que no estén en la tabla backfill para procesarlos, pero en principio no la voy a activar ya que los vamos procesando en el proceso de Redactor con lo que ya deberían tener sus datos
    /**
     * Inserta en la cola productos nuevos que todavía no estén en la tabla
     * de backfill.
     *
     * No importa si son vendibles o no en este momento: se insertan una sola vez
     * y luego getNextPendingProducts() decidirá si ahora son procesables.
     *
     * @return bool
     */
    public function seedNewProductsForTargetBackfill()
    {
        $sql = '
            INSERT IGNORE INTO ' . _DB_PREFIX_ . self::TABLE_BACKFILL . ' (
                id_product,
                origen,
                status,
                prioridad,
                vendible_al_insertar,
                active_al_insertar,
                stock_al_insertar,
                allow_orders_al_insertar,
                created_at,
                updated_at
            )
            SELECT
                p.id_product,
                CASE
                    WHEN rcc.id_product IS NOT NULL THEN "rcc_pendiente"
                    ELSE "sin_rcc"
                END AS origen,
                "pending" AS status,
                CASE
                    WHEN rcc.id_product IS NOT NULL
                        AND p.active = 1
                        AND (IFNULL(sa.quantity, 0) > 0 OR IFNULL(sa.out_of_stock, 0) = 1)
                        THEN 1000000 + p.id_product

                    WHEN rcc.id_product IS NULL
                        AND p.active = 1
                        AND (IFNULL(sa.quantity, 0) > 0 OR IFNULL(sa.out_of_stock, 0) = 1)
                        THEN 2000000 + p.id_product

                    WHEN rcc.id_product IS NOT NULL
                        AND p.active = 1
                        THEN 3000000 + p.id_product

                    WHEN rcc.id_product IS NOT NULL
                        AND p.active = 0
                        THEN 4000000 + p.id_product

                    WHEN rcc.id_product IS NULL
                        AND p.active = 1
                        THEN 5000000 + p.id_product

                    ELSE 6000000 + p.id_product
                END AS prioridad,
                CASE
                    WHEN IFNULL(sa.quantity, 0) > 0 OR IFNULL(sa.out_of_stock, 0) = 1
                        THEN 1
                    ELSE 0
                END AS vendible_al_insertar,
                p.active AS active_al_insertar,
                IFNULL(sa.quantity, 0) AS stock_al_insertar,
                IFNULL(sa.out_of_stock, 0) AS allow_orders_al_insertar,
                NOW(),
                NOW()
            FROM ' . _DB_PREFIX_ . 'product p
            LEFT JOIN ' . _DB_PREFIX_ . self::TABLE_RCC . ' rcc
                ON rcc.id_product = p.id_product
            LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa
                ON sa.id_product = p.id_product
            AND sa.id_product_attribute = 0
            LEFT JOIN ' . _DB_PREFIX_ . self::TABLE_BACKFILL . ' b
                ON b.id_product = p.id_product
            WHERE b.id_product IS NULL
        ';

        $ok = Db::getInstance()->execute($sql);

        if ($ok) {
            $this->log('Seed de nuevos productos para target backfill ejecutado correctamente.', 'INFO');
        } else {
            $this->log(
                'Error en seedNewProductsForTargetBackfill(): ' . Db::getInstance()->getMsgError(),
                'ERROR'
            );
        }

        return (bool) $ok;
    }
}