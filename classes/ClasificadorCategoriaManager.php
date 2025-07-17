<?php

require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

class ClasificadorCategoriaManager
{    
    private $tabla = 'redactor_clasificador_categorias';   

    //tiempo para volver a poner en Pendiente un producto "atascado"
    private $reset_procesando_time = 360;
    
    private $logger = null;

    //importamos $logger
    public function setLogger(LoggerFrik $logger)
    {
        $this->logger = $logger;
    }

    //si no se hizo setLogger() simplemente $this->logger será null y no pasará nada
    private function log(string $mensaje, string $tipo = 'INFO')
    {
        if ($this->logger) {
            $this->logger->log($mensaje, $tipo);
        }
    }

    public function obtenerProductoEnCola()
    {
        $sql = "
            SELECT id_product 
            FROM "._DB_PREFIX_."{$this->tabla} 
            WHERE estado = 'pendiente' 
            AND en_cola = 1 
            ORDER BY date_metido_cola ASC
            -- LIMIT 1             
        ";

        $id_product = Db::getInstance()->getValue($sql);   

        if (!$id_product) {
            return false;
        }

        $ahora = date('Y-m-d H:i:s');

        $actualizado = Db::getInstance()->update($this->tabla, [
            'estado' => 'procesando',
            'estado_fecha' => $ahora,
            'en_cola' => 0,
            'inicio_proceso' => $ahora,
            'date_upd' => $ahora,
        ], "id_product = " . (int)$id_product . " AND estado = 'pendiente'");        

        if (!$actualizado) {
            // Puede que el registro haya sido tomado en paralelo, podría suceder que se superpongan dos procesos cron por error. Devolvemos skip true para que continue con el siguiente producto de lista
            return [ 'skip' => true ];
        }

        return ['id_product' => (int)$id_product];
    }


