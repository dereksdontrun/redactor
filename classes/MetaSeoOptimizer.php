<?php

// https://lafrikileria.com/modules/redactor/classes/MetaSeoOptimizer.php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';
require_once(dirname(__FILE__).'/OpenAIClasificador.php');


new MetaSeoOptimizer();

class MetaSeoOptimizer
{
    private $tabla = 'redactor_seo_optimizer';   
    private $inicio;
    private $execution_time = 280;
    private $max_execution_time;
    private $log_file = _PS_ROOT_DIR_.'/modules/redactor/log/meta_seo_optimizer.txt';
    private $logger;

    public function __construct()
    {
        try {            
            $this->inicio = time();
            $max_ini = ini_get('max_execution_time');
            $this->max_execution_time = ($max_ini && $max_ini > 0) ? min($max_ini * 0.9, $this->execution_time) : $this->execution_time;
            $this->logger = new LoggerFrik($this->log_file);
            
            //para llevarnos $logger a OpenAIClasificador:
            OpenAIClasificador::setLogger($this->logger);      
            
            $this->logger->log("-----     -----     -----     -----     -----", 'INFO', false);
            $this->logger->log("Iniciado proceso de revisión de SEO de productos", 'INFO', false);   
            
            $this->resetearProductosProcesandoAntiguos(10); // 10 minutos

            $this->start();
        } catch (Exception $e) {
            $this->logger->log("Fallo en ejecución general: " . $e->getMessage(), 'ERROR');
        }   
    }

    public function start()
    {
        $contador = 0;

        while (true) {
            $contador++;

            if ((time() - $this->inicio) > $this->max_execution_time) {
                $this->logger->log("Tiempo máximo de ejecución alcanzado, fin del proceso.", 'INFO');
                break;
            }

            $producto = $this->getProducto();            

            if (!$producto && $contador > 1) {
                $this->logger->log("No hay productos nuevos ni pendientes por procesar.", 'INFO');
                return;
            } elseif (!$producto && $contador == 1) {
                return;
            }

            $id_product = (int)$producto['id_product'];

            // $this->logger->log("Procesando producto $id_product .", 'INFO');

            try {
                $conPrecio = $this->contienePrecio($producto['meta_title']);
                $nombre = $producto['name'];
                $descripcion = $producto['description'];

                $this->logger->log("Procesando producto $id_product. Contiene precio meta_title: ".($conPrecio ? 'Sí' : 'No'), 'DEBUG');

                $respuestaIA = OpenAIClasificador::optimizarMetaSeo($nombre, $descripcion, $conPrecio);

                $this->validarRespuestaSeo($respuestaIA, $conPrecio, $id_product);

                $this->logger->log("Respuesta de IA validada correctamente para producto $id_product.", 'DEBUG');

                $actualizados = $this->actualizarCamposProducto($id_product, $respuestaIA, $conPrecio);

                $this->guardarLogTabla($id_product, $actualizados);

                Db::getInstance()->update('redactor_seo_optimizer', [
                    'estado' => 'ok',
                    'fecha_estado' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s')
                ], 'id_product = ' . $id_product);

                $this->logger->log("Producto $id_product procesado correctamente.", 'INFO');

            } catch (Exception $e) {
                $mensaje = "Error en producto $id_product: " . $e->getMessage();
                $this->logger->log($mensaje, 'ERROR');

                $error_log = pSQL($mensaje);
                $timestamp = date('Y-m-d H:i:s');

                $campos = [
                    "estado = 'error'",
                    "fecha_estado = NOW()",
                    "error_log = CONCAT(IFNULL(error_log, ''),'\n[$timestamp] $error_log')",
                    "date_upd = NOW()"
                ];

                $sql = "UPDATE "._DB_PREFIX_."{$this->tabla}
                    SET " . implode(', ', $campos) . "
                    WHERE id_product = " . (int)$id_product;

                Db::getInstance()->execute($sql);            
            }
        }
    }

