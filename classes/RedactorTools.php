<?php
/**
* 2007-2020 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2020 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

require_once(dirname(__FILE__).'/ClasificadorCategoriaManager.php');

//02/01/2025 Metemos en esta calse las funciones comunes al uso de cualquier api de redacción, sea Redacta.me o OpenAI, como revisar el producto, actualizar o guardar la nueva descripción, etc

class RedactorTools
{     

    //28/02/2024 Función que guardará la descripción generada, ya sea si el producto se ha procesado en Cola de descipciones, o si se ha procesado visualmente desde el front del módulo (controlador), en cuyo caso también recibirá el nombre del producto por si el usuario lo ha modificado. No debe marcar Redactado, eso debe hacerse solo cuando se genera la descripción con API, ya sea en cola de redacción o manualmente desde el front.
    //damos la opción del parámtreo nombre vacío porque el nombre por cola de redacción no se modifica, pero manualmente si se puede cambiar.    
    public static function actualizaProducto($id_product, $id_lang, $descripcion, $nombre = "", $atributo_alt = "", $metatitulo = "", $metadescripcion = "") {
            // $parametros = ['id_product' => $id_product, 'id_lang' => $id_lang,'descripcion' => $descripcion,'nombre' => $nombre,'descripcion_categoria' => $descripcion_categoria,'metatitulo' => $metatitulo,'metadescripcion' => $metadescripcion];
        // file_put_contents(__DIR__ . "/actualizaProducto.txt", print_r($parametros, true), FILE_APPEND);
        //instanciamos el producto para actualizar descripción y/o nombre, solo para id_lang 1
        $product = new Product($id_product);
        // para descripciones cuando solo queremos afectar a un lenguaje
        $product->description_short[$id_lang] = $descripcion;  

        if ($nombre !== "") {
            $product->name[$id_lang] = $nombre;
        }  

        if ($nombre !== "") {
            $product->meta_title[$id_lang] = $metatitulo;
        }  

        if ($nombre !== "") {
            $product->meta_description[$id_lang] = $metadescripcion;
        }  

        try {
            if (!$product->update()) {
                // Si devuelve false, lanza una excepción manual
                throw new Exception("No se pudieron actualizar los textos para el producto con ID {$id_product}");
            }    
            
            //15/07/2025 Ahora guardamos el texto para el atributo alt de las imágenes, para $id_lang, lo hacemos por separado ya que afecta a otras tablas
            if (!self::actualizarAltImagenesProducto($id_product, $id_lang, $atributo_alt)) {
                // Si devuelve false, lanza una excepción manual
                throw new Exception("No se pudieron actualizar los atributos alt de imágenes para el producto con ID {$id_product}");
            }

            //guardamos la descripción por ahora para analizar errores
            //07/01/2025, de moemnto dejamos de guardar si no hay error
            // $sql_descripcion = "UPDATE lafrips_redactor_descripcion
            // SET                
            // descripcion = '$descripcion'
            // WHERE id_product = $id_product";

            // Db::getInstance()->execute($sql_descripcion);   
            
            return true;

        } catch (Exception $e) {
            $error_message = "Excepción guardando textos: ".pSQL($e->getMessage());

            //guardamos por ahora la descripción fallida para analizar errores
            $descripcion = 'Error exception. '.$descripcion;

            $sql_descripcion = "UPDATE lafrips_redactor_descripcion
            SET         
            descripcion = '$descripcion'
            WHERE id_product = $id_product";

            Db::getInstance()->execute($sql_descripcion); 

            return $error_message;
        }

    }

    public static function actualizarAltImagenesProducto($id_product, $id_lang, $texto_alt)
    {
        try {
            if (!(int)$id_product || !(int)$id_lang || empty($texto_alt)) {
                throw new Exception("Faltan datos necesarios para actualizar el atributo alt.");
            }

            // Obtener todas las imágenes del producto
            $imagenes = Db::getInstance()->executeS("
                SELECT id_image FROM "._DB_PREFIX_."image
                WHERE id_product = ".(int)$id_product
            );

            if (!$imagenes) {
                throw new Exception("No se encontraron imágenes para el producto $id_product.");
            }

            foreach ($imagenes as $imagen) {
                $id_image = (int)$imagen['id_image'];

                // Comprobamos si ya existe esa fila en image_lang
                $existe = Db::getInstance()->getValue("
                    SELECT COUNT(*) FROM "._DB_PREFIX_."image_lang
                    WHERE id_image = $id_image AND id_lang = $id_lang
                ");

                if ($existe) {
                    // Actualizar
                    Db::getInstance()->update('image_lang', [
                        'legend' => pSQL($texto_alt)
                    ], "id_image = $id_image AND id_lang = $id_lang");
                } else {
                    // Insertar
                    Db::getInstance()->insert('image_lang', [
                        'id_image' => $id_image,
                        'id_lang' => $id_lang,
                        'legend' => pSQL($texto_alt)
                    ]);
                }
            }

            return true;

        } catch (Exception $e) {
            return "Error actualizando atributo alt de imágenes de producto $id_product idioma $id_lang: ".$e->getMessage();
        }
    }


    //función para marcar como redactado un producto, se separa de la función de marcar como revisado porque un producto podemos redactarlo de forma automática desde la cola de redacción o manualmente desde el controlador. Pero revisarlo solo se hace desde el controlador y si el producto fue redactado con la cola, tenemos que poder marcar solo revisado, pero si fue redactado pidiéndolo manualmente desde el controlador, debemos poder marcar ambos, de modo que llamaremos a una sola función o ambas dependiendo.
    //si estamos marcando un producto como redactado = 1, significa que está recién redactado y por tanto no revisado, pondremos revisado = 0, del mismo modo, si un producto consideramos que no está redactado, también debemos marcarlo como revisado = 0. Es decir, marcar un producto el parámetro redactado siempre implica resetear revisado, no así marcar revisado, que depende de si ya estaba redactado, como cuando viene de la cola de redacción, o si estamos revisando un producto del que acabamos de generar su descripción desde el controlador y por tanto aún no ha sido guardada, en cuyo caso, al marcar revisado también marcaremos redactado (mismo usuario)
    //07/03/2024 Solo marcaremos redactado cuando se genera la descripción por la API, es decir, al guardarla. De modo que se puede marcar revisado un producto que no fue redactado, porque a veces se utiliza este módulo para modificar descripciones de productos que no han sido generadas con él.
    //14/07/2025 Hemos preparado el proceso que categorizará los productos a partir de la descripción. Por ahora, cuando se marque un producto como redactado, se insertará el mismo en la tabla lafrips_redactor_clasificador_categorias y se colocará en cola, o en caso de ya existir se pondrá en cola. Llamaremos a una función encolarProductoClasificacion() en ClasificadorCategoriaManager.php
    public static function updateTablaRedactorRedactado($redactado, $id_product, $error_message = "") {

        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }

        if ($redactado) {
            //ponemos en redactado_api lo que haya en api, de modo que si se utiliza una api eso pondrá 
            $sql_redactado = " redactado = 1,
            redactado_api = api,
            id_employee_redactado = $id_employee, 
            date_redactado = NOW(),            
            error = 0, ";

            //14/07/2025 encolamos el producto para categorizar
            $resultado = ClasificadorCategoriaManager::encolarProductoClasificacion($id_product, $id_employee);

            //si no se encoló metemos un mensaje pero continuamos
            if (!$resultado['success']) {
                // Registrar error 
                $sql_redactado .= " error_message = CONCAT(error_message, ' | ".$resultado['message']." - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')), ";
            }

        } else {
            if ($error_message) {
                $update_error_message = "  error = 1,
                date_error = NOW(),
                error_message = CONCAT(error_message, ' | Descripción generada OK pero NO guardada - $error_message - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')), ";
            } else {
                $update_error_message = "";
            }

            $sql_redactado = "
            redactado = 0,
            id_employee_redactado = 0, 
            date_redactado = '0000-00-00 00:00:00', 
            ".$update_error_message;  
        }        
        
        //insertamos fecha y empleado de redactar en lafrips_redactor_descripcion. Marcamos revisado = 0 porque siempre que se marque redactado se necesita un revisado
        $sql_update_redactado = "UPDATE lafrips_redactor_descripcion
        SET                
        procesando = 0,
        inicio_proceso = '0000-00-00 00:00:00',
        en_cola = 0,        
        $sql_redactado  
        revisado = 0, 
        date_revisado = '0000-00-00 00:00:00',
        id_employee_revisado = 0,                   
        date_upd = NOW()
        WHERE id_product = $id_product";

        Db::getInstance()->execute($sql_update_redactado); 

        return;
    }

    //función para marcar como revisado un producto, se separa de la función de marcar como redactado porque un producto podemos redactarlo de forma automatica desde la cola de redacción o manualmente desde el controlador. Pero revisarlo solo se hace desde el controlador y si el producto fue redactado con la cola, tenemos que poder marcar solo revisado, pero si fue redactado pidiendolo manualmente desde el controlador, debemos poder marcar ambos, de modo que llamaremos a una sola función o ambas dependiendo.    
    //marcar un producto el parámetro redactado siempre implica resetear revisado, no así marcar revisado, que depende de si ya estaba redactado, como cuando viene de la cola de redacción, o si estamos revisando un producto del que acabamos de generar su descripción desde el controlador y por tanto aún no ha sido guardada, en cuyo caso, al marcar revisado también marcaremos redactado (mismo usuario). Por eso, si generamos una descripción desde el controlador, al marcar revisar, primero llamaremos a updateTablaRedactorRedactado() y seguido a updateTablaRedactorRevisado()
    //07/03/2024 Solo marcaremos redactado cuando se genera la descripción por la API, es decir, al guardarla. De modo que se puede marcar revisado un producto que no fue redactado, porque a veces se utiliza este módulo para modificar descripciones de productos que no han sido generadas con él.
    //02/01/2025 No ponemos redactado_api porque se haría al marcar redactado, ya que se puede revisar un producto que no ha sido redactado con ninguna api.
    public static function updateTablaRedactorRevisado($id_product) {

        if (!$id_employee = Context::getContext()->employee->id) {
            //esta función solo se puede ejecutar desde el controlador por lo tanto tiene que haber un employee en context pero por si acaso dejamos esto
            $id_employee = 44;
        }        
        
        //insertamos fecha y empleado de revisar en lafrips_redactor_descripcion
        $sql_update_revisado = "UPDATE lafrips_redactor_descripcion
        SET                
        procesando = 0,
        inicio_proceso = '0000-00-00 00:00:00',
        en_cola = 0,
        revisado = 1,
        id_employee_revisado = $id_employee, 
        date_revisado = NOW(),                   
        date_upd = NOW()
        WHERE id_product = $id_product";

        Db::getInstance()->execute($sql_update_revisado); 

        return;
    }

    //28/05/2025 función para almacenar en tabla la info que queremos pasar a la api sobre el producto
    public static function actualizaInfoParaApi($id_product, $info_para_api) {        
        
        $sql_update_info_para_api = "UPDATE lafrips_redactor_descripcion
        SET                
        info_para_api = '$info_para_api',                   
        date_upd = NOW()
        WHERE id_product = $id_product";

        if (Db::getInstance()->execute($sql_update_info_para_api)) {
            return true;
        }

        return 'Error haciendo UPDATE de info_para_api';
    }   

    
}