    //como parámetro puede llegar $logger desde ColaClasificación para seguir haciendo log
    public function clasificarProducto($id_product, LoggerFrik $logger = null)
    {
        $this->log("Iniciando clasificación para producto ID $id_product", 'INFO');

        // === Obtener información del producto ===
        $info = $this->obtenerDatosProducto($id_product);

        if (!$info) {
            $mensaje = 'No se encontró el producto en la base de datos.';
            $this->marcarError($id_product, $mensaje);
            $this->log($mensaje, 'ERROR');
            return;
        }

        if (!$info['nombre'] || !$info['descripcion'] || !$info['precio_con_iva']) {
            $mensaje = 'Faltan nombre, descripción o precio con IVA';
            $this->marcarError($id_product, $mensaje);
            $this->log($mensaje, 'ERROR');
            return;            
        }

        // === Categoría principal, tipo de producto y categoría de precio ===
        $categorias = $this->getCategoriasPrincipales();

        $tipos = $this->getTiposProducto();
        
        $resultado_principal = OpenAIClasificador::obtenerCategoriaPrincipalTipoPrecioTarget(
            $info['nombre'],
            $info['descripcion'],
            $info['precio_con_iva'],
            $categorias,
            $tipos
        );

        if (
            !$resultado_principal 
            || !isset($resultado_principal['categoria_principal_id']) 
            || !isset($resultado_principal['tipo_producto_id']) 
            || !isset($resultado_principal['categoria_precio_id'])
            || !isset($resultado_principal['target'])
        ) {
            $mensaje = "Error en clasificación principal, target, tipo o precio para producto $id_product.";
            $this->marcarError($id_product, $mensaje);
            $this->log($mensaje, 'ERROR');
            return;
        }

        $this->log(sprintf(
            "Clasificación para producto %d: Categoría Principal = %s, Tipo Producto = %s, Categoría Precio = %s, Target = %s",
            $id_product,
            $resultado_principal['categoria_principal_id'],
            $resultado_principal['tipo_producto_id'],
            $resultado_principal['categoria_precio_id'],
            $resultado_principal['target']
        ));



        if (!$this->guardarCategoriaPrincipalTipoPrecioTarget($id_product, $resultado_principal)) {
            $mensaje = "Error guardando en lafrips_redactor_clasificador_categorias la clasificación principal, target, tipo y precio para producto $id_product.";
            $this->marcarError($id_product, $mensaje);
            $this->log($mensaje, 'ERROR');
            return;
        }

        // ===  Referencia de producto ===
        $referencia = $this->generarReferencia($resultado_principal['categoria_principal_id'], $id_product);

        if (!$referencia) {
            $mensaje = "Error generando referencia para producto $id_product.";
            $this->marcarError($id_product, $mensaje);
            $this->log($mensaje, 'ERROR');

            return;
        }        

        $this->guardarReferencia($id_product, $referencia);

        $this->log("Referencia generada para el producto $id_product : ".$referencia, 'INFO');

        // === Subcategorías de la principal ===            
       
        $subcats = $this->getSubcategoriasPrincipal($resultado_principal['categoria_principal_id']);

        // Si no hay subcategorías hijas, simplemente lo registramos y seguimos con el resto
        if (empty($subcats)) {
            $this->log("La categoría principal del producto $id_product no tiene subcategorías asignables. Se continúa con el resto.", 'INFO');
        } else {
            // Pedimos a la IA que seleccione subcategorías entre las disponibles
            $resultado_subcats_principal = OpenAIClasificador::obtenerSubcategoriasPrincipal(
                $info['nombre'],
                $info['descripcion'],
                $subcats
            );

            // Si falla la llamada o no devuelve el campo esperado, marcamos error
            if (
                !$resultado_subcats_principal ||
                !isset($resultado_subcats_principal['subcategorias_principal']) ||
                !is_array($resultado_subcats_principal['subcategorias_principal'])
            ) {
                $mensaje = "Error al obtener subcategorías principales del producto $id_product.";
                $this->marcarError($id_product, $mensaje);
                $this->log($mensaje, 'ERROR');
                return;
            }

            // Si la IA indica que no hay subcategorías adecuadas, devuelve array vacío, seguimos, sin marcar error
            if (empty($resultado_subcats_principal['subcategorias_principal']))  {
                $this->log("No encontradas subcategorías adecuadas para el producto $id_product según la IA.", 'WARNING');
                //guardamos espacio vacío
                $this->guardarSubcategoriasPrincipal($id_product, '');
            } else {
                // Guardamos las subcategorías propuestas por la IA
                $ids_array = array_filter(array_map('trim', $resultado_subcats_principal['subcategorias_principal'])); // quita espacios y vacíos si hubiera
                $ids_csv_subcats_principal = implode(',', $ids_array);                
                $this->guardarSubcategoriasPrincipal($id_product, $ids_csv_subcats_principal);
                $this->log(sprintf(
                    "Producto %d: Subcategorías principales asignadas = %s",
                    $id_product,
                    $ids_csv_subcats_principal
                ));
            }
        }
        

        // === Categorías para regalar ===
        $cats_regalar = $this->getCategoriasRegalar($resultado_principal['target']);
         
        // echo '<pre>';
        // print_r($cats_regalar);
        // echo '</pre>';   

        // Si no hay categorías regalar es un error, pasamos al siguiente
        if (empty($cats_regalar)) {
            return;
        } else {
            // Pedimos a la IA que seleccione categorías entre las disponibles
            $resultado_cats_regalar = OpenAIClasificador::obtenerCategoriasRegalar(
                $info['nombre'],
                $info['descripcion'],
                $cats_regalar
            );

            // Si falla la llamada o no devuelve el campo esperado, marcamos error
            if (
                !$resultado_cats_regalar 
                || !isset($resultado_cats_regalar['categorias_regalar']) 
                || !is_array($resultado_cats_regalar['categorias_regalar'])
            ) {
                $mensaje = "Error en clasificación para regalar para producto $id_product.";
                $this->marcarError($id_product, $mensaje);
                $this->log($mensaje, 'ERROR');
                return;
            }            
        }        

        $ids_array = array_filter(array_map('trim', $resultado_cats_regalar['categorias_regalar'])); // quita espacios y vacíos si hubiera
        $ids_csv_regalar = implode(',', $ids_array);
        $this->guardarCategoriasRegalar($id_product, $ids_csv_regalar);

        $this->log(sprintf(
            "Producto %d: Categorías para regalar = %s",
            $id_product,
            $ids_csv_regalar
        ));        

        // === Subcategorías para regalar ===
        //pasamos los ids ce regalar como cadena con comas para asignar a la sql directamente
        $subcats_regalar = $this->getSubcategoriasRegalar($ids_csv_regalar);

        // Si no hay subcategorías hijas, simplemente lo registramos y seguimos con el resto
        if (empty($subcats_regalar)) {
            $this->log("La/s categoría/s regalar del producto $id_product no tiene/n subcategorías asignables. Se continúa con el resto.", 'INFO');
        } else {
            // Pedimos a la IA que seleccione subcategorías entre las disponibles
            $resultado_subcats_regalar = OpenAIClasificador::obtenerSubCategoriasRegalar(
                $info['nombre'],
                $info['descripcion'],
                $subcats_regalar
            );

            // Si falla la llamada o no devuelve el campo esperado, marcamos error
            if (
                !$resultado_subcats_regalar 
                || !isset($resultado_subcats_regalar['subcategorias_regalar']) 
                || !is_array($resultado_subcats_regalar['subcategorias_regalar'])
            ) {
                $mensaje = "Error al obtener subcategorías para regalar del producto $id_product.";
                $this->marcarError($id_product, $mensaje);
                $this->log($mensaje, 'ERROR');
                return;
            }

            // Si la IA indica que no hay subcategorías adecuadas, devuelve array vacío, seguimos, sin marcar error
            if (empty($resultado_subcats_regalar['subcategorias_regalar'])) {
                $this->log("No encontradas subcategorías adecuadas para el producto $id_product según la IA.", 'WARNING');
                //guardamos espacio vacío
                $this->guardarSubcategoriasRegalar($id_product, '');
            } else {
                // Guardamos las subcategorías propuestas por la IA
                $ids_csv = implode(',', $resultado_subcats_regalar['subcategorias_regalar']);
                $this->guardarSubcategoriasRegalar($id_product, $ids_csv);
                $this->log(sprintf(
                    "Producto %d: Subcategorías para regalar = %s",
                    $id_product,
                    $ids_csv
                ));
            }
        }        

        // === Finalización ===
        $this->marcarCompleto($id_product);
        $this->log("Producto $id_product: Clasificación completada con éxito, procedemos a actualizar producto en Prestashop.", 'SUCCESS');

        $this->actualizarProductoConClasificacion($id_product);
    }