    private function getProducto()
    {      
        //1. sacamos un producto, pueden salir activos o no, a la venta o no, pero para dar más importancia a los vendibles etc ordenamos primero por activos, dentro de esos los que sean vendibles, y dentro de esos, por antiguedad, nuevos primero. Y además que no tengan las etiquetas de no indexar en link_rewrite de disfrazzes y kids, y por si acaso, que no tengan id_supplier disfrazzes ni globomatik. Pero los primeros en salir aparte serán los que si están ya en lafrips_redactor_seo_optimizer y tienen estado pendiente. Un campo del select es existe_en_optimizer, lo cual nos indicará si hay que hacer insert o update. Solo devolverá productos que no sean de los proveedores esos o sin indexar y no estén en la tabla, o si están en la tabla, que estén en estado 'pendiente'
        $sql = "
            SELECT 
            p.id_product,
            pl.name,
            pl.description,
            pl.meta_title,                
            CASE WHEN so.id_product IS NOT NULL THEN 1 ELSE 0 END AS existe_en_optimizer
        FROM lafrips_product p
        JOIN lafrips_product_lang pl 
            ON p.id_product = pl.id_product AND pl.id_lang = 1
        JOIN lafrips_stock_available sa 
            ON sa.id_product = p.id_product AND sa.id_product_attribute = 0
        LEFT JOIN lafrips_redactor_seo_optimizer so 
            ON so.id_product = p.id_product
        WHERE 
            pl.link_rewrite NOT LIKE '%noindxr%'
            AND pl.link_rewrite NOT LIKE '%kidscrd%'
            AND p.id_supplier NOT IN (161, 156)
            AND (so.id_product IS NULL OR so.estado = 'pendiente')  -- clave: solo pendientes o sin registro
        ORDER BY
            -- 1º prioridad: que exista en redactor y tenga estado pendiente
            (CASE WHEN so.estado = 'pendiente' THEN 1 ELSE 0 END) DESC,
            -- Si es pendiente, ordenar por fecha_estado (más antiguos primero)
            (CASE WHEN so.estado = 'pendiente' THEN so.fecha_estado END) ASC,
            -- 2º prioridad: los que no tienen registro en la tabla
            (CASE WHEN so.id_product IS NULL THEN 1 ELSE 0 END) DESC,
            -- 3º: los activos primero
            p.active DESC,
            -- 4º: vendibles primero
            (CASE WHEN sa.out_of_stock = 1 OR sa.quantity > 0 THEN 1 ELSE 0 END) DESC,
            -- 5º: antigüedad
            p.date_add DESC
        ";

        $producto = Db::getInstance()->getRow($sql);

        if ($producto) {
            $id_product = (int)$producto['id_product'];            

            //si no existe en la tabla, lo insertamos, como procesando, si existe lo actualizamos como procesando
            if ($producto['existe_en_optimizer']) {
                // Marcar como procesando
                Db::getInstance()->update($this->tabla, [
                    'estado' => 'procesando',
                    'fecha_estado' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s')
                ], 'id_product = ' . $id_product);

                $this->logger->log("Producto $id_product marcado como procesando (UPDATE).", 'DEBUG');

                return $producto;
                
            } else {
                // Insertar en la tabla y marcar como procesando
                Db::getInstance()->insert($this->tabla, [
                    'id_product' => $id_product,
                    'estado' => 'procesando',
                    'fecha_estado' => date('Y-m-d H:i:s'),
                    'date_add' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s')
                ]);

                $this->logger->log("Producto $id_product marcado como procesando (INSERT).", 'DEBUG');

                return $producto;
            }            
        }        

        //no hay productos a procesar
        return null;
    }


    private function contienePrecio($texto)
    {
        return preg_match('/\d+([.,]\d{1,2})?\s?(€|euros|eur|€\/u)/i', $texto);
    }

