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

//20/02/2024 Vamos a hacer una segunda petición a la API enviandole la descripción resultado de la primera para que obtenga hasta x palabras clave, y después añadirle negritas a dichas palabras clave y lo obtenido establecerlo como descripción. Para ello, en lugar de hacer retorno de apiRedactameSolicitudDescripcion() la primera llamada a API lo que haremos será enviar el resultado a otra función que pedirá las palabras clave, el resultado de eso lo enviaremos junto a la descripción a otra función para poner negritas, y el resultado de eso será lo que devolveremos.

//todas las funciones para trabajar con peticiones a la API de Redacta.me

class Redactame
{   
    //función que recibe los parámetros para enviar a la API de Redacta.me y llama a la función que ejecuta la petición con el json preparado.
    //en principio llamamos a esta función desde el controlador AdminRedactorDescripciones.php y desde ColaDescripciones.php
    //11/03/2024 Aparte de la petición de una descripción vamos a utilizar la API para otras llamadas como obtener las palabras clave del texto recibido, o probablemente pedir una traducción del mismo texto. Esta función prepara los parámetros y llama a las funciones que realizan las paetciones a la api. Veremos como se realiza lo de la traducción
    public static function apiRedactameSolicitudDescripcion($parametros) {
        $id_product = $parametros["id_product"];
        $api_title = $parametros["title"];
        $api_description = $parametros["description"];
        $api_keywords = $parametros["keywords"];
        $api_tone = $parametros["tone"];

        if (empty($api_keywords) || !$api_keywords) {
            $api_keywords = "";
        }

        //preparamos parámetros POST en json para la api
        //utilizamos eltemplate 4 de redacta.me que corresponde a descripción de producto
        $array = array(
            "templateId" => 4,
            "parameters" => array(
                "Title" => $api_title,
                "Description" => $api_description
            ),
            "keywords" => $api_keywords,
            "tone" => $api_tone
        ); 

        $array_json = json_encode($array);

        $array_json_insert = pSQL($array_json);

        //marcamos la tabla redactor_descripcion como procesando
        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }
        //insertamos fecha y empleado de redactar y el json de envío post a la API en lafrips_redactor_descripcion
        //11/03/2024 Solo guardaremos el json si api_json en lafrips_redactor_descripcion está vacío, es decir, la primera vez, dado que a partir de ahí, una vez generada una descripción, el producto en su campo description_short puede tener un texto grande que pisará (cortado a 500 caracteres) lo que haya en el campo
        //18/09/2024 redacta.me ha ampliado el límite de caracteres de la descripción de 500 a 5000
        $sql_redactando = "UPDATE lafrips_redactor_descripcion
        SET                
        procesando = 1,
        inicio_proceso = NOW(),
        id_employee_redactado = $id_employee,                            
        date_upd = NOW(),
        api_json = 
            CASE
                WHEN api_json IS NULL OR api_json = '' THEN '$array_json_insert'
                ELSE api_json
            END 
        WHERE id_product = $id_product";

        Db::getInstance()->executeS($sql_redactando);               

        // return Redactame::apiCallDescription($array_json, $id_product);
        //20/02/2024 Vamos a hacer una segunda llamda a la API para obtener palabras clave de la descripción
        $description = Redactame::apiCallDescription($array_json, $id_product);
        //si hubo error generando descripción devolvemos el error a donde hallamos llamado a esta función
        if (!$description["result"]) {
            return $description;
        }

        //11/03/2024 limpiamos de palabra fanático/s
        $description["message"] = Redactame::sustituyeFanatico($description["message"]);

        //hacemos llamada a API para obtener palabras clave de la descripción obtenida. Preparamos los parámetros para el nuevo template (id 1 "Redact")
        //preparamos parámetros POST en json para la api
        //utilizamos eltemplate 1 de redacta.me que corresponde a la plantilla "básica" y enviamos la descripción obtenida como parámetro, concatenando antes la frase "dame 7 palabras clave de este texto: "
        //palabras clave que pediremos por defecto
        $num_palabras_clave = 7;
        // $api_text = "dame $num_palabras_clave palabras clave de este texto: ".$description["message"];
        //20/03/2024 este devolvía las palabras clave de diferentes maneras, probamos este de debajo