    /**
     * Obtiene los datos básicos del producto incluyendo nombre, descripción, precio e IVA.
     * 
     * @param int $id_product ID del producto
     * @return array|false Array con los datos del producto o false si no existe
     */
    public function obtenerDatosProducto($id_product)
    {
        // Aseguramos que el ID sea entero para evitar inyecciones SQL
        $id_product = (int)$id_product;

        // Consulta SQL para obtener la información necesaria
        $sql = "
            SELECT 
                p.id_product, 
                pl.name AS nombre, 
                pl.description_short AS descripcion,
                p.price, 
                t.rate AS tax_rate
            FROM lafrips_product p
            JOIN lafrips_product_lang pl 
                ON p.id_product = pl.id_product AND pl.id_lang = 1
            JOIN lafrips_tax_rule tr 
                ON tr.id_tax_rules_group = p.id_tax_rules_group AND tr.id_country = 6
            JOIN lafrips_tax t 
                ON t.id_tax = tr.id_tax
            WHERE p.id_product = $id_product
            -- LIMIT 1
        ";

        $producto = Db::getInstance()->getRow($sql);

        if (!$producto) {
            return false;
        }

        // Calculamos el precio con IVA y lo añadimos al array
        $producto['precio_con_iva'] = round(
            $producto['price'] * (1 + ($producto['tax_rate'] / 100)),
            2
        );

        return $producto;
    }

    /**
     * Actualiza uno o varios campos en la tabla en la fila correspondiente al producto.
     *
     * @param int $id_product ID del producto a actualizar.
     * @param array $campos Campos y valores a actualizar (clave => valor).
     * @param string|null $log_mensaje Mensaje opcional para dejar constancia en el log.
     *
     * @return bool True si la actualización se realizó con éxito, false en caso contrario.
     */
    public function actualizarCampos($id_product, array $campos, $log_mensaje = null)
    {
        // Añade/update la fecha de actualización
        $campos['date_upd'] = date('Y-m-d H:i:s');

        // Ejecuta la actualización en base de datos
        $ok = Db::getInstance()->update(
            $this->tabla, 
            $campos, 
            "id_product = {$id_product}");

        // Si hay logger y mensaje, se registra
        if ($log_mensaje) {
            $this->log($log_mensaje, $ok ? 'INFO' : 'ERROR');
        }

        return $ok;
    }


    /**
     * Guarda la clasificación principal, el target, el tipo de producto y la categoría de precio en la tabla del clasificador.
     *
     * @param int $id_product ID del producto a actualizar
     * @param array $resultado Array con claves:
     *   - 'categoria_principal_id' => int
     *   - 'tipo_producto_id' => int
     *   - 'categoria_precio' => int
     * @return bool true si la actualización fue correcta, false si falló
     */
    public function guardarCategoriaPrincipalTipoPrecioTarget($id_product, $resultado)
    {
        return $this->actualizarCampos($id_product, [
            'categoria_principal_id' => (int)$resultado['categoria_principal_id'],
            'tipo_producto_id'       => (int)$resultado['tipo_producto_id'],
            'categoria_precio'       => (int)$resultado['categoria_precio_id'],
            'target'       => $resultado['target']
        ], "Producto {$id_product} clasificado correctamente (principal/tipo/precio/target)");
    }


    /**
     * Guarda las subcategorías asociadas a la categoría principal del producto.
     *
     * @param int $id_product ID del producto a actualizar.
     * @param string $ids Lista de IDs de subcategorías en formato CSV (ej: "101,102").
     * @return bool True si se ha actualizado correctamente, false en caso contrario.
     */
    public function guardarSubcategoriasPrincipal($id_product, $ids)
    {
        return $this->actualizarCampos($id_product, [
            'subcategorias_principal' => pSQL($ids),
        ], "Subcategorías de principal guardadas para producto {$id_product}");
    }

    /**
     * Guarda las categorías funcionales asociadas al producto (para regalar).
     *
     * @param int $id_product ID del producto a actualizar.
     * @param string $ids Lista de IDs de categorías en formato CSV (ej: "200,201").
     * @return bool True si se ha actualizado correctamente, false en caso contrario.
     */
    public function guardarCategoriasRegalar($id_product, $ids)
    {
        return $this->actualizarCampos($id_product, [
            'categorias_regalar' => pSQL($ids),
        ], "Categorías para regalar guardadas para producto {$id_product}");
    }

    /**
     * Guarda las subcategorías funcionales (para regalar) asociadas al producto.
     *
     * @param int $id_product ID del producto a actualizar.
     * @param string $ids Lista de IDs de subcategorías en formato CSV (ej: "300,301").
     * @return bool True si se ha actualizado correctamente, false en caso contrario.
     */
    public function guardarSubcategoriasRegalar($id_product, $ids)
    {
        return $this->actualizarCampos($id_product, [
            'subcategorias_regalar' => pSQL($ids),
        ], "Subcategorías para regalar guardadas para producto {$id_product}");
    }


    /**
     * Marca el producto como con error durante el proceso de clasificación
     * y registra el mensaje de error en la tabla.
     *
     * @param int $id_product ID del producto a actualizar.
     * @param string $mensaje Mensaje de error descriptivo.
     * @return bool True si la actualización fue exitosa, false en caso contrario.
     */
    public function marcarError($id_product, $mensaje)
    {
        $ahora = date('Y-m-d H:i:s'); 

        return $this->actualizarCampos($id_product, [
            'estado'        => 'error',
            'estado_fecha'        => $ahora,
            'mensaje_error' => pSQL($mensaje)
        ], "ERROR en producto {$id_product}: {$mensaje}");
    }

    /**
     * Marca el producto como clasificado correctamente en la tabla de cola.
     *
     * @param int $id_product ID del producto a actualizar.
     * @return bool True si la actualización fue exitosa, false en caso contrario.
     */
    public function marcarCompleto($id_product)
    {
        $ahora = date('Y-m-d H:i:s'); 

        return $this->actualizarCampos($id_product, [
            'estado' => 'completo',
            'estado_fecha'        => $ahora,
            'date_clasificado' => $ahora
        ], "Clasificación completa para producto {$id_product}");
    }


