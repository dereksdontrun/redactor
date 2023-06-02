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

//todas las funciones para trabajar con peticiones a la API de Redacta.me

class Redactame
{   
    //función que recibe los parámetros para enviar a la API de Redacta.me y llama a la función apiCall() con el json preparado.
    //en principio llamamos a esta función desde el controlador AdminRedactorDescripciones.php
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

        //insertamos el json de envío post a la API en lafrips_redactor_descripcion
        $sql_api_json = "UPDATE lafrips_redactor_descripcion
        SET
        api_json = '$array_json_insert', 
        date_upd = NOW()
        WHERE id_product = $id_product";

        Db::getInstance()->executeS($sql_api_json);         

        return Redactame::apiCall($array_json, $id_product);
    }

    public static function apiCall ($post_fields, $id_product) {
        //Obtenemos la key leyendo el archivo api.json donde hemos almacenado la contraseña para la API
        $secrets_json = file_get_contents(dirname(__FILE__).'/../secrets/api.json');
        
        $secrets = json_decode($secrets_json, true);

        //sacamos la Api Key
        $api_key = $secrets['api_key'];

    
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

            $error_message = 'Error haciendo petición a API Redacta.me - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            Redactame::updateTablaRedactor(0, $id_product, pSQL($error_message));        
            
            return array(
                "result" => 0,
                "message" => $error_message
            );
            
        }
        
        if ($response) {

            $curl_info = curl_getinfo($curl);

            $connect_time = $curl_info['connect_time'];
            $total_time = $curl_info['total_time'];

            curl_close($curl);
            
        
            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response); 
        
            // print_r($response_decode);

            //a 29/05/2023 si la API devuelve correctamente la descripción solo hay dos valores, generatedText que es la descripción y generatedWords que es el número de palabras de la descripción. Si hay un error que no permite devolver la descripción sé que devuelve varios parámetros. Buscamos title, status y detail, interpreto que si están es que no hay generatedText y lo podemos montar como error
            if ($response_decode->title || $response_decode->status || $response_decode->detail) {
                $error_message = "Error: ".$response_decode->status." - ".$response_decode->title." - ".$response_decode->detail;

                Redactame::updateTablaRedactor(0, $id_product, $error_message);
                
                return array(
                    "result" => 0,
                    "message" => $error_message,
                    "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );
            }
        
            $response_generated_text = $response_decode->generatedText;
    
            if ($response_generated_text && !is_null($response_generated_text) && !empty($response_generated_text)) {
                Redactame::updateTablaRedactor(1, $id_product);

                //la API deveulve el texto generado en párrafos con saltos de línea de tipo \n\n , es decir, dos saltos de línea. Para formatearlo a html con <p> hacemos primero un explode por \n\n y luego implode con </p><p> poniendo a principio y fin el comienzo y fin del tag <p>
                $lines_response = explode("\n\n", $response_generated_text);
                $html_response = '<p>'.implode('</p><p>', $lines_response).'</p>';
                
                return array(
                    "result" => 1,
                    "message" => $html_response,
                    "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );

            } else {
                $error_message = "Error, respuesta de API vacía";

                Redactame::updateTablaRedactor(0, $id_product, $error_message);
                
                return array(
                    "result" => 0,
                    "message" => $error_message,
                    "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );
            }
        
        } else {
            //no hay respuesta pero cerramos igualmente
            curl_close($curl);

            $error_message = "Error, la API no responde";

            Redactame::updateTablaRedactor(0, $id_product, $error_message);    
            
            return array(
                "result" => 0,
                "message" => $error_message
            );
        }  
    }

    //función que realiza updates a la tabla lafrips_redactor_descripcion en función del resultado
    public static function updateTablaRedactor($redactado, $id_product, $error_message = "") {

        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }

        if ($redactado) {
            $sql_redactado = " redactado = 1,
            id_employee_redactado = $id_employee, 
            date_redactado = NOW(),
            error = 0, ";
        } else {
            $sql_redactado = " redactado = 0,
            id_employee_redactado = 0, 
            date_redactado = '0000-00-00 00:00:00', 
            error = 1,
            date_error = NOW(),
            error_message = CONCAT(error_message, ' | ".$error_message." - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')), ";
        }
        // error_message = '".$error_message."', ";
        
        //insertamos fecha y empleado de redactar en lafrips_redactor_descripcion
        $sql_update_redactado = "UPDATE lafrips_redactor_descripcion
        SET                
        procesando = 0,
        inicio_proceso = '0000-00-00 00:00:00',
        en_cola = 0,
        revisado = 0, 
        date_revisado = '0000-00-00 00:00:00',
        id_employee_revisado = 0,
        $sql_redactado                    
        date_upd = NOW()
        WHERE id_product = $id_product";

        Db::getInstance()->executeS($sql_update_redactado); 

        return;
    }
    
}
