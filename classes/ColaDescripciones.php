<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');
// require_once(dirname(__FILE__).'/Redactame.php');
require_once(dirname(__FILE__).'/OpenAIRedactor.php');
require_once(dirname(__FILE__).'/RedactorTools.php');

//https://lafrikileria.com/modules/redactor/classes/ColaDescripciones.php
//https://lafrikileria.com/test/modules/redactor/classes/ColaDescripciones.php

//24/05/2023 Proceso programado que revisa la tabla lafrips_redactor_descripcion buscando productos marcados para la cola de redacción, obtiene sus datos y envía petición a la API de Redacta.me para recibir una nueva descripción y guardarla en el producto.

//Los productos tardan un tiempo variable en obtener la descripción de la API de modo que el proceso debe tener un límite de tiempo. Sacaremos el max_execution_time de PHP que tenemos configurado en el servidor y cuando se termine cada producto lo comprobaremos. Si se ha alcanzado el 90% de ese tiempo o terminado la lista de productos, pararemos el proceso. Cada vez que se finaliza un producto haremos una media de tiempo tardado para calcular si el tiempo que queda hasta el 90% o 95% es suficiente para otro antes de sacar otro de la tabla. 

//Los productos deben marcarse con procesando = 1 y la hora de inicio de proceso al entrar en el proceso. Esos valores se pasarán a 0 al terminar. De ese modo al iniciar el proceso se busca el primer producto con en_cola = 1 y procesando = 0.

//habrá un proceso de comprobación o coche escoba al comenzar la ejecución. Se sacará si hay algún producto con procesando = 1 y se calculará el tiempo que lleva. Si este es superior a x lo pasaremos a procesando = 0 y habrá que revisarlo.

//02/01/2025 Adaptamos todo al nuevo redactor OpenAI

$a = new ColaDescripciones();

class ColaDescripciones
{
    //almacenamos max_execution_time para saber cuando parar si hemos puesto un limit muy alto. El valor que almacenamos es el 90% de la variable php, de modo que si lo superamos no continuaremos con más productos
    public $my_max_execution_time;
    //momento de inicio del script, en segundos, para comparar con max_execution_time
    public $inicio;
    //un segundo max execution time definido a 25 minutos, para, a 29/05/2023 programar dos veces por hora el cron en producción, asegurándome de que no llegará a la media hora de max_excution_time de PHP. Programaré la tarea dos veces por hora, a y 5 y a y 35. Así al tener un max de 25 minutos parará cuando alcnace el primer límite, que podría ser 25 o si alguien modifica max_execution_time de PHP y lo acorta o alarga.//DE MOMENTO NO LO USO DADO QUE TENEMOS EN PHP DEFINIDO 50 minutos. Ejecuto una vez por hora

    //un segundo max execution time definido a x minutos para programarlo dos/tres veces por hora sin que se solapen, mientras descubro la razón de que pare cada aprox 10 productos o 5-10 minutos. Pongo 25 minutos para dos veces (1500 sec) o 18 minutos para 3 veces ()
    //18 minutos 1080 segundos
    //8 minutos (480 sec) para ejecutarlo cada 10, a ver que tal, ya que parece que siempre se para a los 10 productos o más o menos 5 mintuos
    public $max_execution_time_x_minutos = 240;
    

    //directorio para log
    // public $directorio_log = _PS_ROOT_DIR_.'/modules/redactor/log/';
    public $log_file = _PS_ROOT_DIR_.'/modules/redactor/log/descripciones.txt';
    public $error = 0;

    public $contador_productos = 0;
    public $contador_correctos = 0;

    public $descripcion_api;
    public $info_api = array();

    //28/05/2025 solo operamos con openai ahora
    public $api_seleccionada = 'openai';

    public function __construct() {
        $this->inicio = time();
        $this->my_max_execution_time = ini_get('max_execution_time')*0.9; //90% de max_execution_time   

        //llamamos a función para que analice productos atascados en "procesando"
        $this->checkProcesando();
 
        $this->start();
    }