    /**
     * Reinicia los productos que llevan demasiado tiempo en estado 'procesando',
     * devolviéndolos al estado 'pendiente' y en_cola. Se actualiza el campo `mensaje_error`
     * para indicar el reinicio por timeout.
     *
     * @return void
     */
    public function resetProcesandoAntiguos()
    {
        $sql = "SELECT id_product, inicio_proceso FROM "._DB_PREFIX_."{$this->tabla} WHERE estado = 'procesando'";
        $productos = Db::getInstance()->executeS($sql);        

        foreach ($productos as $producto) {
            $id_product = (int)$producto['id_product'];
            $inicio     = strtotime($producto['inicio_proceso']);
            $ahora      = time();
            $diff       = $ahora - $inicio;

            if ($diff >= $this->reset_procesando_time) {
                // Actualizar mensaje de error anterior concatenando el reinicio
                $sql_error = "SELECT mensaje_error FROM "._DB_PREFIX_."{$this->tabla} WHERE id_product = {$id_product}";
                $mensaje_anterior = Db::getInstance()->getValue($sql_error);
                $mensaje_nuevo = pSQL(trim($mensaje_anterior . ' | Reiniciado ' . date('Y-m-d H:i:s')));

                Db::getInstance()->update($this->tabla, [
                    'estado'         => 'pendiente',
                    'estado_fecha'        => date('Y-m-d H:i:s'),
                    'en_cola' => 1,
                    'inicio_proceso' => '0000-00-00 00:00:00',
                    'mensaje_error'  => $mensaje_nuevo,
                    'date_upd'       => date('Y-m-d H:i:s'),
                ], "id_product = {$id_product}");

                $this->log("Reiniciado producto {$id_product} por timeout de {$diff} segundos", 'WARNING');
            }
        }
    }

    /**
     * Obtiene los tipos de producto desde el atributo (feature) ID 8.
     * 
     * @return array Lista de tipos de producto con id y nombre.
     */
    public function getTiposProducto()
    {
        $sql = "
            SELECT fvl.id_feature_value, fvl.value
            FROM lafrips_feature_value fv
            JOIN lafrips_feature_value_lang fvl ON fv.id_feature_value = fvl.id_feature_value
            WHERE fv.id_feature = 8
            AND fvl.id_lang = 1
            ORDER BY fvl.value ASC
        ";

        return Db::getInstance()->executeS($sql);
    }


    /**
     * Obtiene las categorías principales filtrando por ciertos padres.
     * 
     * @return array Lista de categorías con id y nombre.
     */
    public function getCategoriasPrincipales()
    {
        $sql = "
            SELECT cat.id_category, cla.name
            FROM lafrips_category cat
            JOIN lafrips_category_lang cla ON cat.id_category = cla.id_category
            WHERE cat.id_parent IN (4, 5, 6, 7, 130)
            AND cla.id_lang = 1
            ORDER BY cla.name ASC
        ";

        return Db::getInstance()->executeS($sql);
    }


    /**
     * Obtiene todas las subcategorías de una categoría principal.
     * 
     * @param int $id_categoria_principal ID de la categoría raíz.
     * @return array Lista de subcategorías (excluyendo la raíz).
     */
    public function getSubcategoriasPrincipal($id_categoria_principal)
    {
        $id_categoria_principal = (int)$id_categoria_principal;

        //Sql hasta 5 niveles por debajo. No podemos usar WITH RECURSIVE
        $sql = "
            SELECT cl.id_category, cl.name
            FROM lafrips_category_lang cl
            JOIN lafrips_category c ON cl.id_category = c.id_category
            WHERE cl.id_lang = 1
            AND cl.id_category IN (
                -- Nivel 1: Hijos directos
                SELECT id_category FROM lafrips_category WHERE id_parent = $id_categoria_principal

                UNION

                -- Nivel 2: Nietos
                SELECT id_category FROM lafrips_category WHERE id_parent IN (
                    SELECT id_category FROM lafrips_category WHERE id_parent = $id_categoria_principal
                )

                UNION

                -- Nivel 3: Biznietos
                SELECT id_category FROM lafrips_category WHERE id_parent IN (
                    SELECT id_category FROM lafrips_category WHERE id_parent IN (
                        SELECT id_category FROM lafrips_category WHERE id_parent = $id_categoria_principal
                    )
                )

                UNION

                -- Nivel 4: Tataranietos
                SELECT id_category FROM lafrips_category WHERE id_parent IN (
                    SELECT id_category FROM lafrips_category WHERE id_parent IN (
                        SELECT id_category FROM lafrips_category WHERE id_parent IN (
                            SELECT id_category FROM lafrips_category WHERE id_parent = $id_categoria_principal
                        )
                    )
                )

                UNION

                -- Nivel 5: Choznos
                SELECT id_category FROM lafrips_category WHERE id_parent IN (
                    SELECT id_category FROM lafrips_category WHERE id_parent IN (
                        SELECT id_category FROM lafrips_category WHERE id_parent IN (
                            SELECT id_category FROM lafrips_category WHERE id_parent IN (
                                SELECT id_category FROM lafrips_category WHERE id_parent = $id_categoria_principal
                            )
                        )
                    )
                )
            )
            ORDER BY cl.name ASC;
        ";

        // $this->log("getSubcategoriasPrincipal SQL: \n" . $sql, 'DEBUG');

        $resultado = Db::getInstance()->executeS($sql);

        // $this->log("Subcategorías principal obtenidas: " . print_r($resultado, true), 'DEBUG');

        return $resultado;
    }

