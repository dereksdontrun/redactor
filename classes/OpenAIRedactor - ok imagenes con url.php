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

require_once(dirname(__FILE__).'/RedactorTools.php');
//30/12/2024 

//todas las funciones para trabajar con peticiones a la API de OpenAI (ChatGPT)

class OpenAIRedactor
{   
    //función que recibe los parámetros para enviar a la API de OpenAI y llama a la función que ejecuta la petición con el json preparado.
    //en principio llamamos a esta función desde el controlador AdminRedactorDescripciones.php y desde ColaDescripciones.php. Añadimos el parámetro opcional $imagenes, que sería un array con urls, para cuando utilicemos esta función desde el creador de productos, que pueda traer las imágenes de los proveedores, aunque para poder utilizar el id_product el producto ya debe existir. Se podrá utilizar de otra manera 
    public static function apiOpenAISolicitudDescripcion($parametros, $imagenes = null) {
        $id_product = $parametros["id_product"];
        $api_title = $parametros["title"];
        $api_description = $parametros["description"];     
        
        $api_imagenes = array();
        //obtenemos hasta 6 imágenes del producto
        if ($imagenes !== null) {
            foreach ($imagenes AS $imagen) {
                $api_imagenes[] = array(
                    "type" => "image_url",
                    "image_url" => array(
                        "url" => $imagen
                    ),
                );
            }
        } else {
            //no vienen imágenes en los parámetros, buscamos las del producto, hasta 6, para ello sacamos de lafrips_images hasta 6 id_image
            $sql_images = "SELECT id_image FROM lafrips_image WHERE id_product = $id_product ORDER BY cover DESC LIMIT 6";

            $images = Db::getInstance()->executeS($sql_images);   

            if (count($images) < 1) {
                $error_message = 'Error, no obtenidas imágenes para producto id_product '.$id_product;

                RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
                
                return array(
                    "result" => 0,
                    "message" => pSQL($error_message)
                );
            } else {
                foreach ($images AS $image) {
                    $id_image = $image['id_image'];

                    $link = new Link();								
						
                    $product = new Product((int)$id_product, false, 1, 1);
                    $product_link = $link->getProductLink($product, $product->link_rewrite, null, null, 1, 1);	                    
                    //imagen		
                    $image_link = new Link;//because getImageLInk is not static function
                    $image_url = $image_link->getImageLink($product->link_rewrite, $id_image, 'thickbox_default');

                    $api_imagenes[] = array(
                        "type" => "image_url",
                        "image_url" => array(
                            "url" => $image_url
                        ),
                    );
                }
            }
        }        

        //hemos guardado con la configuración del módulo el system role context en una variable llamada REDACTOR_OPENAI_SYSTEM_ROLE_CONTEXT
        // $system_role_context = "Eres un redactor experto en SEO especializado en productos de merchandising. Tu tarea es escribir descripciones persuasivas y atractivas para regalos coleccionables y productos geek, evitando términos que puedan tener connotaciones negativas en España, como 'fanático' o 'friki'. También describirás productos como camisetas, zapatillas, bolsos y otros complementos, a menudo relacionados con elementos populares como superheroes, dibujos animados, personajes de televisión y cine, etc. Prioriza destacar detalles clave del producto, como materiales, uso, características especiales y lo que lo hace único. Siempre optimiza los textos para buscadores, empleando palabras clave relevantes y generando títulos llamativos y efectivos para SEO. Puedes usar imágenes del producto y cualquier dato adicional proporcionado para crear contenido perfectamente adaptado a la marca y al público objetivo. Sé profesional, creativo y enfócate en captar la atención del lector. Por favor no metas en el texto emojis para evitar errores. Ofrecerás la respuesta con formato html válido para insertar en una base de datos, sin usar saltos de línea (\\n). Las etiquetas html permitidas son strong, h1, h2, br, p, ul, li, i. Aplicarás la etiqueta de negrita a las palabras clave, las cuales no incluirás por separado en tu descripción. Muy importante, lo primero que harás será analizar las imágenes recibidas y generar una descripción muy detallada del producto que muestran, mencionando formas, colores y otros rasgos relevantes. A dicha descripción puedes añadirle los datos adicionales que recibirás del rol de usuario y que pueden consistir en marcas, tamaños, fabricantes, materiales o cualquier otra información interesante, pero lo más importante es describir las imágenes recibidas como si el cliente no pudiera ver. Otro punto a destacar es que deberás orientar la descripción del producto al cliente potencial. Por ejemplo, si el producto a describir es un producto orientado a los niños, como juguetes o ropa de niño o bebé, la descripción y el SEO ira orientado a convencer a las madres de los niños. Si el producto es una figura de colección la descripción deberá ir dirigida a un cliente coleccionista. No confundas juguetes con figuras de colección. Si el producto tiene matices de indole sexual o erótica nunca dirijas la descripción a un cliente potencial infantil ya que se trata de un producto para adultos o coleccionistas.";

        $system_role_context = Configuration::get('REDACTOR_OPENAI_SYSTEM_ROLE_CONTEXT');

        if (!$system_role_context || $system_role_context == '') {
            $error_message = 'Error, no obtenido contexto para rol de sistema para OpenAI de tabla de configuración';

            RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
            
            return array(
                "result" => 0,
                "message" => pSQL($error_message)
            );
        }

        // $user_prompt = "Describe el producto de las fotos para un comercio online. Más información del producto: ".$api_description;
        $user_prompt = $api_title.' '.$api_description;

        //preparamos parámetros POST en json para la api       
        $array_user = array();

        $array_user[] = array(
            "type" => "text",
            "text" => $user_prompt
        );

        
        //obtenemos hasta 6 imágenes del producto
        if ($imagenes !== null) {
            foreach ($imagenes AS $imagen) {
                $array_user[] = array(
                    "type" => "image_url",
                    "image_url" => array(
                        "url" => $imagen
                    ),
                );
            }
        } else {
            //no vienen imágenes en los parámetros, buscamos las del producto, hasta 6, para ello sacamos de lafrips_images hasta 6 id_image
            $sql_images = "SELECT id_image FROM lafrips_image WHERE id_product = $id_product ORDER BY cover DESC LIMIT 6";

            $images = Db::getInstance()->executeS($sql_images);   

            if (count($images) < 1) {
                $error_message = 'Error, no obtenidas imágenes para producto id_product '.$id_product;

                RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
                
                return array(
                    "result" => 0,
                    "message" => pSQL($error_message)
                );
            } else {
                foreach ($images AS $image) {
                    $id_image = $image['id_image'];

                    $link = new Link();								
						
                    $product = new Product((int)$id_product, false, 1, 1);
                    $product_link = $link->getProductLink($product, $product->link_rewrite, null, null, 1, 1);	                    
                    //imagen		
                    $image_link = new Link;//because getImageLInk is not static function
                    $image_url = $image_link->getImageLink($product->link_rewrite, $id_image, 'thickbox_default');

                    $array_user[] = array(
                        "type" => "image_url",
                        "image_url" => array(
                            "url" => "https://".$image_url
                        ),
                    );
                }
            }
        }     

        //obtenemos los valores de modelo, max_tokens y temperature de la tabla de configuración, con valores por defecto si no hubiera
        $model = Configuration::get('REDACTOR_OPENAI_MODEL', 'gpt-4o');
        $max_tokens = Configuration::get('REDACTOR_OPENAI_MAX_TOKENS', 1000);
        $temperature = Configuration::get('REDACTOR_OPENAI_TEMPERATURE', 0.7);

        $array = array(
            "model" => $model,
            "messages" => array(
                array(
                    "role" => "system",
                    "content" => $system_role_context
                ),
                array(
                    "role" => "user",
                    "content" => $array_user
                )
            ),
            "max_tokens" => $max_tokens,
            "temperature" => $temperature
        ); 

        $array_json = json_encode($array);

        $array_json_insert = pSQL($array_json);

        //marcamos la tabla redactor_descripcion como procesando
        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }
        //insertamos fecha y empleado de redactar y el json de envío post a la API en lafrips_redactor_descripcion
        //11/03/2024 Solo guardaremos el json si api_json en lafrips_redactor_descripcion está vacío, es decir, la primera vez, dado que a partir de ahí, una vez generada una descripción, el producto en su campo description_short puede tener un texto grande que pisará (cortado a 500 caracteres) lo que haya en el campo        
        $sql_redactando = "UPDATE lafrips_redactor_descripcion
        SET 
        api = 'openai',                
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
        
