<?php

require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';

class ClasificadorCategoriaManager
{
    private $tabla = 'redactor_clasificador_categorias';

    //tiempo para volver a poner en Pendiente un producto "atascado"
    private $reset_procesando_time = 360;

    private $logger = null;

    //ids de categorías a partir de las que buscamos las que consideramos categorías principales. Meto variable paa centralizar por si cambian
    private $categorias_padres_principales = [4, 5, 6, 7, 12, 130];

    //categorías a redirigir para el link de descripción larga, por ejemplo "Regalos de más películas" -> "Mucho cine"
    private $categorias_cambiar_desc_larga = [
        12 => 144,
        30 => 4,
        131 => 130,
        314 => 6
    ];

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
            FROM " . _DB_PREFIX_ . "{$this->tabla} 
            WHERE estado = 'pendiente' 
            AND en_cola = 1 
            ORDER BY date_metido_cola ASC                        
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
        ], "id_product = " . (int) $id_product . " AND estado = 'pendiente'");

        if (!$actualizado) {
            // Puede que el registro haya sido tomado en paralelo, podría suceder que se superpongan dos procesos cron por error. Devolvemos skip true para que continue con el siguiente producto de lista
            return ['skip' => true];
        }

        return ['id_product' => (int) $id_product];
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

        //DEBUG
        // $categorias_str = implode(', ', array_map(function($cat) {
        //     return "{$cat['id_category']} - {$cat['name']}";
        // }, $categorias));

        // $tipos_str = implode(', ', array_map(function($tipo) {
        //     return "{$tipo['id_feature_value']} - {$tipo['value']}";
        // }, $tipos));

        // $this->log('Categorias para prompt: '.$categorias_str, 'DEBUG');
        // $this->log('Tipos para prompt: '.$tipos_str, 'DEBUG');

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

        $this->log("Referencia generada para el producto $id_product : " . $referencia, 'INFO');

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
            if (empty($resultado_subcats_principal['subcategorias_principal'])) {
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
        //utilizaremos diferente prompt para target adultos que para kids y cosplay, para adultos especificamos en el prompt las categorías, su contenido e id, de modo que no necesitamos obtener los ids
        if ($resultado_principal['target'] == 'ADULTO') {
            // Pedimos a la IA que seleccione categorías entre las disponibles
            $resultado_cats_regalar = OpenAIClasificador::obtenerCategoriasRegalar(
                $info['nombre'],
                $info['descripcion'],
                $resultado_principal['target']
            );
        } else {
            $cats_regalar = $this->getCategoriasRegalar($resultado_principal['target']);

            // echo '<pre>';
            // print_r($cats_regalar);
            // echo '</pre>';   

            // Si no hay categorías regalar es un error, pasamos al siguiente
            if (empty($cats_regalar)) {
                return;
            }

            // Pedimos a la IA que seleccione categorías entre las disponibles
            $resultado_cats_regalar = OpenAIClasificador::obtenerCategoriasRegalar(
                $info['nombre'],
                $info['descripcion'],
                $resultado_principal['target'],
                $cats_regalar
            );
        }

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

        $ids_array = array_filter(array_map('trim', $resultado_cats_regalar['categorias_regalar'])); // quita espacios y vacíos si hubiera
        $ids_csv_regalar = implode(',', $ids_array);
        $this->guardarCategoriasRegalar($id_product, $ids_csv_regalar);

        $this->log(sprintf(
            "Producto %d: Categorías para regalar = %s",
            $id_product,
            $ids_csv_regalar
        ));

        // === Subcategorías para regalar ===
        //pasamos los ids de regalar como cadena con comas para asignar a la sql directamente
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

        // === 23/03/2026 Otras Categorías ===
        //asignamos (o no) las categorías referentes a Amazon
        $cats_amazon = $this->getCategoriasAmazon();

        //obtenemos info extra necesaria para el prompt, como fabricante, nombres de las categorías ya asignadas, tipo de producto, etc
        $info_extra = $this->getInfoExtra($id_product);

        // Si no hay subcategorías hijas, simplemente lo registramos y seguimos con el resto
        if (empty($cats_amazon)) {
            $mensaje = "Error al obtener las categorías Amazon para el prompt para producto $id_product.";
            $this->marcarError($id_product, $mensaje);
            $this->log($mensaje, 'ERROR');
            return;
        } elseif (empty($info_extra)) {
            $mensaje = "Error al obtener la información extra para asignar las categorías Amazon para el producto $id_product.";
            $this->marcarError($id_product, $mensaje);
            $this->log($mensaje, 'ERROR');
            return;
        } else {
            $info_extra['nombre'] = $info['nombre'];
            $info_extra['descripcion'] = $info['descripcion'];
            // Pedimos a la IA que seleccione las categorías Amazon según la información disponible del producto
            $resultado_cats_amazon = OpenAIClasificador::obtenerCategoriasAmazon(
                $info_extra,
                $cats_amazon
            );

            // Si falla la llamada o no devuelve el campo esperado, marcamos error
            if (
                !$resultado_cats_amazon
                || !isset($resultado_cats_amazon['categorias_amazon'])
                || !is_array($resultado_cats_amazon['categorias_amazon'])
            ) {
                $mensaje = "Error al obtener categorías Amazon del producto $id_product.";
                $this->marcarError($id_product, $mensaje);
                $this->log($mensaje, 'ERROR');
                return;
            }

            // Si la IA indica que no hay categorías amazon adecuadas, devuelve array vacío, seguimos, sin marcar error
            if (empty($resultado_cats_amazon['categorias_amazon'])) {
                $this->log("No encontradas categorías Amazon adecuadas para el producto $id_product según la IA.", 'WARNING');
                //guardamos espacio vacío
                $this->guardarCategoriasAmazon($id_product, '');
            } else {
                // Guardamos las subcategorías propuestas por la IA
                $ids_csv = implode(',', $resultado_cats_amazon['categorias_amazon']);
                $this->guardarCategoriasAmazon($id_product, $ids_csv);
                $this->log(sprintf(
                    "Producto %d: Categorias Amazon = %s",
                    $id_product,
                    $ids_csv
                ));
            }
        }

        // === 09/04/2026 Categoría para descripción SEO larga y actualización de Principal si necesario  ===

        //09/04/2026 Una vez que tenemos todas las categorías hacemos un check para saber si debemos cambiar la principal y para seleccionar la categoría para la descripción larga en caso de que no deba ser la principal. La principal la tenemos en $resultado_principal['categoria_principal_id']
        $id_categoria_principal = $resultado_principal['categoria_principal_id'];

        //para ciertas categorías principales tipo: "Otros viedojuegos", "Otras series..."... no queremos que use ese nombre ni link en la descripción larga sino la padre, así que mapeamos esas categorías a las correspondientes utilizando $categorias_cambiar_desc_larga declarado arriba:
        // private $categorias_cambiar_desc_larga = [
        //     12 => 144,
        //     30 => 4,
        //     131 => 130,
        //     314 => 6
        // ];
        $id_categoria_descripcion_larga = $id_categoria_principal;

        //si la categoría principal es Otras temáticas 12, en lugar de Regalar es fácil 144 buscaremos la hija más alta de regalar es fácil. Por ejemplo, a un casco medieval, al no tener temática (licencia o personaje) se le marca otras temáticas, lo que direccionaría a Regalar es fácil, queremos que vaya a Armas de colección y recreación 210 que la tendría marcada por debajo de regalar es fácil
        //07/04/2026 Ahora, en el caso de que la principal sea la 12 (ahora llamada Regalos Frikis) le ponemos como principal también la más alta por debajo de Regalar es fácil si la hay, así no se llena la 12, y la categoría 12 no se añadirá al producto 

        foreach ($this->categorias_cambiar_desc_larga as $id_original => $id_destino) {
            if ((int) $id_original === (int) $id_categoria_principal) {
                //03/03/2026 Si la categoría es Otras temáticas (id 12) en lugar de asignar Regalar es fácil 144, buscamos la categoría hija de Regalar es fácil en posición más alta, deshechando Otras temáticas y Solo me puedo gastar (id 3) y las que tiene por debajo, que están en Regalar es fácil                
                if ($id_original == 12) {
                    //aquí $id_original = 12 y $id_destino = 144
                    //necesitamos las categorías que, entre las que vamos a asignar, estén por debajo de la 144
                    $ids_csv_subcats_regalar = implode(',', $resultado_subcats_regalar['subcategorias_regalar']);

                    //obtenemos las categorías por debajo de 144 que estén entre las seleccionadas como subcategorías regalar, con las exclusiones, ordenadas por profuncidad y haciendo getRow para quedarnos la primera
                    $sql = '
                            SELECT c.id_category, c.level_depth
                            FROM ' . _DB_PREFIX_ . 'category c
                            WHERE c.id_category IN (' . $ids_csv_subcats_regalar . ')
                            AND c.id_category NOT IN (' . (int) $id_destino . ', ' . (int) $id_original . ', 3, 13, 14, 15, 319)
                            AND (c.nleft > (
                                    SELECT nleft FROM ' . _DB_PREFIX_ . 'category WHERE id_category = ' . (int) $id_destino . '
                                )
                                AND c.nright < (
                                    SELECT nright FROM ' . _DB_PREFIX_ . 'category WHERE id_category = ' . (int) $id_destino . '
                                )
                            )
                            ORDER BY c.level_depth ASC                            
                            ';

                    $result = Db::getInstance()->getRow($sql);

                    if ($result) {
                        $id_categoria_descripcion_larga = (int) $result['id_category'];

                        $this->log("ID Categoría principal cambiado de $id_categoria_principal a $id_categoria_descripcion_larga", 'DEBUG');

                        //07/04/2026 Ahora, en el caso de que la principal sea la 12 (ahora llamada Regalos Frikis) le ponemos como principal también la más alta por debajo de Regalar es fácil si la hay, y la categoría 12 no se añadirá al producto 
                        $id_categoria_principal = $id_categoria_descripcion_larga;

                    } else {
                        //si no hay ninguna hija, devolvemos la 144
                        $id_categoria_descripcion_larga = $id_destino;
                    }

                } else {
                    $id_categoria_descripcion_larga = $id_destino;
                }

                break;
            }
        }
        // Hacemos update para sustituir la principal si es el caso, y almacenar la de descripción larga. La categoría 12 no se añadirá al producto
        $this->guardarCategoriaDescripcionLarga($id_product, $id_categoria_descripcion_larga, $id_categoria_principal);

        $this->log("Categoría para descripción larga: $id_categoria_descripcion_larga", 'INFO');

        // === 09/04/2026 Target (Edad, género) ===
        $info_target = $this->getInfoTarget($id_product);

        if (empty($info_target)) {
            $mensaje = "Error al obtener información para designar el target para el prompt para producto $id_product.";
            $this->marcarError($id_product, $mensaje);
            $this->log($mensaje, 'ERROR');
            return;
        } else {
            // Pedimos a la IA que seleccione el target para el producto
            $resultado_target = OpenAIClasificador::obtenerTarget(
                $info_target
            );

            // Si falla la llamada o no devuelve el campo esperado, marcamos error
            if (
                !$resultado_target
                || !isset($resultado_target['target_edad'])
                || !isset($resultado_target['target_genero'])
                || empty($resultado_target['target_edad'])
                || empty($resultado_target['target_genero'])               
            ) {
                $mensaje = "Error al obtener target edad y género para el producto $id_product.";
                $this->marcarError($id_product, $mensaje);
                $this->log($mensaje . ' - ' . json_encode($resultado_target, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'ERROR');
                return;
            }
            
            $this->guardarTarget($id_product, $resultado_target['target_edad'], $resultado_target['target_genero']);

            $this->log(sprintf(
                "Producto %d: Target edad = %s | Target género = %s",
                $id_product,
                $resultado_target['target_edad'],
                $resultado_target['target_genero']
            ));

            $this->log(sprintf(
                "Afinidad Mujer = %d | Afinidad Hombre = %d | Confianza = %d \nMotivo Comercial: %s",
                $resultado_target['afinidad_mujer'],
                $resultado_target['afinidad_hombre'],
                $resultado_target['confianza'],
                $resultado_target['motivo_comercial']
            ), 'DEBUG');
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
        $id_product = (int) $id_product;

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
            "id_product = {$id_product}"
        );

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
            'categoria_principal_id' => (int) $resultado['categoria_principal_id'],
            'tipo_producto_id' => (int) $resultado['tipo_producto_id'],
            'categoria_precio' => (int) $resultado['categoria_precio_id'],
            'target' => $resultado['target']
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

    //guardamos las categorías Amazon en lafrips_redactor_clasificador_categorias
    public function guardarCategoriasAmazon($id_product, $ids)
    {
        return $this->actualizarCampos($id_product, [
            'otras_categorias' => pSQL($ids),
        ], "Categorías Amazon guardadas para producto {$id_product}");
    }

    public function guardarTarget($id_product, $target_edad, $resultado_target_genero)
    {
        return $this->actualizarCampos($id_product, [
            'target_edad' => $target_edad,
            'target_genero' => $resultado_target_genero
        ], "Target Edad y Género para producto {$id_product} clasificado correctamente");
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
            'estado' => 'error',
            'estado_fecha' => $ahora,
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
            'estado_fecha' => $ahora,
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
        $limite_timestamp = date('Y-m-d H:i:s', time() - $this->reset_procesando_time);

        // Solo productos en estado 'procesando' que han superado el tiempo límite
        $sql = "SELECT id_product, mensaje_error 
                FROM " . _DB_PREFIX_ . "{$this->tabla}
                WHERE estado = 'procesando'
                AND inicio_proceso < '{$limite_timestamp}'";

        $productos = Db::getInstance()->executeS($sql);

        if (!$productos || empty($productos)) {
            return; // Nada que resetear
        }

        $total = count($productos);
        $this->log("---------- Reinicio de productos estancados ----------", 'WARNING');
        $this->log("Total de productos reiniciados por timeout: {$total}", 'WARNING');

        foreach ($productos as $producto) {
            $id_product = (int) $producto['id_product'];
            $mensaje_anterior = $producto['mensaje_error'];
            $mensaje_nuevo = pSQL(trim($mensaje_anterior . ' | Reiniciado ' . date('Y-m-d H:i:s')));

            Db::getInstance()->update($this->tabla, [
                'estado' => 'pendiente',
                'estado_fecha' => date('Y-m-d H:i:s'),
                'en_cola' => 1,
                'inicio_proceso' => '0000-00-00 00:00:00',
                'mensaje_error' => $mensaje_nuevo,
                'date_upd' => date('Y-m-d H:i:s'),
            ], "id_product = {$id_product}");

            $this->log("Reiniciado producto {$id_product} por timeout.", 'WARNING');
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
            WHERE cat.id_parent IN (" . implode(',', $this->categorias_padres_principales) . ")
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
        $id_categoria_principal = (int) $id_categoria_principal;

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
            SELECT 
                cat.id_category,
                cla.name
            FROM lafrips_category cat
            INNER JOIN lafrips_category_lang cla 
                ON cat.id_category = cla.id_category
            WHERE cat.id_parent = $id_category
            AND cla.id_lang = 1
            AND cat.id_category NOT IN (3, 107)
            ORDER BY cla.name            
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

    //obtenemos las categorías bajo la categoría Exportar Amazon id 3070, que son Nuevos Amazon, No subir Amazon, No Amazon ES, No Amazon IT, etc
    public function getCategoriasAmazon()
    {
        $sql = "
            SELECT cat.id_category, cla.name
            FROM lafrips_category cat
            JOIN lafrips_category_lang cla ON cat.id_category = cla.id_category
            WHERE cat.id_parent = 3070
            AND cla.id_lang = 1
            ORDER BY cla.name ASC
        ";

        return Db::getInstance()->executeS($sql);
    }

    //obtenemos algo de información extra que no tenemos disponible para ofrecer al prompt que asignará las categorías Amazon. Por ejemplo, fabricante, proveedor, los valores ya asignados como tipo de producto, categorías, etc
    public function getInfoExtra($id_product)
    {
        $sql = "
            SELECT 
                sup.name AS proveedor, 
                man.name AS fabricante, 
                fvl.value AS tipo, 
                cla.name AS categoria_principal,

                GROUP_CONCAT(DISTINCT cla_sp.name SEPARATOR ', ') AS subcategorias_principal,
                GROUP_CONCAT(DISTINCT cla_cr.name SEPARATOR ', ') AS categorias_regalar,
                GROUP_CONCAT(DISTINCT cla_sr.name SEPARATOR ', ') AS subcategorias_regalar

            FROM lafrips_product pro

            JOIN lafrips_redactor_clasificador_categorias rcc 
                ON rcc.id_product = pro.id_product

            JOIN lafrips_supplier sup
                ON sup.id_supplier = pro.id_supplier

            JOIN lafrips_manufacturer man
                ON man.id_manufacturer = pro.id_manufacturer

            JOIN lafrips_category_lang cla
                ON cla.id_category = rcc.categoria_principal_id
                AND cla.id_lang = 1

            LEFT JOIN lafrips_feature_value_lang fvl 
                ON fvl.id_feature_value = rcc.tipo_producto_id
                AND fvl.id_lang = 1   

            -- subcategorias_principal
            LEFT JOIN lafrips_category_lang cla_sp
                ON FIND_IN_SET(cla_sp.id_category, rcc.subcategorias_principal)
                AND cla_sp.id_lang = 1

            -- categorias_regalar
            LEFT JOIN lafrips_category_lang cla_cr
                ON FIND_IN_SET(cla_cr.id_category, rcc.categorias_regalar)
                AND cla_cr.id_lang = 1

            -- subcategorias_regalar
            LEFT JOIN lafrips_category_lang cla_sr
                ON FIND_IN_SET(cla_sr.id_category, rcc.subcategorias_regalar)
                AND cla_sr.id_lang = 1

            WHERE pro.id_product = " . (int) $id_product . "

            GROUP BY pro.id_product
        ";

        return Db::getInstance()->getRow($sql);
    }

    // obtenemos la info para enviar a GPT para que decida el target del producto. Le vamos a enviar las categorias y tipo de producto, pero estos aún no han sido asignados al producto, de modo que obtenemos los ids de la tabla lafrips_redactor_clasificador_categorias y luego buscamos sus nombres, también para el tipo de producto
    public function getInfoTarget($id_product)
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
                attrs.atributos_combinaciones,
                rcc.categoria_principal_id,
                rcc.tipo_producto_id,
                rcc.subcategorias_principal,
                rcc.categorias_regalar,
                rcc.subcategorias_regalar
            FROM lafrips_product pro
            JOIN lafrips_product_lang pla
                ON pla.id_product = pro.id_product
            AND pla.id_lang = 1
            LEFT JOIN lafrips_manufacturer man
                ON man.id_manufacturer = pro.id_manufacturer
            LEFT JOIN lafrips_redactor_clasificador_categorias rcc
                ON rcc.id_product = pro.id_product
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
                AND atl.id_lang = 1
                GROUP BY pat.id_product
            ) attrs
                ON attrs.id_product = pro.id_product
            WHERE pro.id_product = ' . $id_product;

        $row = Db::getInstance()->getRow($sql);

        if (empty($row)) {
            return array();
        }

        $categoriaPrincipal = $this->getCategoryNameById($row['categoria_principal_id']);
        $tipoProducto = $this->getFeatureValueNameById($row['tipo_producto_id']);

        $otrasCategoriasArray = array_merge(
            $this->getCategoryNamesFromCsv($row['subcategorias_principal']),
            $this->getCategoryNamesFromCsv($row['categorias_regalar']),
            $this->getCategoryNamesFromCsv($row['subcategorias_regalar'])
        );

        $otrasCategoriasArray = array_values(array_unique(array_filter($otrasCategoriasArray)));
        $otrasCategorias = !empty($otrasCategoriasArray) ? implode(' | ', $otrasCategoriasArray) : '';

        return array(
            'id_product' => (int) $row['id_product'],
            'nombre' => !empty($row['nombre']) ? trim($row['nombre']) : '',
            'descripcion' => $this->cleanHtmlForGpt($row['description_short']),
            'marca' => !empty($row['marca']) ? trim($row['marca']) : '',
            'precio' => isset($row['precio_sin_iva']) ? (float) $row['precio_sin_iva'] : 0,
            'combinaciones' => !empty($row['atributos_combinaciones']) ? trim($row['atributos_combinaciones']) : '',
            'categoria_principal' => $categoriaPrincipal,
            'tipo_producto' => $tipoProducto,
            'otras_categorias' => $otrasCategorias,
        );

        /* 
        quedaría un json 

        {
            "id_product": 84850,
            "nombre": "Figura Banpresto...",
            "description_short": "Texto limpio...",
            "marca": "Banpresto",
            "precio": 24.95,
            "combinaciones": "Rojo | Azul",
            "categoria_principal": "One Piece",
            "tipo_producto": "Figura",
            "otras_categorias": "Luffy | Regalos para adolescentes | Figuras anime"
            ]
        }
        */
    }

    // === helpers para function getInfoTarget()
    protected function getCategoryNameById($id_category)
    {
        $id_category = (int) $id_category;

        if ($id_category <= 0) {
            return '';
        }

        $sql = '
            SELECT cl.name
            FROM lafrips_category_lang cl
            WHERE cl.id_category = ' . $id_category . '
            AND cl.id_lang = 1';

        $name = Db::getInstance()->getValue($sql);

        return $name ? trim($name) : '';
    }

    protected function getFeatureValueNameById($id_feature_value)
    {
        $id_feature_value = (int) $id_feature_value;

        if ($id_feature_value <= 0) {
            return '';
        }

        $sql = '
            SELECT fvl.value
            FROM lafrips_feature_value_lang fvl
            WHERE fvl.id_feature_value = ' . $id_feature_value . '
            AND fvl.id_lang = 1';

        $value = Db::getInstance()->getValue($sql);

        return $value ? trim($value) : '';
    }

    protected function getCategoryNamesFromCsv($csv)
    {
        $ids = $this->csvToIntArray($csv);

        if (empty($ids)) {
            return array();
        }

        $sql = '
            SELECT cl.name
            FROM lafrips_category_lang cl
            WHERE cl.id_lang = 1
            AND cl.id_category IN (' . implode(',', $ids) . ')
            ORDER BY cl.name ASC';

        $rows = Db::getInstance()->executeS($sql);

        if (empty($rows)) {
            return array();
        }

        $names = array();

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $names[] = trim($row['name']);
            }
        }

        return $names;
    }

    protected function csvToIntArray($csv)
    {
        if (empty($csv)) {
            return array();
        }

        $parts = explode(',', $csv);
        $ids = array();

        foreach ($parts as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

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
        $referencia_actual = Db::getInstance()->getValue("SELECT reference FROM lafrips_product WHERE id_product = " . (int) $id_product);

        //si la referencia tiene formato válido y no comienza por ZZZ comprobamos si existe para utilizarla, si no, si tiene otro formato o empieza por zzz, pasamos a generar una en base a categoría principal y fecha
        if ($referencia_actual && preg_match('/^(?!ZZZ)[A-Z]{3}[0-9]{8}$/', $referencia_actual)) {
            // Comprobamos si está repetida (salvo por el propio producto)
            $sql_check = "
                SELECT COUNT(*) 
                FROM lafrips_product 
                WHERE reference = '" . pSQL($referencia_actual) . "' 
                AND id_product != " . (int) $id_product;

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


    public function guardarReferencia($id_product, $referencia)
    {
        return $this->actualizarCampos($id_product, [
            'referencia' => pSQL($referencia),
        ], "Referencia guardada para producto {$id_product}");
    }

    public function guardarCategoriaDescripcionLarga($id_product, $id_categoria_descripcion_larga, $id_categoria_principal)
    {
        return $this->actualizarCampos($id_product, [
            'categoria_principal_id' => (int) $id_categoria_principal,
            'categoria_seo_desc_larga' => (int) $id_categoria_descripcion_larga
        ], "Categoría para SEO descripción larga guardada para producto {$id_product}");

    }

    /**
     * Aplica la clasificación obtenida al producto real de Prestashop:
     * - Asigna categoría principal y subcategorías.
     * - Establece categoría por defecto.
     * - Guarda tipo de producto como característica (id_feature = 8).
     * - Genera descripción larga con texto SEO usando OpenAI.
     * - La categoría 12 no se añadirá al producto si ha sido seleccionada
     *
     * Si algo falla, se marca el producto como 'clasificado_sin_aplicar'.
     *
     * @param int $id_product
     */
    public function actualizarProductoConClasificacion($id_product)
    {
        try {
            // 1. Recuperamos datos de la tabla auxiliar
            $sql = "SELECT referencia, target_edad, target_genero, categoria_principal_id, subcategorias_principal, tipo_producto_id,
                        categoria_precio, categorias_regalar, subcategorias_regalar, categoria_seo_desc_larga, otras_categorias
                    FROM " . _DB_PREFIX_ . "{$this->tabla}
                    WHERE id_product = " . (int) $id_product;

            $datos = Db::getInstance()->getRow($sql);

            if (!$datos) {
                throw new Exception("No se encontró la fila auxiliar en " . _DB_PREFIX_ . "{$this->tabla} con la clasificación del producto $id_product.");
            }

            $referencia = $datos['referencia'];

            $target_edad = $datos['target_edad'];
            $target_genero = $datos['target_genero'];

            // Convertir campos a arrays y valores enteros
            $id_categoria_principal = (int) $datos['categoria_principal_id'];
            $id_tipo_producto = (int) $datos['tipo_producto_id'];
            $id_categoria_precio = (int) $datos['categoria_precio'];
            $id_categoria_seo_desc_larga = (int) $datos['categoria_seo_desc_larga'];

            $subcategorias_principal = !empty($datos['subcategorias_principal']) ? array_filter(array_map('intval', explode(',', $datos['subcategorias_principal']))) : [];
            $categorias_regalar = !empty($datos['categorias_regalar']) ? array_filter(array_map('intval', explode(',', $datos['categorias_regalar']))) : [];
            $subcategorias_regalar = !empty($datos['subcategorias_regalar']) ? array_filter(array_map('intval', explode(',', $datos['subcategorias_regalar']))) : [];
            $otras_categorias = !empty($datos['otras_categorias']) ? array_filter(array_map('intval', explode(',', $datos['otras_categorias']))) : [];


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

                //comprobamos los campos del producto antes de guardar
                $validateFields = $product->validateFields(UNFRIENDLY_ERROR, true);
                $validateFieldsLang = $product->validateFieldsLang(UNFRIENDLY_ERROR, true);

                $errors = array_merge(
                    is_array($validateFields) ? $validateFields : [],
                    is_array($validateFieldsLang) ? $validateFieldsLang : []
                );

                if (!empty($errors)) {
                    $error_validacion = '';

                    foreach ($errors as $error) {
                        $error_validacion .= "Error: $error\n";
                    }

                    throw new Exception("Errores de validación de campos guardando categoría principal y referencia (si aplica) para el producto $id_product. Errores: " . $error_validacion);
                } elseif (!$product->save()) {
                    throw new Exception("No se pudo guardar la categoría principal $id_categoria_principal (y referencia $referencia si aplica) para el producto $id_product.");
                }
            }

            //comprobamos si el producto tiene combinaciones y si es así actualizamos las referencias de combinación a la nueva referencia
            $this->actualizarReferenciaCombinaciones($id_product, $referencia);

            // 4. Asignar todas las categorías al producto (incluimos la categoría de precio) comprobando existencia. Las categorías que tenga el producto en prestashop se eliminan al hacer $product->updateCategories();
            //30/07/2025 Por ahora forzamos categoría Amazon Nuevos id 2356 hasta que arreglemos un prompt
            //23/03/2026 quitamos la categoría de amazon de aquí, ya tiene su prompt y las de amazon irían en $otras_categorias
            //09/04/2026 La categoría 12 no se añadirá al producto
            $categorias_total = array_unique(array_merge(
                $id_categoria_principal ? [$id_categoria_principal] : [],
                $id_categoria_precio ? [$id_categoria_precio] : [],
                $id_categoria_seo_desc_larga ? [$id_categoria_seo_desc_larga] : [],
                $subcategorias_principal,
                $categorias_regalar,
                $subcategorias_regalar,
                $otras_categorias
            ));
            // Filtramos las categorías existentes (por si GPT se ha inventado alguna)
            if (!empty($categorias_total)) {
                $categorias_str = implode(',', array_map('intval', $categorias_total));
                $sql_existentes = "SELECT id_category FROM lafrips_category WHERE id_category IN ($categorias_str)";
                $categorias_existentes = array_column(Db::getInstance()->executeS($sql_existentes), 'id_category');

                // $this->log("categorias_existentes = " . implode(', ', $categorias_existentes), 'DEBUG');

                if (!empty($categorias_existentes)) {
                    $ids_actuales = Product::getProductCategories($id_product);

                    // $this->log("categorias_actuales antes de asignar = " . implode(', ', $ids_actuales), 'DEBUG');

                    if (array_diff($categorias_existentes, $ids_actuales) || array_diff($ids_actuales, $categorias_existentes)) {
                        $this->log("Producto $id_product: Categorías a aplicar -> " . implode(', ', $categorias_existentes), 'INFO');

                        //nos aseguramos de eliminar la categoría 12
                        $categorias_existentes = array_diff($categorias_existentes, [12]);

                        //se ponen las categorías que existen de las que tenemos para asignar
                        $product->updateCategories($categorias_existentes);

                        // Comprobar qué categorías tiene realmente el producto, $product->updateCategories() no devuelve nada (void) de modo que podemos asegurarnos de que se hayan actualizado las categorías (elimina las que haya y las sustituye por las nuevas) comparando las que tiene ahora con las que queríamos meter
                        //usamos una sql porque Product::getProductCategories($product->id); utiliza caché y devuelve todavía las anteriores
                        $categorias_actuales = Db::getInstance()->executeS('
                            SELECT id_category 
                            FROM ' . _DB_PREFIX_ . 'category_product 
                            WHERE id_product = ' . (int) $product->id
                        );
                        $categorias_ids = array_column($categorias_actuales, 'id_category');
                        $ids = implode(',', array_map('intval', $categorias_ids));

                        // $this->log("categorias_actuales después de asignar = " . $ids, 'DEBUG');

                        // Verificamos si coinciden con las que intentamos establecer
                        // $coinciden = (count(array_diff($categorias_existentes, $categorias_actuales)) === 0)
                        //     && (count(array_diff($categorias_actuales, $categorias_existentes)) === 0);

                        // if (!$coinciden) {
                        //     throw new Exception("No se actualizaron correctamente las categorías a asignar a $id_product.\n categorias_existentes = ".implode(', ', $categorias_existentes)."\n categorias_actuales = ".implode(', ', $categorias_actuales));
                        // }                      

                    }
                }
            }

            // 5a. Asignar tipo de producto como característica (id_feature = 8)
            if ($id_tipo_producto > 0) {
                $existe_valor = Db::getInstance()->getValue("SELECT COUNT(*) FROM lafrips_feature_value WHERE id_feature_value = $id_tipo_producto AND id_feature = 8");
                if ($existe_valor) {

                    $product->deleteProductFeatures();

                    if (!Product::addFeatureProductImport($id_product, 8, $id_tipo_producto)) {
                        throw new Exception("No se pudo asignar la característica tipo de producto a $id_product.");
                    }
                    $this->log("Producto $id_product: Tipo producto aplicado -> " . $id_tipo_producto, 'INFO');
                } else {
                    $this->log("ID de tipo de producto no válido para el producto $id_product: $id_tipo_producto", 'ERROR');
                }
            }

            // 5b. Asignar target Edad (id_feature = 9)
            if ($target_edad !== '') {
                //buscamos por el nombre
                $id_feature_value = Db::getInstance()->getValue("SELECT fvl.id_feature_value 
                    FROM lafrips_feature_value_lang fvl
                    JOIN lafrips_feature_value fev 
                        ON fev.id_feature_value = fvl.id_feature_value
                    WHERE fvl.id_lang = 1
                    AND fev.id_feature = 9
                    AND fvl.value = '" . $target_edad . "'");

                if ($id_feature_value) {
                    if (!Product::addFeatureProductImport($id_product, 9, $id_feature_value)) {
                        throw new Exception("No se pudo asignar la característica Edad (" . $target_edad . ") a $id_product.");
                    }
                    $this->log("Producto $id_product: Target edad aplicado -> " . $target_edad . " id " . $id_feature_value, 'INFO');
                } else {
                    $this->log("Target de edad no válido para el producto $id_product: $target_edad", 'ERROR');
                }
            }

            // 5c. Asignar target Género (id_feature = 17)                     
            if ($target_genero !== '') {
                //buscamos por el nombre
                $id_feature_value = Db::getInstance()->getValue("SELECT fvl.id_feature_value 
                    FROM lafrips_feature_value_lang fvl
                    JOIN lafrips_feature_value fev 
                        ON fev.id_feature_value = fvl.id_feature_value
                    WHERE fvl.id_lang = 1
                    AND fev.id_feature = 17
                    AND fvl.value = '" . $target_genero . "'");

                if ($id_feature_value) {
                    if (!Product::addFeatureProductImport($id_product, 17, $id_feature_value)) {
                        throw new Exception("No se pudo asignar la característica Target Género (" . $target_genero . ") a $id_product.");
                    }
                    $this->log("Producto $id_product: Target género aplicado -> " . $target_genero . " id " . $id_feature_value, 'INFO');
                } else {
                    $this->log("Target de génro no válido para el producto $id_product: $target_genero", 'ERROR');
                }
            }

            // 6. Generar descripción larga con texto SEO            
            // Obtener nombre y enlace de la categoría para descripción larga          

            // Mapeo de idiomas: id_lang => clave idioma
            $idiomas = [
                1 => 'es',
                11 => 'en',
                12 => 'fr',
                18 => 'pt'
            ];

            $links_y_nombres_categoria = $this->getCategoryLinksByLanguages($id_categoria_seo_desc_larga, $idiomas);

            //preparamos un json que pasaremos a la IA como variable
            $json_links_y_nombres_categoria = json_encode($links_y_nombres_categoria, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            // Obtener descripción larga con texto SEO
            $texto_seo = OpenAIClasificador::obtenerTextoSeoCategoria($json_links_y_nombres_categoria);

            if (!$texto_seo || !isset($texto_seo['es'])) {
                throw new Exception("No se obtuvo el texto SEO de la IA para la descripción larga.");
            }

            // Actualizar descripción por idioma
            //23/03/2026 Por si metemos algún video en la descripción larga, comprobamos si hay algo antes, si lo hay añadimos el texto SEO después
            foreach ($idiomas as $id_lang => $codigo) {
                $desc = isset($texto_seo[$codigo]) ? $texto_seo[$codigo] : $texto_seo['es']; // fallback español

                //si hacemos varias pasadas del clasificador, se irán añadiendo bloque trás bloque ya que encontrará siempre algo dentro, de modo que "rodeamos" la descripción SEO con estas etiquetas, y si encuentra algo, lo que hace es sustituir el contenido.
                $marca_inicio = '<!-- SEO_AUTO_START -->';
                $marca_fin = '<!-- SEO_AUTO_END -->';

                // Bloque SEO marcado
                $bloqueSeo = $marca_inicio . $desc . $marca_fin;

                // Descripción actual
                $currentDesc = isset($product->description[$id_lang])
                    ? trim($product->description[$id_lang])
                    : '';

                if (!empty($currentDesc)) {

                    // Eliminar bloque anterior si existe (para no duplicar)
                    if (strpos($currentDesc, $marca_inicio) !== false) {
                        $currentDesc = preg_replace(
                            '/<!-- SEO_AUTO_START -->.*?<!-- SEO_AUTO_END -->/s',
                            '',
                            $currentDesc
                        );

                        // Limpiar saltos HTML sobrantes al final
                        $currentDesc = preg_replace('/(?:\s|&nbsp;|<br\s*\/?>|<\/p>|<p>)+$/i', '', $currentDesc);

                        // Limpiar saltos HTML sobrantes al principio, por si acaso
                        $currentDesc = preg_replace('/^(?:\s|&nbsp;|<br\s*\/?>|<\/p>|<p>)+/i', '', $currentDesc);

                        $currentDesc = trim($currentDesc);
                    }

                    // Añadir nuevo bloque debajo solo si queda contenido previo
                    $product->description[$id_lang] = !empty($currentDesc)
                        ? $currentDesc . '<br><br>' . $bloqueSeo
                        : $bloqueSeo;

                } else {
                    // No hay nada previo
                    $product->description[$id_lang] = $bloqueSeo;
                }
            }

            //comprobamos los campos del producto antes de guardar
            $validateFields = $product->validateFields(UNFRIENDLY_ERROR, true);
            $validateFieldsLang = $product->validateFieldsLang(UNFRIENDLY_ERROR, true);

            $errors = array_merge(
                is_array($validateFields) ? $validateFields : [],
                is_array($validateFieldsLang) ? $validateFieldsLang : []
            );

            if (!empty($errors)) {
                $error_validacion = '';

                foreach ($errors as $error) {
                    $error_validacion .= "Error: $error\n";
                }

                throw new Exception("Errores de validación de campos guardando texto descripción larga para el producto $id_product. Errores: " . $error_validacion);

            } elseif (!$product->save()) {
                throw new Exception("No se pudo guardar la descripción larga en product_lang para el producto $id_product.");
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
     * Sustituye el prefijo de las reference de combinación manteniendo el sufijo. 
     * Ej:
     *   ZZZ00001234-XL_ROJO  => HAR26022701-XL_ROJO
     * Comprueba si es producto con combinaciones.
     * Lo hace buscando el primer guión de la referencia.
     */
    public function actualizarReferenciaCombinaciones($id_product, $referencia)
    {
        $referencia = trim((string) $referencia);

        // Obtener combinaciones
        $combinations = Db::getInstance()->executeS(
            'SELECT id_product_attribute, reference 
            FROM ' . _DB_PREFIX_ . 'product_attribute 
            WHERE id_product = ' . (int) $id_product
        );

        if (empty($combinations)) {
            $this->log("El producto $id_product no tiene combinaciones.", 'INFO');

            return;
        }

        foreach ($combinations as $comb) {

            $old_referencia = $comb['reference'];
            $id_product_attribute = (int) $comb['id_product_attribute'];

            if (empty($old_referencia)) {
                $this->log("El producto $id_product tiene combinaciones pero no tiene referencia para id_product_attribute " . $id_product_attribute, 'ERROR');

                continue;
            }

            $pos = strpos($old_referencia, '-');

            if ($pos === false) {
                $this->log("El producto $id_product tiene combinaciones pero la referencia para id_product_attribute " . $id_product_attribute . " - " . $old_referencia . " - no tiene guion para la partición", 'ERROR');

                continue;
            }

            $suffix = substr($old_referencia, $pos);
            $nueva_referencia = $referencia . $suffix;

            // Cargar objeto combinación
            $combination = new Combination($id_product_attribute);
            $combination->reference = $nueva_referencia;

            if ($combination->update()) {
                $this->log("Referencia de combinación de producto $id_product para id_product_attribute " . $id_product_attribute . " actualizada - " . $old_referencia . " - " . $nueva_referencia, 'INFO');
            } else {
                $this->log("Referencia de combinación de producto $id_product para id_product_attribute " . $id_product_attribute . " no pudo actualizarse - " . $old_referencia . " - " . $nueva_referencia, 'ERROR');
            }
        }

        return;
    }

    //función que recibe el id de una categoría y un array de id_lang => iso y devuelve un array con el nombre y link de dicha categoría para cada idioma
    /*
        [
            'es' => [
                'nombre' => 'Nombre de la categoría en español',
                'link'   => 'https://tu-dominio.com/es/5-nombre-de-la-categoria'
            ],
            'en' => [
                'nombre' => 'Category name in English',
                'link'   => 'https://tu-dominio.com/en/5-category-name'
            ],
            'fr' => [
                'nombre' => 'Nom de la catégorie en français',
                'link'   => 'https://tu-dominio.com/fr/5-nom-de-la-categorie'
            ],
            'pt' => [
                'nombre' => 'Nome da categoria em português',
                'link'   => 'https://tu-dominio.com/pt/5-nome-da-categoria'
            ]
        ]
    */
    public function getCategoryLinksByLanguages($id_category, $idiomas)
    {
        $link = new Link();
        $category = new Category($id_category);
        $links_categoria = [];

        foreach ($idiomas as $id_lang => $iso) {
            $links_categoria[$iso] = [
                'nombre' => $category->name[$id_lang],
                'link' => $link->getCategoryLink($id_category, null, $id_lang)
            ];
        }

        return $links_categoria;
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

        $id_product = (int) $id_product;

        if (!$id_product) {
            return [
                'success' => false,
                'message' => "ID de producto no válido para proceso clasificación: $id_product"
            ];
        }

        try {
            //si no llega id_employee ponemos automatizador
            $id_employee = $id_employee !== null ? (int) $id_employee : 44;

            $ahora = date('Y-m-d H:i:s');

            $existe = Db::getInstance()->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . $tabla . " WHERE id_product = $id_product");

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
                'message' => "Error al encolar para clasificar el producto $id_product: " . $e->getMessage()
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
        $id_employee = $id_employee !== null ? (int) $id_employee : 44;

        $resultados = [];

        foreach ($ids_product as $id_product) {
            $resultado = self::encolarProductoClasificacion((int) $id_product, $id_employee);
            $resultados[$id_product] = $resultado;
        }

        return $resultados;
    }


}