    /* Alternativa a WITH RECURSIVE para 4 niveles

    $sql = "
            SELECT cl.id_category, cl.name
            FROM lafrips_category_lang cl
            JOIN lafrips_category c ON cl.id_category = c.id_category
            WHERE cl.id_lang = 1
            AND cl.id_category IN (
                SELECT id_category FROM lafrips_category WHERE id_parent = $id_categoria_principal
                UNION
                SELECT c1.id_category FROM lafrips_category c1
                WHERE c1.id_parent IN (SELECT id_category FROM lafrips_category WHERE id_parent = $id_categoria_principal)
                UNION
                SELECT c2.id_category FROM lafrips_category c2
                WHERE c2.id_parent IN (
                    SELECT c1.id_category FROM lafrips_category c1
                    WHERE c1.id_parent IN (SELECT id_category FROM lafrips_category WHERE id_parent = $id_categoria_principal)
                )
                UNION
                SELECT c3.id_category FROM lafrips_category c3
                WHERE c3.id_parent IN (
                    SELECT c2.id_category FROM lafrips_category c2
                    WHERE c2.id_parent IN (
                        SELECT c1.id_category FROM lafrips_category c1
                        WHERE c1.id_parent IN (SELECT id_category FROM lafrips_category WHERE id_parent = $id_categoria_principal)
                    )
                )
            )
            ORDER BY cl.name ASC
        ";

        Con WITH RECURSIVE. Existe algún problema, probablemente relacionado con DB de Prestashop ¿?¿?¿

        $sql = "
            WITH RECURSIVE subcategorias AS (
                SELECT c.id_category, c.id_parent, cl.name
                FROM lafrips_category c
                JOIN lafrips_category_lang cl ON c.id_category = cl.id_category
                WHERE cl.id_lang = 1 AND c.id_category = $id_categoria_principal

                UNION ALL

                SELECT c.id_category, c.id_parent, cl.name
                FROM lafrips_category c
                JOIN lafrips_category_lang cl ON c.id_category = cl.id_category
                JOIN subcategorias s ON c.id_parent = s.id_category
                WHERE cl.id_lang = 1
            )
            SELECT id_category, name
            FROM subcategorias
            WHERE id_category != $id_categoria_principal
            ORDER BY name ASC
        ";
    */

    /**
     * Obtiene categorías funcionales para regalar, hijas de 3 ramas clave.
     * 
     * @param string $target Target para escoger la categoría cuyos hijos queremos obtener.
     * @return array Lista de categorías con id y nombre.
     */
    public function getCategoriasRegalar($target)
    {
        if ($target == 'ADULTO') {
            $id_category = 144;
        } elseif ($target == 'COSPLAY') {
            $id_category = 58;
        } elseif ($target == 'KIDS') {
            $id_category = 2232;
        } else {
            $this->log("Target para producto no válido: {$target}", 'ERROR');

            return [];
        }

        $sql = "
            SELECT cat.id_category, cla.name
            FROM lafrips_category cat
            JOIN lafrips_category_lang cla ON cat.id_category = cla.id_category
            WHERE 
                (cat.nleft > (SELECT nleft FROM lafrips_category WHERE id_category = $id_category)
                AND cat.nright < (SELECT nright FROM lafrips_category WHERE id_category = $id_category)) 
            AND cat.level_depth = 4
            AND cla.id_lang = 1
            AND cat.id_category NOT IN (3, 107)
            ORDER BY cla.name ASC
        ";

        return Db::getInstance()->executeS($sql);
    }


    /**
     * Obtiene todas las subcategorías de una lista de categorías "para regalar".
     * 
     * @param string $ids_cats_regalar Cadena de IDs separados por coma, ej: "23,34,1"
     * @return array Lista de subcategorías hijas con campos id y nombre.
     */
    public function getSubcategoriasRegalar($ids_cats_regalar)
    {       

        if (!isset($ids_cats_regalar) || !is_string($ids_cats_regalar) || trim($ids_cats_regalar) === '') {
            return []; // Si no hay IDs válidos, devolvemos array vacío
        }        

        //sql hasta 5 niveles, aunque será raro que haya más de 4
        $sql = "
            SELECT cl.id_category, cl.name
            FROM lafrips_category_lang cl
            JOIN lafrips_category c ON cl.id_category = c.id_category
            WHERE cl.id_lang = 1
            AND cl.id_category IN (
                -- Nivel 1: Hijos directos
                SELECT id_category FROM lafrips_category WHERE id_parent IN ($ids_cats_regalar)

                UNION

                -- Nivel 2: Nietos
                SELECT id_category FROM lafrips_category WHERE id_parent IN (
                    SELECT id_category FROM lafrips_category WHERE id_parent IN ($ids_cats_regalar)
                )

                UNION

                -- Nivel 3: Biznietos
                SELECT id_category FROM lafrips_category WHERE id_parent IN (
                    SELECT id_category FROM lafrips_category WHERE id_parent IN (
                        SELECT id_category FROM lafrips_category WHERE id_parent IN ($ids_cats_regalar)
                    )
                )

                UNION

                -- Nivel 4: Tataranietos
                SELECT id_category FROM lafrips_category WHERE id_parent IN (
                    SELECT id_category FROM lafrips_category WHERE id_parent IN (
                        SELECT id_category FROM lafrips_category WHERE id_parent IN (
                            SELECT id_category FROM lafrips_category WHERE id_parent IN ($ids_cats_regalar)
                        )
                    )
                )

                UNION

                -- Nivel 5: Choznos
                SELECT id_category FROM lafrips_category WHERE id_parent IN (
                    SELECT id_category FROM lafrips_category WHERE id_parent IN (
                        SELECT id_category FROM lafrips_category WHERE id_parent IN (
                            SELECT id_category FROM lafrips_category WHERE id_parent IN (
                                SELECT id_category FROM lafrips_category WHERE id_parent IN ($ids_cats_regalar)
                            )
                        )
                    )
                )
            )
            ORDER BY cl.name ASC;
        ";

        // $this->log("getSubcategoriasRegalar SQL: \n" . $sql, 'DEBUG');

        $resultado = Db::getInstance()->executeS($sql);

        // $this->log("Subcategorías regalar obtenidas: " . print_r($resultado, true), 'DEBUG');
        
        return $resultado;
    }