    /**
     * Valida que la respuesta de la IA tenga la estructura y tipos esperados.
     *
     * @param array $respuesta Respuesta devuelta por la IA ya interpretada.
     * @param bool $conPrecio Indica si se esperaba respuesta con metatítulo y metadescripción.
     * @param int $id_product Para mensaje de error en contexto.
     *
     * @throws Exception Si falta algún campo o el tipo no es válido.
     */
    private function validarRespuestaSeo(array $respuesta, bool $conPrecio, int $id_product)
    {
        $idiomas = ['es', 'en', 'fr', 'pt'];

        foreach ($idiomas as $lang) {
            // Verificamos que el idioma esté presente y sea un array
            if (!isset($respuesta[$lang]) || !is_array($respuesta[$lang])) {
                throw new Exception("Respuesta IA inválida para producto $id_product: Falta o es incorrecto el idioma '$lang'.");
            }

            // Validar 'image_alt' siempre
            if (
                !array_key_exists('image_alt', $respuesta[$lang]) ||
                !is_string($respuesta[$lang]['image_alt']) ||
                trim($respuesta[$lang]['image_alt']) === ''
            ) {
                throw new Exception("Respuesta IA inválida para producto $id_product: 'image_alt' vacío o inválido en '$lang'.");
            }

            // Si el producto tenía precio en el meta_title, validar también 'meta_title' y 'meta_description'
            if ($conPrecio) {
                foreach (['meta_title', 'meta_description'] as $campo) {
                    if (
                        !array_key_exists($campo, $respuesta[$lang]) ||
                        !is_string($respuesta[$lang][$campo]) ||
                        trim($respuesta[$lang][$campo]) === ''
                    ) {
                        throw new Exception("Respuesta IA inválida para producto $id_product: '$campo' vacío o inválido en '$lang'.");
                    }
                }
            }
        }
    }