        $api_text = "dame $num_palabras_clave palabras clave de este texto, separadas por coma, sin repetir el texto: ".$description["message"];

        $array = array(
            "templateId" => 1,
            "parameters" => array(
                "Text" => $api_text
            ),
            "creativityLevel" => "",
            "keywords" => "",
            "tone" => "",
            "responses" => 1
        ); 

        $array_json = json_encode($array);

        $palabras_clave = Redactame::apiCallPalabrasClave($array_json, $id_product, $num_palabras_clave);
        //si hubo error generando palabras clave devolvemos el error a donde hallamos llamado a esta función
        if (!$palabras_clave["result"]) {
            return $palabras_clave;
        }

        //tenemos supuestamente las palabras clave en un array en la respuesta de apiCallPalabrasClave(). Las enviamos junto a la descripción a ponerNegritas() y devolvemos el resultado al lugar de petición
        return Redactame::ponerNegritas($description["message"], $palabras_clave["message"]);
    }

    public static function apiCallDescription ($post_fields, $id_product) {
        $api_key = Redactame::getApiKey();
    
        $curl = curl_init();
    
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.redacta.me/v1/ai/texts',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 70,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer '.$api_key
            ),
        ));
    
        try {
            //ejecutamos cURL
            $response = curl_exec($curl);
        
            //si ha ocurrido algún error, lo capturamos
            if(curl_errno($curl)){
                throw new Exception(curl_error($curl));
            }
        }
        catch (Exception $e) {    
            $exception = $e->getMessage();
            $file = $e->getFile();
            $line = $e->getLine(); 
            $code = $e->getCode();

            $error_message = 'Error haciendo petición a API Redacta.me para Descripción - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            Redactame::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
            
            return array(
                "result" => 0,
                "message" => pSQL($error_message)
            );
            
        }
        
        if ($response) {

            // $curl_info = curl_getinfo($curl);

            // $connect_time = $curl_info['connect_time'];
            // $total_time = $curl_info['total_time'];

            curl_close($curl);
            
        
            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response); 
        
            // print_r($response_decode);

            //a 29/05/2023 si la API devuelve correctamente la descripción solo hay dos valores, generatedText que es la descripción y generatedWords que es el número de palabras de la descripción. Si hay un error que no permite devolver la descripción sé que devuelve varios parámetros. Buscamos title, status y detail, interpreto que si están es que no hay generatedText y lo podemos montar como error
            if ($response_decode->title || $response_decode->status || $response_decode->detail) {
                // $error_message = "Error: ".$response_decode->status." - ".$response_decode->title." - ".$response_decode->detail;
                $error_message = "Error: ";

                if ($response_decode->status) {
                    $error_message .= " - ".$response_decode->status;
                }

                if ($response_decode->title) {
                    $error_message .= " - ".$response_decode->title;
                }

                if ($response_decode->detail) {
                    $error_message .= " - ".$response_decode->detail;
                }

                Redactame::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));
                
                return array(
                    "result" => 0,
                    "message" => pSQL($error_message)
                    // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );
            }
        
            $response_generated_text = $response_decode->generatedText;
    
            if ($response_generated_text && !is_null($response_generated_text) && !empty($response_generated_text)) {
                // Redactame::updateTablaRedactor(1, $id_product); Mientras hagamos negritas, aún no podemos considerarlo redactado

                //la API devuelve el texto generado en párrafos con saltos de línea de tipo \n\n , es decir, dos saltos de línea. Para formatearlo a html con <p> hacemos primero un explode por \n\n y luego implode con </p><p> poniendo a principio y fin el comienzo y fin del tag <p>. Para poder pasar el pSQL() tenemos que hacerlo después de dividir con explode por \n\n o no lo cogerá, y después pasamos pSQL($string, true) con true como segundo parámetro, que indica html_ok, es decir, respeta los tags html
                $lines_response = explode("\n\n", $response_generated_text);

                // foreach ($lines_response AS &$line) {
                //     $line = pSQL($line);
                // }

                $html_response = '<p>'.implode('</p><p>', $lines_response).'</p>';
                
                return array(
                    "result" => 1,
                    "message" => pSQL($html_response, true)
                    // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );

            } else {
                $error_message = "Error, respuesta API de Descripción vacía";

                Redactame::updateTablaRedactorRedactado(0, $id_product, $error_message);
                
                return array(
                    "result" => 0,
                    "message" => $error_message
                    // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );
            }
        
        } else {
            //no hay respuesta pero cerramos igualmente
            curl_close($curl);

            $error_message = "Error, la API no responde a la petición de Descripción";

            Redactame::updateTablaRedactorRedactado(0, $id_product, $error_message);    
            
            return array(
                "result" => 0,
                "message" => $error_message
            );
        }  
    }
    
    public static function apiCallPalabrasClave($post_fields, $id_product, $num_palabras_clave) {   
        $api_key = Redactame::getApiKey();   
    
        $curl = curl_init();
    
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.redacta.me/v1/ai/texts',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 70,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer '.$api_key
            ),
        ));
    
        try {
            //ejecutamos cURL
            $response = curl_exec($curl);
        
            //si ha ocurrido algún error, lo capturamos
            if(curl_errno($curl)){
                throw new Exception(curl_error($curl));
            }
        }
        catch (Exception $e) {    
            $exception = $e->getMessage();
            $file = $e->getFile();
            $line = $e->getLine(); 
            $code = $e->getCode();

            $error_message = 'Error haciendo petición a API Redacta.me para Palabras Clave - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            Redactame::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
            
            return array(
                "result" => 0,
                "message" => pSQL($error_message)
            );
            
        }
        
        if ($response) {

            // $curl_info = curl_getinfo($curl);

            // $connect_time = $curl_info['connect_time'];
            // $total_time = $curl_info['total_time'];

            curl_close($curl);            
        
            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response); 
        
            // print_r($response_decode);

            //a 20/02/2024 si la API devuelve correctamente las palabras clave solo hay dos valores, generatedText que es la lista de palabras (o grupos de palabras) consideradas palabras clave y generatedWords que es el número de palabras totales seleccionadas de la descripción. Si hay un error que no permite devolver la descripción sé que devuelve varios parámetros. Buscamos title, status y detail, interpreto que si están es que no hay generatedText y lo podemos montar como error

            //OJO No he podido reproducir error llamando a template 1, dejo el mismo tratamiento pero esto podría no ser así
            // OJO OJO OJO
            if ($response_decode->title || $response_decode->status || $response_decode->detail) {
                // $error_message = "Error: ".$response_decode->status." - ".$response_decode->title." - ".$response_decode->detail;
                $error_message = "Error: ";

                if ($response_decode->status) {
                    $error_message .= " - ".$response_decode->status;
                }

                if ($response_decode->title) {
                    $error_message .= " - ".$response_decode->title;
                }

                if ($response_decode->detail) {
                    $error_message .= " - ".$response_decode->detail;
                }

                Redactame::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));
                
                return array(
                    "result" => 0,
                    "message" => pSQL($error_message)
                    // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );
            }
        
            $response_generated_text = $response_decode->generatedText;
    
            if ($response_generated_text && !is_null($response_generated_text) && !empty($response_generated_text)) {
                // Redactame::updateTablaRedactor(1, $id_product); No lo hago ya que se ha marcado correcto al generar la descripción, (ya no) y es mejor esperar a que estén bien las negritas

                //la API devuelve las palabras claves del texto generado. Si es correcto lo hace separandolas por coma. Puede haber grupos de palabras, por ejemplo:
                // "Figura, Mini Egg Attack, Batman, Liga de la Justicia, calidad, detalle, colección"
                // A veces devuelve un texto o la descripción añadiendo después las palabras, tampoco nos vale, por ello contaremos los bloques entre comas
                // Vamos a hacer un explode por la coma de modo que tendremos en un array lo que queremos poner en negrita
                $response_keywords = explode(",", pSQL($response_generated_text));    
                
                //nos aseguramos de que el array tiene $num_palabras_clave elementos, con 2 más o menos de margen, o lo consideramos un error
                if ((count($response_keywords) > ($num_palabras_clave + 2)) || (count($response_keywords) < ($num_palabras_clave - 2))) {
                    $error_message = "Error, respuesta de API para Palabras Clave contiene ".count($response_keywords)." elementos y esperaba $num_palabras_clave";
                    
                    Redactame::updateTablaRedactorRedactado(0, $id_product, $error_message);
                    
                    return array(
                        "result" => 0,
                        "message" => $error_message
                        // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                    );
                }
                
                return array(
                    "result" => 1,
                    "message" => $response_keywords
                    // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );

            } else {
                $error_message = "Error, respuesta de API para Palabras Clave vacía";

                Redactame::updateTablaRedactorRedactado(0, $id_product, $error_message);
                
                return array(
                    "result" => 0,
                    "message" => $error_message
                    // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );
            }
        
        } else {
            //no hay respuesta pero cerramos igualmente
            curl_close($curl);

            $error_message = "Error, la API no responde a la petición de Palabras Clave";

            Redactame::updateTablaRedactorRedactado(0, $id_product, $error_message);    
            
            return array(
                "result" => 0,
                "message" => $error_message
            );
        }  
    }

    public static function ponerNegritas($description, $palabras_clave) {
        //en $palabras_clave tenemos supuestamente un array con las palabras o grupos de palabras a poner en negrita del texto contenido en $description
        foreach ($palabras_clave AS $palabra_clave) {
            $description = str_replace($palabra_clave, "<strong>$palabra_clave</strong>", $description);
        }

        return array(
            "result" => 1,
            "message" => $description
        );
    }

    //función que busca las palabras fanático, fanática. fanáticos, fanáticas y las sustituye por fan/s
    //CAMBIAR A REGEX
    public static function sustituyeFanatico($description) {
        $fanatico_singular = array('fanatico','fanático','fanatica','fanática');
        $fanatico_plural = array('fanaticos','fanáticos','fanaticas','fanáticas');
        foreach ($fanatico_singular AS $fan) {
            $description = str_replace($fan, "fan", $description);
        }

        foreach ($fanatico_plural AS $fan) {
            $description = str_replace($fan, "fans", $description);
        }

        return $description;
    }

    public static function getApiKey() {
        //Obtenemos la key leyendo el archivo api.json donde hemos almacenado la contraseña para la API
        $secrets_json = file_get_contents(dirname(__FILE__).'/../secrets/api.json');
        
        $secrets = json_decode($secrets_json, true);

        //devolvemos la Api Key
        return $secrets['api_key'];
    }

    //28/02/2024 Función que guardará la descripción generada, ya sea si el producto se ha procesado en Cola de descipciones, o si se ha procesado visualmente desde el front del módulo (controlador), en cuyo caso también recibirá el nombre del producto por si el usuario lo ha modificado. No debe marcar Redactado, eso debe hacerse solo cuando se genera la descripción con API, ya sea en cola de redacción o manualmente desde el front.
    //damos la opción del parámtreo nombre vacío porque el nombre por cola de redacción no se modifica, pero manualmente si se puede cambiar.    
    public static function actualizaProducto($id_product, $descripcion, $nombre = "") {
        //instanciamos el producto para actualizar descripción y/o nombre, solo para id_lang 1
        $product = new Product($id_product);
        // para descripciones cuando solo queremos afectar a un lenguaje
        $product->description_short[1] = $descripcion;  

        if ($nombre !== "") {
            $product->name[1] = $nombre;
        }        

        try {
            $product->update();                      

            //guardamos la descripción por ahora para analizar errores
            $sql_descripcion = "UPDATE lafrips_redactor_descripcion
            SET                
            descripcion = '$descripcion'
            WHERE id_product = $id_product";

            Db::getInstance()->executeS($sql_descripcion);   
            
            return true;

        } catch (Exception $e) {
            $error_message = "Excepción: ".pSQL($e->getMessage());

            //guardamos por ahora la descripción fallida para analizar errores
            $descripcion = 'Error exception. '.$descripcion;

            $sql_descripcion = "UPDATE lafrips_redactor_descripcion
            SET         
            descripcion = '$descripcion'
            WHERE id_product = $id_product";

            Db::getInstance()->executeS($sql_descripcion); 

            return $error_message;
        }

    }

    //función para marcar como redactado un producto, se separa de la función de marcar como revisado porque un producto podemos redactarlo de forma automática desde la cola de redacción o manualmente desde el controlador. Pero revisarlo solo se hace desde el controlador y si el producto fue redactado con la cola, tenemos que poder marcar solo revisado, pero si fue redactado pidiéndolo manualmente desde el controlador, debemos poder marcar ambos, de modo que llamaremos a una sola función o ambas dependiendo.
    //si estamos marcando un producto como redactado = 1, significa que está recién redactado y por tanto no revisado, pondremos revisado = 0, del mismo modo, si un producto consideramos que no está redactado, también debemos marcarlo como revisado = 0. Es decir, marcar un producto el parámetro redactado siempre implica resetear revisado, no así marcar revisado, que depende de si ya estaba redactado, como cuando viene de la cola de redacción, o si estamos revisando un producto del que acabamos de generar su descripción desde el controlador y por tanto aún no ha sido guardada, en cuyo caso, al marcar revisado también marcaremos redactado (mismo usuario)
    //07/03/2024 Solo marcaremos redactado cuando se genera la descripción por la API, es decir, al guardarla. De modo que se puede marcar revisado un producto que no fue redactado, porque a veces se utiliza este módulo para modificar descripciones de productos que no han sido generadas con él.
    public static function updateTablaRedactorRedactado($redactado, $id_product, $error_message = "") {

        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }

        if ($redactado) {
            $sql_redactado = " redactado = 1,
            id_employee_redactado = $id_employee, 
            date_redactado = NOW(),            
            error = 0, ";
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

        Db::getInstance()->executeS($sql_update_redactado); 

        return;
    }

    //función para marcar como revisado un producto, se separa de la función de marcar como redactado porque un producto podemos redactarlo de forma automatica desde la cola de redacción o manualmente desde el controlador. Pero revisarlo solo se hace desde el controlador y si el producto fue redactado con la cola, tenemos que poder marcar solo revisado, pero si fue redactado pidiendolo manualmente desde el controlador, debemos poder marcar ambos, de modo que llamaremos a una sola función o ambas dependiendo.    
    //marcar un producto el parámetro redactado siempre implica resetear revisado, no así marcar revisado, que depende de si ya estaba redactado, como cuando viene de la cola de redacción, o si estamos revisando un producto del que acabamos de generar su descripción desde el controlador y por tanto aún no ha sido guardada, en cuyo caso, al marcar revisado también marcaremos redactado (mismo usuario). Por eso, si generamos una descripción desde el controlador, al marcar revisar, primero llamaremos a updateTablaRedactorRedactado() y seguido a updateTablaRedactorRevisado()
    //07/03/2024 Solo marcaremos redactado cuando se genera la descripción por la API, es decir, al guardarla. De modo que se puede marcar revisado un producto que no fue redactado, porque a veces se utiliza este módulo para modificar descripciones de productos que no han sido generadas con él.
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

        Db::getInstance()->executeS($sql_update_revisado); 

        return;
    }
    
}