    /**
     * Obtiene todas las subcategorías recursivas a partir de una lista de categorías base.
     *
     * @param string|array $ids_categorias Lista de IDs (array o string tipo "23,34,1")
     * @param bool $excluir_raiz Si se debe excluir del resultado las categorías raíz originales (por defecto: true)
     * @return array Lista de subcategorías con campos 'id' y 'nombre'
     * 
     * $this->getSubcategoriasRecursivas("23,34,1"); // Excluye raíz (por defecto) Solo hijas
     * $this->getSubcategoriasRecursivas([23, 34, 1], false); // Incluye raíz también
     */
    //NO ESTÁ EN USO, sería adaptable para diferentes casos
    public function getSubcategoriasRecursivas($ids_categorias, $excluir_raiz = true)
    {
        // Convertir a array si se recibe una cadena
        if (is_string($ids_categorias)) {
            $ids_categorias = explode(',', $ids_categorias);
        }

        // Filtrar y sanear valores
        $ids_array = array_filter(array_map('intval', $ids_categorias));

        if (empty($ids_array)) {
            return [];
        }

        $ids_sql = implode(',', $ids_array);

        // OJO PARECE QUE PRESTASHOP DB NO ES AMIGA DE WITH RECURSIVE
        $sql = "
            WITH RECURSIVE subcategorias AS (
                SELECT c.id_category, c.id_parent, cl.name
                FROM lafrips_category c
                JOIN lafrips_category_lang cl ON c.id_category = cl.id_category
                WHERE cl.id_lang = 1 AND c.id_category IN ($ids_sql)

                UNION ALL

                SELECT c.id_category, c.id_parent, cl.name
                FROM lafrips_category c
                JOIN lafrips_category_lang cl ON c.id_category = cl.id_category
                JOIN subcategorias s ON c.id_parent = s.id_category
                WHERE cl.id_lang = 1
            )
            SELECT id_category, name
            FROM subcategorias
            " . ($excluir_raiz ? "WHERE id_category NOT IN ($ids_sql)" : "") . "
            ORDER BY name ASC
        ";

        return Db::getInstance()->executeS($sql);
    }

    
    public function generarReferencia($id_categoria_principal, $id_product)
    {
        //  Comprobamos si ya tiene una referencia válida y no repetida
        $referencia_actual = Db::getInstance()->getValue("SELECT reference FROM lafrips_product WHERE id_product = " . (int)$id_product);

        if ($referencia_actual && preg_match('/^[A-Z]{3}[0-9]{8}$/', $referencia_actual)) {
            // Comprobamos si está repetida (salvo por el propio producto)
            $sql_check = "
                SELECT COUNT(*) 
                FROM lafrips_product 
                WHERE reference = '" . pSQL($referencia_actual) . "' 
                AND id_product != " . (int)$id_product;

            $repetida = Db::getInstance()->getValue($sql_check);

            if (!$repetida) {
                // Es válida y única
                $this->log("Referencia actual del producto $referencia_actual es válida y única, no generamos nueva", 'INFO');

                return $referencia_actual;
            }
        }

        // obtenemos el prefijo en función de la categoría principal, sacando el prefijo más común para los productos que tienen dicha categoría. Si ningún producto tiene la categoría, devuelve OTR
        $sql = "SELECT COALESCE(
            (SELECT LEFT(p.reference, 3)
             FROM lafrips_product p
             WHERE p.reference IS NOT NULL
               AND LENGTH(p.reference) >= 3
               AND p.id_category_default = $id_categoria_principal
             GROUP BY LEFT(p.reference, 3)
             ORDER BY COUNT(*) DESC
             LIMIT 1),
             'OTR'
        ) AS prefijo";

        $prefijo = Db::getInstance()->getValue($sql);

        // Si está vacío o no válido, asignamos 'OTR'
        if (!$prefijo || !preg_match('/^[A-Z]{3}$/', $prefijo)) {
            $this->log("Prefijo para referencia OTR asignado por no haberse obtenido ninguno por la categoría principal $id_categoria_principal", 'WARNING');

            $prefijo = 'OTR';
        }

        // Probamos las fechas desde hoy en adelante con dicho prefijo paa asegurarnos de no repetir referencia
        $fecha_actual = new DateTime();

        for ($dias = 0; $dias < 365; $dias++) {
            $fecha = $fecha_actual->format('ymd'); // Ejemplo: 250716

            // Probar sufijos del 01 al 99
            for ($i = 1; $i <= 99; $i++) {
                $sufijo = str_pad($i, 2, '0', STR_PAD_LEFT);
                $referencia = $prefijo . $fecha . $sufijo;

                // Verificar que no exista
                $sql_check = "SELECT COUNT(*) FROM lafrips_product WHERE reference = '" . pSQL($referencia) . "'";
                $existe = Db::getInstance()->getValue($sql_check);

                if (!$existe && preg_match('/^[A-Z]{3}[0-9]{8}$/', $referencia)) {
                    return $referencia;
                }
            }

            // Si las 99 están ocupadas, sumar un día y seguir
            $fecha_actual->modify('+1 day');
        }       
        