    //al tiempo que actualizamos campos, eliminamos el contenido del resto de idiomas para limpiar
    private function actualizarCamposProducto($id_product, $datos, $conPrecio)
    {
        $idiomas = [1 => 'es', 11 => 'en', 12 => 'fr', 18 => 'pt'];
        $resultados = ['metatitle' => 0, 'metadescription' => 0, 'alt' => 0];
        $id_langs_validos = implode(',', array_keys($idiomas));

        foreach ($idiomas as $id_lang => $codigo) {
            // ----- METATÍTULO Y METADESCRIPCIÓN -----
            if ($conPrecio && isset($datos[$codigo]['meta_title'], $datos[$codigo]['meta_description'])) {
                $current = Db::getInstance()->getRow("
                    SELECT meta_title, meta_description
                    FROM lafrips_product_lang
                    WHERE id_product = $id_product AND id_lang = $id_lang
                ");

                $nuevo_title = pSQL($datos[$codigo]['meta_title']);
                $nuevo_desc = pSQL($datos[$codigo]['meta_description']);

                if ($current && ($current['meta_title'] !== $nuevo_title || $current['meta_description'] !== $nuevo_desc)) {
                    Db::getInstance()->update('product_lang', [
                        'meta_title' => $nuevo_title,
                        'meta_description' => $nuevo_desc,
                    ], "id_product = $id_product AND id_lang = $id_lang");

                    if ($current['meta_title'] !== $nuevo_title) {
                        $resultados['metatitle'] = 1;
                    }
                    if ($current['meta_description'] !== $nuevo_desc) {
                        $resultados['metadescription'] = 1;
                    }

                    // Borra meta_title y meta_description en otros idiomas
                    Db::getInstance()->update('product_lang', [
                        'meta_title' => '',
                        'meta_description' => '',
                    ], "id_product = $id_product AND id_lang NOT IN ($id_langs_validos)");
                }
            }

            // ----- LEGEND DE IMÁGENES -----
            if (isset($datos[$codigo]['image_alt'])) {
                $nuevo_alt = pSQL($datos[$codigo]['image_alt']);

                // Seleccionamos imágenes del producto en ese idioma
                $imagenes = Db::getInstance()->executeS("
                    SELECT il.id_image, il.legend
                    FROM lafrips_image_lang il
                    INNER JOIN lafrips_image i ON il.id_image = i.id_image
                    WHERE i.id_product = $id_product AND il.id_lang = $id_lang
                ");

                foreach ($imagenes as $img) {
                    if (trim($img['legend']) !== $nuevo_alt) {
                        Db::getInstance()->update('image_lang', [
                            'legend' => $nuevo_alt,
                        ], "id_image = " . (int)$img['id_image'] . " AND id_lang = $id_lang");

                        $resultados['alt'] = 1;

                        // Borrar ALT en otros idiomas
                        Db::getInstance()->update('image_lang', [
                            'legend' => '',
                        ], "id_image = " . (int)$img['id_image'] . " AND id_lang NOT IN ($id_langs_validos)");
                    }
                }
            }
        }

        if ($resultados['metatitle'] === 0 && $resultados['metadescription'] === 0 && $resultados['alt'] === 0) {
            $this->logger->log("Producto $id_product no tenía diferencias con los campos existentes. No se actualizó ningún campo.", 'WARNING');
        }

        if (array_sum($resultados) > 0) {
            $this->logger->log("Campos SEO actualizados para producto $id_product: " . json_encode($resultados), 'DEBUG');
        }

        return $resultados;
    }


    private function guardarLogTabla($id_product, $estado_array)
    {
        $estado = isset($estado_array['error']) ? 'error' : 'ok';        

        $campos = [
            "estado = '" . pSQL($estado) . "'",
            "fecha_estado = NOW()",
            "metatitle_actualizado = " . (int)($estado_array['metatitle'] ?? 0),
            "metadescription_actualizado = " . (int)($estado_array['metadescription'] ?? 0),
            "alt_actualizado = " . (int)($estado_array['alt'] ?? 0),
            "date_upd = NOW()"
        ];

        if ($estado === 'error' && !empty($estado_array['error'])) {
            $error_log = pSQL($estado_array['error']);
            $timestamp = date('Y-m-d H:i:s');
            $campos[] = "error_log = CONCAT(IFNULL(error_log, ''),'\n[$timestamp] $error_log')";
        }

        $sql = "UPDATE "._DB_PREFIX_."{$this->tabla}
                SET " . implode(', ', $campos) . "
                WHERE id_product = " . (int)$id_product;

        Db::getInstance()->execute($sql);
    }


    public function resetearProductosProcesandoAntiguos($minutos = 10)
    {
        $limite = date('Y-m-d H:i:s', time() - ($minutos * 60));

        // Obtener IDs de productos estancados en 'procesando'
        $sql_ids = "
            SELECT id_product 
            FROM lafrips_redactor_seo_optimizer 
            WHERE estado = 'procesando' 
            AND fecha_estado < '$limite'
        ";

        $productos_reiniciar = Db::getInstance()->executeS($sql_ids);

        $ids_reiniciar = array_column($productos_reiniciar, 'id_product');

        // Si hay productos para reiniciar
        if (!empty($ids_reiniciar)) {
            $ids_in = implode(',', array_map('intval', $ids_reiniciar));

            $timestamp = date('Y-m-d H:i:s');

            // Actualizar su estado a pendiente
            $res = Db::getInstance()->execute("
                UPDATE lafrips_redactor_seo_optimizer 
                SET 
                estado = 'pendiente', 
                fecha_estado = NOW(),
                error_log = CONCAT(IFNULL(error_log, ''),'\n[$timestamp] Reiniciado'), 
                date_upd = NOW() 
                WHERE id_product IN ($ids_in)
            ");

            $this->logger->log("---------- Reinicio de productos estancados ----------", 'WARNING');
            $this->logger->log("Productos reiniciados tras más de $minutos minutos en 'procesando': [$ids_in] (Total: $res)", 'WARNING');
        }

        // $this->logger->log("Revisión de productos estancados ejecutada.", 'INFO');

        return;
    }
}