    public function start() {
        $exit = 0;

        do {
            $this->info_api = null;
            $resultado_api = null;
            $this->descripcion_api = null;
            // $this->api_seleccionada = null;
            
            $get_info = $this->getProductInfo();

            if ($get_info === true) {

                $this->contador_productos++;

                if ($this->contador_productos == 1) {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso Cola Descripciones'.PHP_EOL, FILE_APPEND);
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tiempo máximo ejecución - my_max_execution_time = '.$this->my_max_execution_time.PHP_EOL, FILE_APPEND);
                }

                //info de producto obtenida, enviamos a la clase de la API correspondiente
                //ya no utilizamos Redactame
                // if ($this->api_seleccionada == 'redactame') {
                //     $resultado_api = Redactame::apiRedactameSolicitudDescripcion($this->info_api);

                // } elseif ($this->api_seleccionada == 'openai') {
                //     $resultado_api = OpenAIRedactor::apiOpenAISolicitudDescripcion($this->info_api);

                // }         
                
                $resultado_api = OpenAIRedactor::apiOpenAISolicitudDescripcion($this->info_api);

                // if ($resultado_api["curl_info"]) {
                //     file_put_contents($this->log_file, date('Y-m-d H:i:s').' - cURL Info: '.$resultado_api["curl_info"].PHP_EOL, FILE_APPEND);
                // }                

                //22/05/2025 $resultado debe contener title, description_short, description, meta_title, meta_description
                //15/07/2025 Ahora hacemos la descripción larga en clasificación al obtener la categoría principal, por tanto $resultado debe contener title, description_short, meta_title, meta_description y atributo_alt (para img)
                if ($resultado_api["result"] == 1) {
                    //tenemos una descripción, supuestamente correcta, o al menos la API no dió error. La tabla ya ha sido actualizada 
                    foreach ($resultado_api['message'] AS $index => $textos) {
                        switch ($index) {
                            case 'es':
                                $id_lang = 1;
                                break;
                            case 'en':
                                $id_lang = 11;
                                break;
                            case 'fr':
                                $id_lang = 12;
                                break;
                            case 'pt':
                                $id_lang = 18;
                                break;
                        }

                        //guardamos el resultado actualizando el producto.                  
                        if (($retorno_actualiza_producto = RedactorTools::actualizaProducto($this->info_api["id_product"], $id_lang, $textos['description_short'], $textos['title'],  $textos['atributo_alt'], $textos['meta_title'], $textos['meta_description'])) === true) {
                            //marcamos redactado
                            RedactorTools::updateTablaRedactorRedactado(1, $this->info_api["id_product"]);

                            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Descripción generada y guardada para id_product '.$this->info_api["id_product"].', id_lang = '.$id_lang.' con API '.ucfirst($this->api_seleccionada).PHP_EOL, FILE_APPEND);
                        } else { 
                            //marcamos error y quitamos redactado, e insertamos el mensaje de error de excepción devuelto
                            $retorno_actualiza_producto = pSQL($retorno_actualiza_producto);

                            RedactorTools::updateTablaRedactorRedactado(0, $this->info_api["id_product"], $retorno_actualiza_producto);              

                            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error: Descripción generada NO guardada para id_product '.$this->info_api["id_product"].', id_lang = '.$id_lang.' con API '.ucfirst($this->api_seleccionada).' - '.$retorno_actualiza_producto.PHP_EOL, FILE_APPEND);
                        }
                    }                    

                    $this->contador_correctos++;                    
                    
                } else {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error generando descripción para id_product '.$this->info_api["id_product"].' con API '.ucfirst($this->api_seleccionada).PHP_EOL, FILE_APPEND);
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Mensaje: '.$resultado_api["message"].PHP_EOL, FILE_APPEND);
                }

            } elseif ($get_info === false) {
                //no hay productos en cola en la tabla, interrumpimos el proceso, salimos del do - while
                break;
            }
            
            if (((time() - $this->inicio) >= $this->my_max_execution_time) || ((time() - $this->inicio) >= $this->max_execution_time_x_minutos)) {
                $exit = 1;

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tiempo ejecución alcanzando límite'.PHP_EOL, FILE_APPEND);
            }
        } while (!$exit);

        if ($this->contador_productos > 0) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Fin proceso. Productos procesados = '.$this->contador_productos.' - Descripciones correctas = '.$this->contador_correctos.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tiempo ejecución - '.(time() - $this->inicio).PHP_EOL, FILE_APPEND);
        }

        exit;
    }

    public function getProductInfo() {
        //buscamos un producto en cola, no procesando y el que lleve más tiempo en cola
        $sql_get_product = "SELECT id_product 
        FROM lafrips_redactor_descripcion 
        WHERE en_cola = 1
        AND procesando = 0
        AND error = 0
        ORDER BY date_metido_cola ASC";        

        if ($id_product = Db::getInstance()->getValue($sql_get_product)) {
            //sacamos la info necesaria para enviar a la API de redacta.me
            //por defecto, para el proceso automático, no enviamos keywords y el tono es siempre Professional
            //damos por buenos el nombre y la descripción corta en español del producto, cortando a 50 y 500 carácteres respectivamente por si acaso, dado que es el límite de la API
            //11/03/2024 He cambiado el proceso de modo que en el campo api_json se guarda el json de la primera vez que se hace petición a la api, de modo que ahí estaría guardada la descripción original preparada para la api, en el caso de estar preparada. Para el caso de que se meta en cola un producto que ya fue procesado para descripción, primero comprobamos si existe ese valor en api_json y si es así sacamos la desciprión original para enviar a la api. de este modo evitamos que aquí recojamos una descripción recortada a 500 caracteres que podría no funcionar bien.
            // $sql_info_producto = "SELECT SUBSTRING(name, 1, 50) AS nombre, SUBSTRING(description_short, 1, 500) AS descripcion 
            // FROM lafrips_product_lang WHERE id_lang = 1 AND id_product = $id_product";
            //18/09/2024 redacta.me ha ampliado el límite de caracteres de la descripción de 500 a 5000
            //02/01/2025 Añadimos OpenAI para sacar descripciones, de modo que en lafrips_redactor_descripcion hemos añadido el campo 'api' donde tenemos la api que queremos utilizar. 
            //Para Redacta.me CORTAREMOS EN SU CLASE al límite que ponen, enviamos texto completo
            //22/05/2025 Añadimos el contenido de description que debe ser el nombre y enlace a la categoría principal
            //28/05/2025 A partir de ahora, además de solo usar openai, guardaremos la info que enviamos a la api, lo que sería la descripción corta caundo aún no se ha redactado, en un campo de la bd, pudiendo modificar ese texto desde el controlador y guardarlo. Es el campo info_para_api, que la primera vez estará vacío como con api_json. Por tanto, ya no necesitaríamos sacar api_json para obtener el texto, y podemos actualizar api_json cada vez que solicitemos redacción.

            $sql_info_producto = "SELECT name AS nombre, description_short AS descripcion, red.info_para_api AS info_para_api
            FROM lafrips_product_lang pla
            JOIN lafrips_redactor_descripcion red ON red.id_product = pla.id_product
            WHERE pla.id_lang = 1 
            AND pla.id_product = $id_product";

            $info_producto = Db::getInstance()->getRow($sql_info_producto);

            //si hay info_para_api almacenado sacamos la descripción de ahí, sino del producto
            if ($info_producto['info_para_api'] && $info_producto['info_para_api'] != "") {  
                //si hemos encontrado algo lo guardamos como descripcion, sino lo dejamos como estaba, con la del producto
                $info_producto['descripcion'] = $info_producto['info_para_api'];
                               
            } 
            
            if (!$info_producto['nombre'] || !$info_producto['descripcion']) {
                $sql_error = "UPDATE lafrips_redactor_descripcion
                SET
                error = 1, 
                date_upd = NOW()
                WHERE id_product = $id_product";

                Db::getInstance()->executeS($sql_error);  

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error obteniendo Nombre y Descripción para id_product '.$id_product.PHP_EOL, FILE_APPEND);

                //devolvemos 'error', simplemente porque no es ni true ni false, de modo que continuará con el do - while
                return 'error';
            }

            //preparamos info para la función que llamará a la API 
            // $this->api_seleccionada = $info_producto['api_seleccionada'];

            $this->info_api = array(
                "id_product" => $id_product,
                "title" => $info_producto['nombre'],
                "description" => $info_producto['descripcion']
            );                              

            return true;

        } else {
            return false;
        }
    }    

    //función que devuelve la descripción almacenada en api_json, si la hay, buscando en el json para los casos de que sea de api redactame o api openai. Recibe el json sin decodificar
    //28/05/2025 YA NO SE USA   
    public function getApiDescription($json) {
        //primero probamos a sacar si fuera de Openai, si no contiene nada probamos con redactame, si no devolvemos null
        $info_api = json_decode($json, true);

        // Filtrar el mensaje con role "user"
        $user_messages = array_filter($info_api['messages'], function($message) {
            return $message['role'] === 'user';
        });

        // Acceder al contenido de tipo "text"
        $text = null;
        if (!empty($user_messages)) {
            //Usamos reset para obtener el primer elemento del array filtrado. Iteramos sobre content buscando el primer elemento con type: text.
            $user_content = reset($user_messages)['content'];
            foreach ($user_content as $content) {
                if ($content['type'] === 'text') {
                    $text = $content['text'];

                    break;
                }
            }
        }

        if($text) {
            return $text;
        } else {
            //probamos con redactame
            $text = $info_api['parameters']['Description'];

            if($text) {
                return $text;
            } else {
                //no encontramos texto, devolvemos null
                return null;
            }
        }       
    }

    //función que busca productos con proceando =1 y si inicio_proceso es superior a x tiempo, los deja en procesando = 0 para que se procesen en otra pasada.
    public function checkProcesando() {
        //sacamos productos con procesando = 1
        $sql_productos_procesando = 'SELECT id_redactor_descripcion, id_product, inicio_proceso, error
        FROM lafrips_redactor_descripcion 
        WHERE procesando = 1';
        $productos_procesando = Db::getInstance()->executeS($sql_productos_procesando);

        $contador = 0;

        if (count($productos_procesando) > 0) { 
            foreach ($productos_procesando AS $producto) {
                $id_redactor_descripcion = $producto['id_redactor_descripcion'];                 
                $inicio_proceso = $producto['inicio_proceso'];
                $id_product = $producto['id_product'];
                $error = $producto['error'];

                //comprobamos cuanto tiempo lleva procesando y si es más de 10 minutos (se ha quedado bloqueado por lo que sea) lo volvemos a poner en procesando 0 para que la siguiente pasada del proceso lo vuelva a intentar. Metemos mensaje en error_message
                //dividimos entre 60 para sacar cuantos minutos son la diferencia de segundos       
                $diferencia_minutos =  round((strtotime("now") - strtotime($inicio_proceso))/60, 1);

                //si hubiera error = 1 lo vamos a dejar como está para revisar manualmente, si no lo reseteamos
                if (($diferencia_minutos >= 10) && ($error == 0)) {          
                    $contador++;         
                    
                    Db::getInstance()->Execute("UPDATE lafrips_redactor_descripcion
                    SET
                    procesando = 0, 
                    inicio_proceso = '0000-00-00 00:00:00',                                            
                    error_message = CONCAT(error_message, ' | Proceso reiniciado - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),                              
                    date_upd = NOW()
                    WHERE id_redactor_descripcion = ".$id_redactor_descripcion );          
                    
                    if ($contador == 1) {
                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Reseteo productos "procesando"'.PHP_EOL, FILE_APPEND);
                    }

                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Reseteado "procesando" para id_product '.$id_product.PHP_EOL, FILE_APPEND);
                    
                    continue;
                } else {
                    //lleva menos de X tiempo procesando o tiene error, lo ignoramos de momento
                    if ($error == 1) {
                        $contador++;

                        if ($contador == 1) {
                            file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
                            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Reseteo productos "procesando"'.PHP_EOL, FILE_APPEND);
                        }

                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - No Reseteado "procesando" para id_product '.$id_product.' - Tiene error = 1'.PHP_EOL, FILE_APPEND);
                    }

                    continue;
                }
            }
        }

        if ($contador > 0) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Fin Reseteo productos "procesando"'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);            
        }

        return;
    }

}

?>