        $this->log("No se pudo generar una referencia libre para producto $id_product para la categoría $id_categoria_principal en los próximos 365 días.", 'ERROR');

        return false;
    }


    public function guardarReferencia($id_product, $referencia) {
        return $this->actualizarCampos($id_product, [
            'referencia' => pSQL($referencia),
        ], "Referencia guardada para producto {$id_product}");
    }

    /**
     * Aplica la clasificación obtenida al producto real de Prestashop:
     * - Asigna categoría principal y subcategorías.
     * - Establece categoría por defecto.
     * - Guarda tipo de producto como característica (id_feature = 8).
     * - Genera descripción larga con texto SEO usando OpenAI.
     *
     * Si algo falla, se marca el producto como 'clasificado_sin_aplicar'.
     *
     * @param int $id_product
     */
    public function actualizarProductoConClasificacion($id_product)
    {
        try {
            // 1. Recuperamos datos de la tabla auxiliar
            $sql = "SELECT referencia, categoria_principal_id, subcategorias_principal, tipo_producto_id,
                        categoria_precio, categorias_regalar, subcategorias_regalar
                    FROM "._DB_PREFIX_."{$this->tabla}
                    WHERE id_product = " . (int)$id_product;

            $datos = Db::getInstance()->getRow($sql);

            if (!$datos) {
                throw new Exception("No se encontró la fila auxiliar en "._DB_PREFIX_."{$this->tabla} con la clasificación del producto $id_product.");
            }

            $referencia = $datos['referencia'];

            // Convertir campos a arrays y valores enteros
            $id_categoria_principal = (int)$datos['categoria_principal_id'];
            $id_tipo_producto = (int)$datos['tipo_producto_id'];
            $id_categoria_precio = (int)$datos['categoria_precio'];

            $subcategorias_principal = !empty($datos['subcategorias_principal']) ? array_filter(array_map('intval', explode(',', $datos['subcategorias_principal']))) : [];
            $categorias_regalar = !empty($datos['categorias_regalar']) ? array_filter(array_map('intval', explode(',', $datos['categorias_regalar']))) : [];
            $subcategorias_regalar = !empty($datos['subcategorias_regalar']) ? array_filter(array_map('intval', explode(',', $datos['subcategorias_regalar']))) : [];


            // 2. Instanciar el producto de Prestashop
            $product = new Product($id_product);
            if (!Validate::isLoadedObject($product)) {
                throw new Exception("No se pudo cargar el producto con ID $id_product");
            }

            // 3. Asignar categoría por defecto y Referencia de producto
            if ($id_categoria_principal > 0) {
                // Verificamos que la categoría principal existe
                $exists = Db::getInstance()->getValue("SELECT COUNT(*) FROM lafrips_category WHERE id_category = $id_categoria_principal");
                if (!$exists) {
                    throw new Exception("La categoría principal $id_categoria_principal no existe.");
                }

                $product->id_category_default = $id_categoria_principal;
                $product->reference = $referencia;
                $product->save();
            }


            // 4. Asignar todas las categorías al producto (incluimos la categoría de precio) comprobando existencia
            $categorias_total = array_unique(array_merge(
                $id_categoria_principal ? [$id_categoria_principal] : [],
                $id_categoria_precio ? [$id_categoria_precio] : [],
                $subcategorias_principal,
                $categorias_regalar,
                $subcategorias_regalar
            ));
            // Filtramos las categorías existentes
            if (!empty($categorias_total)) {
                $categorias_str = implode(',', array_map('intval', $categorias_total));
                $sql_existentes = "SELECT id_category FROM lafrips_category WHERE id_category IN ($categorias_str)";
                $categorias_existentes = array_column(Db::getInstance()->executeS($sql_existentes), 'id_category');

                if (!empty($categorias_existentes)) {
                    $ids_actuales = Product::getProductCategories($id_product);
                    if (array_diff($categorias_existentes, $ids_actuales) || array_diff($ids_actuales, $categorias_existentes)) {
                        $product->updateCategories($categorias_existentes);

                        $this->log("Producto $id_product: Categorías aplicadas -> " . implode(', ', $categorias_existentes), 'INFO');
                    }
                }
            }

            // 5. Asignar tipo de producto como característica (id_feature = 8)
            if ($id_tipo_producto > 0) {
                $existe_valor = Db::getInstance()->getValue("SELECT COUNT(*) FROM lafrips_feature_value WHERE id_feature_value = $id_tipo_producto AND id_feature = 8");
                if ($existe_valor) {
                    
                    $product->deleteProductFeatures();
                    
                    if (!Product::addFeatureProductImport($id_product, 8, $id_tipo_producto)) {
                        throw new Exception("No se pudo asignar la característica tipo de producto a $id_product.");
                    }
                    $this->log("Producto $id_product: Tipo producto aplicado -> " . $id_tipo_producto, 'INFO');
                } else {
                    $this->log("ID de tipo de producto no válido para el producto $id_product: $id_tipo_producto", 'WARNING');
                }
            }
        

            // 6. Generar descripción larga con texto SEO            
            // Obtener nombre y enlace de la categoría principal
            $sql_categoria = "
                SELECT cl.name, cl.link_rewrite
                FROM lafrips_category_lang cl
                WHERE cl.id_category = " . (int)$id_categoria_principal . " AND cl.id_lang = 1
            ";
            $categoria_data = Db::getInstance()->getRow($sql_categoria);

            if (!$categoria_data || !$categoria_data['link_rewrite']) {
                throw new Exception("No se encontró el nombre y link_rewrite de la categoría principal $id_categoria_principal.");
            }

            $nombre_categoria = $categoria_data['name'];
            $link_categoria = Context::getContext()->link->getCategoryLink($id_categoria_principal, $categoria_data['link_rewrite'], 1);

            // Obtener descripción larga con texto SEO
            $texto_seo = OpenAIClasificador::obtenerTextoSeoCategoria($nombre_categoria, $link_categoria);

            if (!$texto_seo || !isset($texto_seo['es'])) {
                throw new Exception("No se obtuvo el texto SEO de la IA para la descripción larga.");
            }

            // Mapeo de idiomas: id_lang => clave idioma
            $idiomas = [
                1  => 'es',                
                11  => 'en',
                12 => 'fr',
                18 => 'pt'
            ];

            // Actualizar descripción por idioma
            foreach ($idiomas as $id_lang => $codigo) {
                $desc = isset($texto_seo[$codigo]) ? $texto_seo[$codigo] : $texto_seo['es']; // fallback español

                $product->description[$id_lang] = $desc;
            }

            if (!$product->save()) {
                throw new Exception("No se pudo guardar la descripción en product_lang para el producto $id_product.");
            }

            // 7. Log
            $this->log("Producto $id_product: Clasificación aplicada correctamente en Prestashop.", 'SUCCESS');

        } catch (Exception $e) {
            // Si algo falla, se registra el error y se marca como 'clasificado_sin_aplicar'
            $mensaje = "Error al aplicar clasificación a producto $id_product: " . $e->getMessage();
            $this->actualizarCampos($id_product, [
                'estado' => 'clasificado_sin_aplicar',
                'mensaje_error' => pSQL($mensaje),
            ]);

            $this->log($mensaje, 'ERROR');
        }
    }

    /**
     * Encola un producto individual para clasificación. Se limpian los campos en caso de update
     * 
     * @return array ['success' => bool, 'message' => string]
     */
    public static function encolarProductoClasificacion($id_product, $id_employee = null)
    {
        //como $tabla es una variable no estática de la clase, no podemos acceder a ella desde una función estática como esta, para poder obtener su contenido hacemos esto:
        $tabla = (new self())->tabla;

        $id_product = (int)$id_product;

        if (!$id_product) {
            return [
                'success' => false,
                'message' => "ID de producto no válido para proceso clasificación: $id_product"
            ];
        }

        try {
            //si no llega id_employee ponemos automatizador
            $id_employee = $id_employee !== null ? (int)$id_employee : 44;

            $ahora = date('Y-m-d H:i:s');

            $existe = Db::getInstance()->getValue("SELECT COUNT(*) FROM "._DB_PREFIX_.$tabla." WHERE id_product = $id_product");

            if ($existe) {
                $resultado = Db::getInstance()->update($tabla, [
                    'estado' => 'pendiente',
                    'estado_fecha' => $ahora,
                    'en_cola' => 1,
                    'date_metido_cola' => $ahora,
                    'id_employee_metido_cola' => $id_employee,
                    'target' => null,
                    'categoria_principal_id' => null,
                    'tipo_producto_id' => null,
                    'categoria_precio' => null,
                    'subcategorias_principal' => null,
                    'categorias_regalar' => null,
                    'subcategorias_regalar' => null,
                    'date_upd' => $ahora
                ], "id_product = $id_product");

                if (!$resultado) {
                    throw new Exception("Falló el UPDATE del producto a encolar para clasificar $id_product.");
                }

                return ['success' => true, 'message' => "Producto $id_product actualizado en la cola de clasificación."];
            } else {
                $resultado = Db::getInstance()->insert($tabla, [
                    'id_product' => $id_product,
                    'estado' => 'pendiente',
                    'estado_fecha' => $ahora,
                    'en_cola' => 1,
                    'date_metido_cola' => $ahora,
                    'id_employee_metido_cola' => $id_employee,
                    'date_add' => $ahora,
                    'date_upd' => $ahora,
                    'mensaje_error' => '',
                ]);

                if (!$resultado) {
                    throw new Exception("Falló el INSERT del producto a encolar para clasificar $id_product.");
                }

                return ['success' => true, 'message' => "Producto $id_product insertado en la cola de clasificación."];
            }

        } catch (Exception $e) {
            // Log interno para trazabilidad
            // PrestaShopLogger::addLog("Error al encolar producto $id_product: ".$e->getMessage(), 3);

            return [
                'success' => false,
                'message' => "Error al encolar para clasificar el producto $id_product: ".$e->getMessage()
            ];
        }
    }

    /**
     * Encola múltiples productos y devuelve un array con los resultados de cada uno
     * 
     * @return array id_product => ['success' => bool, 'message' => string]
     */
    public static function encolarProductosClasificacion(array $ids_product, $id_employee = null)
    {
        //si no llega id_employee ponemos automatizador
        $id_employee = $id_employee !== null ? (int)$id_employee : 44;

        $resultados = [];

        foreach ($ids_product as $id_product) {
            $resultado = self::encolarProductoClasificacion((int)$id_product, $id_employee);
            $resultados[$id_product] = $resultado;
        }

        return $resultados;
    }  
    

}