        $description = OpenAIRedactor::apiCallDescription($array_json, $id_product);
        //si hubo error generando descripción devolvemos el error a donde hallamos llamado a esta función
        if (!$description["result"]) {
            return $description;
        }

        ////la API devuelve el texto generado en párrafos con saltos de línea de tipo \n
        $description["message"] = OpenAIRedactor::limpiaTexto($description["message"]);        
        
        //la api genera la descripción con un título entre <h1> que podríamos utilizar para nuestro producto como nombre. Comprobamos si existe dicho título y si es así lo devolveremos como parámetro
        if (($titulo = OpenAIRedactor::sacaTitulo($description["message"])) != null) {
            //sacaTitulo() devuelve un array con el título primero y el resto de la descripción después.
            $description['titulo'] = $titulo[0];
            $description["message"] = $titulo[1];
        }

        //devolvemos $description, que puede o no llevar 'titulo'
        return $description;
    }

    public static function apiCallDescription ($post_fields, $id_product) {
        $api_key = OpenAIRedactor::getApiKey();
    
        $curl = curl_init();
    
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
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

            $error_message = 'Error haciendo petición a API OpenAI para Descripción - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
            
            return array(
                "result" => 0,
                "message" => pSQL($error_message)
            );
            
        }
        
        if ($response) {

            // $curl_info = curl_getinfo($curl);

            // $connect_time = $curl_info['connect_time'];
            // $total_time = $curl_info['total_time'];

            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);     

            //pasamos el JSON de respuesta a un array PHP. 
            $response_decode = json_decode($response, true); 
        
            // print_r($response_decode);

            //si el código http no es 200 lo damos por error, la respuesta debe contener un array "error" con 4 posibles parámetros, "message" , "type", "param" y "code"
            if ($http_code != 200) {                
                $error_message = "Error: http code = $http_code - ";

                if ($response_decode['error']['message']) {
                    $error_message .= " - ".$response_decode['error']['message'];
                }

                if ($response_decode['error']['type']) {
                    $error_message .= " - ".$response_decode['error']['type'];
                }

                if ($response_decode['error']['param']) {
                    $error_message .= " - ".$response_decode['error']['param'];
                }

                if ($response_decode['error']['code']) {
                    $error_message .= " - ".$response_decode['error']['code'];
                }

                RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));
                
                return array(
                    "result" => 0,
                    "message" => pSQL($error_message)
                    // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );
            }
        
            //la respuesta debería ser correcta, tiene formato blabla que contiene "choices" (solo pedimos un texto luego será el primero), dentro message, y el role assistan tiene como content el texto resultado
            $response_generated_text = $response_decode['choices'][0]['message']['content'];
    
            if ($response_generated_text && !is_null($response_generated_text) && !empty($response_generated_text)) {
                // Redactame::updateTablaRedactor(1, $id_product); Mientras hagamos negritas, aún no podemos considerarlo redactado

                return array(
                    "result" => 1,
                    "message" => $response_generated_text                    
                );

            } else {
                $error_message = "Error, respuesta API de Descripción vacía";

                RedactorTools::updateTablaRedactorRedactado(0, $id_product, $error_message);
                
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

            RedactorTools::updateTablaRedactorRedactado(0, $id_product, $error_message);    
            
            return array(
                "result" => 0,
                "message" => $error_message
            );
        }  
    }
        

    //función que elimina los caracteres que vayamos poniendo en un array $eliminar    
    public static function limpiaTexto($description) {
        $array_eliminar = array('\n', '`', 'html');

        foreach ($array_eliminar AS $eliminar) {
            $description = str_replace($eliminar, "", $description);
        }

        return $description;
    }

    //función que comprueba si la primera línea del texto recibido es un titular y si es así lo separa del resto devolviendo un array con título y descripción, o null
    public static function sacaTitulo($description) {
        // Expresión regular para buscar el <h1> al principio del texto, no busca más allá de la segunda línea
        $pattern = '/^\s*<h1>(.*?)<\/h1>\s*(?:\r?\n){0,2}/i';
        
        if (preg_match($pattern, $description, $matches)) {
            // $matches[1] contiene el texto dentro del <h1>
            $titular = strip_tags($matches[1]);
            
            // Eliminar el <h1> y la línea en blanco del texto original. Solo elimina la primera ocurrencia de <h1> 
            $resto_texto = preg_replace($pattern, '', $description, 1);

            return array($titular, $resto_texto);                
        }
        
        // Si no hay <h1> devolver null
        return null;
    }

    public static function getApiKey() {
        //Obtenemos la key leyendo el archivo api_openai.json donde hemos almacenado la contraseña para la API
        $secrets_json = file_get_contents(dirname(__FILE__).'/../secrets/api_openai.json');
        
        $secrets = json_decode($secrets_json, true);

        //devolvemos la Api Key
        return $secrets['api_key'];
    }        
}
