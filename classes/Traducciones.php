<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');


//https://lafrikileria.com/modules/redactor/classes/Traducciones.php
//https://lafrikileria.com/test/modules/redactor/classes/Traducciones.php

//ATENCIÓN OJO OJETE
//llamamos al proceso vía cron con esta url que llama a otro proceso que hará una petición cURL de este, para evitar la desconexión que se produce a veces
// https://lafrikileria.com/modules/redactor/classes/LlamaColaTraduccionesCurl.php

//27/03/2024 Este proceso puede ser llamado por una tarea cron con parámetros GET $cola, $id_product e $id_lang, de modo que si viene $cola como true procederá a traducir una cantidad de productos que seleccionará en función de la disponibilidad de venta etc, o puede ser llamado directamente con el id_product del producto a traducir. Además puede llevar GET id_lang que indicará el idioma a traducir. Si hay id_product e id_lang se traducirá solo ese idioma, si hay id_product pero no id_lang se traducirán todos los idiomas e igual para proceso "cola"

// TODO MODIFICAR para indicar el campo a traducir como parámetro al llamar a la clase _construct($cola = false, $id_lang = false, $id_product = false, $campo = null)

// los parámetros GET no son int así que habrá que hacer cast
// https://lafrikileria.com/modules/redactor/classes/Traducciones.php?cola=true..
// https://lafrikileria.com/test/modules/redactor/classes/Traducciones.php?id_product=55724&id_lang=11

//16/04/2024 Añadimos un proceso inicial que buscará productos sin traducir, para uno o varios idiomas, y que estén disponibles a la venta

//22/04/2024 Vamos a añdir la posibilidad de ignorar ciertas palabras o grupos de palabras para que Deepl no las traduzca. Para ello usamos el pará,etro de la API "ignore_tags" con x. Eso quiere decir que lo que vaya envuelto en etiqeutas html <x></x> no será traducido. Para ello utilizo el método del módulo, que es pasar el texto por una función antes de enviarlo a Deepl. Esta función buscará en BD las palabras que queremos ignorar y si las encuentra en el texto les añadirá las etiqeutas. Después, al recibir la traducción pasará el texto recibido por otra función que retira las etiquetas.

//si vienen como parámetros id_product y cola es un error
if (isset($_GET['id_product']) && isset($_GET['cola'])) { 
    exit;
}

//si viene cola
if (isset($_GET['cola']) && $_GET['cola'] == 'true') {
    if (isset($_GET['id_lang']) && $_GET['id_lang'] > 0) {
    
        $a = new Traducciones(true, $_GET['id_lang']);
    
    } else {

        $a = new Traducciones(true);

    }
} else if (isset($_GET['id_product']) && $_GET['id_product'] > 0) {    
 
    if (isset($_GET['id_lang']) && $_GET['id_lang'] > 0) {
    
        $a = new Traducciones(false, $_GET['id_lang'], $_GET['id_product']);
    
    } else {

        $a = new Traducciones(false, false, $_GET['id_product']);

    }   

} 
// para que al hacer el require_once de esta clase donde la necesite, quitamos el else o se ejecutará cada vez que se haga dicho require
// else {

//     $a = new Traducciones();

// }  


class Traducciones 
{    
    public $cola = null;

    public $id_product = null;

    public $id_lang = null;

    public $lang_iso;

    //variable que indica uno o varios idiomas para traducir
    public $un_idioma = false;

    //array que contine los id_lang a los que traducir los productos si no se indica un id_lang en particular
    /*
    1  - ES
    11 - EN
    12 - FR
    13 - IT
    14 - DE
    15 - NL
    16 - SV
    17 - PL
    18 - PT
    19 - BE, sería FR, pero no poner
    */
    public $langs = array(12,18);

    //array que indica los idiomas a los que asignar a la cola cuando busquemos productos a la venta sin traducir. Lo diferenciamos del otro $langs porque ese indica que productos hay que traducir, a que idiomas, y este indica qué idiomas meter a cola de los productos, de modo que podríamos estar traduciendo solo el inglés pero ir añadiendo y preparando los productos a otro idioma. Por ejemplo, en este momento no hay productos traducidos del inglés, si pongo a traducir los de inglés y francés que entren en cola y pongo a meter en cola los de id_lang de inglés meterá varios miles de golpe. Con esto puedo meter a cola manualmente los de inglés en bloques mientras los nuevos van entrando a francés solamente al tenerlo en esta variable
    public $langs_cola = array(12,18);

    //array que contiene los campos a traducir. El proceso de traducción pasará por cada campo para ir llamando a la API pidiendo traducción. Por ahora meto array('name','description_short','description'); LO HACEMOS sacando por producto e id_lang de la tabla lafrips_product_langs_traducciones los que aún no estén traducidos
    public $campos = array();

    public $campo;

    //array para almacenar productos a traducir sacados de lafrips_product_langs_traducciones. Será un combo id_product-id_lang
    public $products = array();

    //límite productos a procesar en proceso cola. Con descripciones muy largas dejamos 30
    public $limite_productos = 30;

    //almacenaremos el texto actual (en español por ahora) de lo que vamos a traducir
    public $texto_original;

    //almacenaremos la traducción en proceso, sea el idioma que sea
    public $traduccion;

    public $api_key;

    public $deepl_endpoint = 'https://api.deepl.com/v2/';

    //los caracteres que se han consumido durante este proceso, resultado de restar $response_decode->character_count al comienzo del proceso al mismo valor al final del proceso
    public $caracteres_consumidos_proceso;

    //iniciamos con null para asegurar que en la primera llamada a api usage irá vacío. Guardaremos $response_decode->character_count. Son los caracteres que se han consumido en total de la cuenta de Deepl en el período de consumo actual (mensual? semanal? por pago?)
    public $caracteres_consumidos_cuenta_deepl = null;    

    //el número de caracteres en total disponibles (usados o no) en la cuenta de Deepl en el momento
    public $caracteres_totales_cuenta_deepl;
    
    //subirá una unidad por cada producto traducido a un idioma
    public $contador_productos = 0;

    //contador campos enviados a traducir
    public $contador_campos = 0;
    
    //momento de inicio del script, en segundos, para comparar con max_execution_time
    public $inicio;

    //un segundo max execution time definido a x segundos para programarlo varias veces por hora sin que se solapen
    //18 minutos 1080 segundos
    //8 minutos (480 sec) para ejecutarlo cada 10 min
    //4 minutos 240 sec para ejecutarlo cada 5 min
    public $my_max_execution_time = 240;

    public $log_file = _PS_ROOT_DIR_.'/modules/redactor/log/traducciones.txt';
    public $error = 0;
    public $mensajes_error = array();

    //envoltorio para palabras que no queremos traducir, etiquetas html.
    public $excluded_words_wrappers = array('<x>', '</x>');

    //variable donde se alamcenarán las palabras para no traducir. Se almacenan en la variable del propio módulo de traducciones dgcontenttranslation, en tabla lafrips_configuration, name dingedi_excluded. TODO La idea es crear otra "nuestra" en la tabla para ir almacenando nuevas palabras 
    public $excluded_words = array();
    
    public function __construct($cola = false, $id_lang = false, $id_product = false)
    {	                
        //15/04/2024 lo primero que vamos a hacer es asegurarnos de que tenemos carácteres disponibles para seguir traduciendo. Para ello llamaremos a apiusage, y guardaremos los valores en sus variables. Si caracteres consumidos es igual que caracteres disponibles no continuaremos (o si quedan menos de 128 que es lo máximo para un nombre de producto). Además al llamar a la api para traducir comprobaremos el código http, que debe ser 200. El código que indica Quota exceded es 456.
        //obtenemos la key de la API de Deepl que tenemos almacenada en redactor/secrets/api_deepl.json. Lo hacemos aquí para no sacarla por cada traducción si son varias
        $this->api_key = $this->getApiKey();

        //pedimos el uso de caracteres a la api para dejar log de los iniciales y los finales
        if (!$this->apiUsage()) {
            // exit;
        }   
        
        if ((!$cola && !$id_product) || ($cola && $id_product)) {
            // echo '<br>no cola ni id_product o ambos';
            //no vienen los parámetros cola ni id_product o vienen ambos, salimos
            exit;
        }

        $this->inicio = time();
        // $this->my_max_execution_time = ini_get('max_execution_time')*0.9; //90% de max_execution_time            

        if ($cola) {
            $this->cola = true;

            //16/04/2024 Añadimos un proceso inicial que buscará productos sin traducir, y que estén disponibles a la venta, para uno o varios idiomas, que indicaremos en la variable $this->langs_cola
            $this->checkCola();
        }

        if ($id_lang) {
            $this->id_lang = $id_lang;

            $this->un_idioma = true;

            if (!$this->checkIdlang()) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, id_lang desconocido, imposible continuar. id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND);  
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);

                exit;
            }
        }

        if ($id_product) {
            //proceso de traducción de un solo producto
            $this->id_product = $id_product;

            if (!$this->checkIdproduct()) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR, id_product desconocido, imposible continuar. id_product: '.$this->id_product.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);

                exit;
            }           
            
        }  
            
        //obtenemos de la API el uso que llevamos de caractéres y lo que nos queda hasta el límite
        //Por ahora no lo uso dado que la propia API se supone que nos avisará cuando no quede crédito de caractéres. OJO si lo activo porque aquí aún no está setLog() activo y quedará raro en log
        // if (!$this->apiUsage()) {
        //     exit;
        // }

        $this->start();
        
    }

    public function start() {       

        // echo '<br>api_key= '.$this->api_key;
        // echo '<br>cola= '.($this->cola ? 'si' : 'no');
        // echo '<br>id_product= '.($this->id_product ? $this->id_product : 'no');
        // echo '<br>id_lang= '.($this->id_lang ? $this->id_lang : 'no');

        //si el proceso es cola vamos a función para obtener productos y procesar uno a uno, si no es cola vamos directamente a función para procesar un producto
        if ($this->cola) {
            if ($this->getProducts()) {                
                $this->setLog(); 

                //hemos obtenido un array $this->products() con arrays dentro que contienen la combinación id_product id_lang a traducir. Vamos a la función para recorrerlo
                $this->procesaProductos();
            } else {
                exit;
            }
        } else {
            //si el proceso es para producto individual, llamamos a setProduct() que preparará el array $this->products con ese único producto, y los id_lang necesarios
            $this->setProduct();

            $this->setLog(); 

            $this->procesaProductos();            
        }

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Alcanzado fin del proceso de traducción.'.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Total productos-idioma traducidos: '.$this->contador_productos.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Total campos traducidos: '.$this->contador_campos.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tiempo total empleado (segundos): '.(time() - $this->inicio).PHP_EOL, FILE_APPEND); 

        //pedimos el uso de caracteres a la api para dejar log de los iniciales y los finales. En este punto final no abandonamos el proceso si no hay respuesta
        $this->apiUsage();            

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Caractéres consumidos de cuenta en final: '.$this->caracteres_consumidos_cuenta_deepl.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Caractéres consumidos durante proceso: '.$this->caracteres_consumidos_proceso.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Caractéres disponibles en cuenta: '.($this->caracteres_totales_cuenta_deepl - $this->caracteres_consumidos_cuenta_deepl).PHP_EOL, FILE_APPEND);

        if ($this->error) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - PROCESO FINALIZADO CON ERRORES'.PHP_EOL, FILE_APPEND);

            $this->enviaEmail();
        }

        exit;
    }

    //recorremos $this->products que contiene uno o varios arrays con el producto o productos a traducir, y el id_lang o id_langs a traducir
    public function procesaProductos() {
        $this->id_product = null;
            
        $this->id_lang = null;

        $this->lang_iso = null;

        //22/04/2024 Obtenemos las palabras a NO traducir. Por ahora almacenadas en la variable de lafrips_configuration del módulo de traducciones, 
        $this->excluded_words = array_filter(explode(',', Configuration::get('dingedi_excluded')));

        foreach ($this->products AS $producto) {
            $this->id_product = $producto['id_product'];
            
            $this->id_lang = $producto['id_lang'];

            $this->lang_iso = Language::getIsoById($this->id_lang);

            //llamamos a función que procesa el producto
            $this->procesaProducto();
              
            if ($this->contador_productos >= $this->limite_productos) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Alcanzado límite de productos ('.$this->limite_productos.') - Productos - Idioma procesados: '.$this->contador_productos.' - Interrupción de proceso'.PHP_EOL, FILE_APPEND);

                break;
            }

            if (((time() - $this->inicio) >= $this->my_max_execution_time)) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tiempo ejecución alcanzando límite - Interrupción de proceso'.PHP_EOL, FILE_APPEND);

                break;
            }
        }        

        return;
    }
    
    //función a la que llamamos cuando el proceso es para un solo producto. Cuando procesamos los productos para traducir sacamos su id_product y el id_lang de $this->products, lo que hacemos aquí es meter en $this->products el producto único a traducir, una o varias veces según id_lang. En este punto tenemos el id_product si es producto único en $this->id_product, y si es traducir a un solo idioma, su id_lang estará en $this->id_lang
    public function setProduct() {
        if ($this->un_idioma) {
            //solo se hace la traducción a un idioma indicado en $this->id_lang      
            $this->products[] = array(
                "id_product" => $this->id_product,
                "id_lang" => $this->id_lang
            );

        } else {
            //se traducirá a los idiomas indicados en $this->langs, de modo que vamos metiendo cada uno en el array $products
            foreach ($this->langs AS $id_lang) {   

                $this->products[] = array(
                    "id_product" => $this->id_product,
                    "id_lang" => $id_lang
                );
            }
        }

        return;
    }

    //esta función procesa el producto $this->id_product para el idioma $this->id_lang. Estas variables se asignan previamente
    //traduciremos los campos sin traducir que se encuentren en lafrips_product_langs_traducciones y que meteremos a un array $campos para ir recorriéndolo
    public function procesaProducto() {
        $this->campos = array();

        $this->campo = null;

        //marcamos procesando en tabla traducciones
        if (!$this->updateProductLangTraducciones('inicio')) {
            return;      
        }

        //buscamos los campos a traducir, si devuelve false por que están todos traducidos etc quitamos procesando y pasamos al siguiente saliendo de la función
        if (!$this->getCampos()) {
            $this->updateProductLangTraducciones('final');

            return;
        }

        foreach ($this->campos AS $campo) {
            $this->campo = $campo;

            //obtenemos el campo a traducir, en español siempre, ya que es la que en principio está completa. En caso de estar vacío, que solo debería producirse con descripción larga en todo caso, devolvemos false, ya que si es nombre o descripción corta hay un error 
            if (!$this->texto_original = $this->getTextoProducto()) {
                //si el campo vacío es descripción larga no lo consideramos error
                if ($this->campo == 'description') {
                    //marcaremos traducido a pesar de que no había nada
                    if ($this->updateProductLangTraducciones('campo')) {
                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, campo '.$this->campo.' de producto en español está vacío, pero es prescindible, marcado traducido. id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND);         
                        
                    } else {
                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, hubo problemas actualizando a traducido tabla lafrips_product_langs_traducciones para campo '.$this->campo.', que estaba vacío pero es prescindible. - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND);
        
                        $this->error = 1;
                    
                        $this->mensajes_error[] = ' - Atención, hubo problemas actualizando a traducido tabla lafrips_product_langs_traducciones para campo '.$this->campo.', que estaba vacío pero es prescindible. - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang; 
                        
                        $this->setError('Campo '.$this->campo.' de producto en español está vacío siendo prescindible pero falló el update a traducido');
                    }  

                } else {
                    //el resto de campos deberían contener algo
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, campo '.$this->campo.' de producto en español está vacío. id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 

                    $this->error = 1;

                    $this->mensajes_error[] = '- Error, campo '.$this->campo.' de producto en español está vacío. id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;

                    $this->setError('Campo '.$this->campo.' de producto en español está vacío');
                }                

                continue;                
            }

            // echo '<br><br>Texto:<br> '.$this->texto_original;

            if (!$this->traduccion = $this->traduceTexto()) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, no se obtuvo traducción para campo: '.$this->campo.'. id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 

                $this->error = 1;

                $this->mensajes_error[] = '- Error, no se obtuvo traducción para campo: '.$this->campo.'. id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;                

                continue;
            }

            // echo '<br><br>Traducción:<br>'.$this->traduccion;

            if (!$this->guardaTraduccion()) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, no se pudo almacenar la traducción o falló la actualización de tabla lafrips_product_langs_traducciones. - campo: '.$this->campo.'. id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 

                $this->error = 1;

                $this->mensajes_error[] = '- Error, no se pudo almacenar la traducción o falló la actualización de tabla lafrips_product_langs_traducciones. - campo: '.$this->campo.'. id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;
           
                continue;
            }

            $this->contador_campos++;
        }          
        
        //quitamos procesando, cola marcamos completo si corresponde        
        if (!$this->updateProductLangTraducciones('final')) {
            return;      
        }

        //marcamos completo si corresponde        
        if (!$this->updateProductLangTraducciones('completado')) {
            return;      
        }

        $this->contador_productos++;

        return;
    }


    //función que llama a la API de Deepl y pide y alamacena la traducción
    public function traduceTexto() {
        //preparamos los parámetros POST. target_lang es el ISO del idioma destino, source_lang es el ISO del idioma original del texto, en este caso ES de Español, probablemente no sea necesario. preserve_formating, ignore_tags y tag_handling sería lo que nos permite conservar los tags html, para negritas etc, aunque ahora no estoy seguro de que conlleva cada una.  
        
        //22/04/2024 Procesamos el texto para etiqeutar las palabras que no queremos traducir
        $text = $this->excludeWords($this->texto_original, true);
        
        $array = array(
            "text" => $text,            
            "target_lang" => strtoupper($this->lang_iso),
            "source_lang" => "ES",
            "preserve_formating" => 1,
            "ignore_tags" => "x",
            "tag_handling" => "xml"
        ); 

        //como la llamada a esta API requiere enviar los parámetros como parte del body en formato x-www-form-urlencoded, en lugar de meterloa ajson_encode lo pasamos por http_build_query para ese formato url
        $post_fields = http_build_query($array);               

        // echo '<br><br>post_fields:<br>'.$post_fields;        

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $this->deepl_endpoint.'translate',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: DeepL-Auth-Key '.$this->api_key
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

            $error_message = 'Error API Deepl para Traducción - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.' - Campo: '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes_error[] = ' - '.$error_message.' - Campo: '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;

            $this->setError($error_message);
            
            return false;            
        }
        
        if ($response) {            
            // $curl_info = curl_getinfo($curl);

            // $connect_time = $curl_info['connect_time'];
            // $total_time = $curl_info['total_time'];

            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response);             

            if ($http_code != 200) {
                //el código http no es 200 OK, aunque no tenga por que ser un error fatal, entendemos que la respuesta tiene algún problema. En principio debería devolver un mensaje
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API a petición de traducción no es correcta - La API devolvió un mensaje a la petición de traducción - Campo: '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$response_decode->message.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes_error[] = ' - Atención, la respuesta de la API a petición de traducción no es correcta - La API devolvió un mensaje a la petición de traducción - Campo: '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang; 
                $this->mensajes_error[] = ' - Http Response Code = '.$http_code;
                $this->mensajes_error[] = ' - API Message: '.$response_decode->message;

                //si es error 500, cosa de ellos, service unavailbale, etc, no marcamos error
                if (($http_code < 500) || ($http_code > 599)) {
                    $this->setError('Error API, Mensaje: '.$response_decode->message);
                }

                return false;
            }
            
            // echo '<pre>';
            // print_r($response);
            // echo '</pre>';

            //a 02/04/2024 si la API devuelve correctamente la traducción sé que devuelve un json con un elemento translations, que contiene un array con otros dos, detected_source_langauage, que será el ISO del idioma detectado del texto original, y text, que será la traducción obtenida. Si en lugar de "translations" devuelve "message" suele ser un error, probablemente el http code no sea 200 y no lleguemos hasta aquí, pero por si acaso, lo devolvemos
            if ($response_decode->message) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la API devolvió un mensaje a la petición de traducción - Campo: '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Mensaje: '.$response_decode->message.PHP_EOL, FILE_APPEND);

                $this->error = 1;
            
                $this->mensajes_error[] = ' - Atención, la API devolvió un mensaje a la petición de traducción - Campo: '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;
                $this->mensajes_error[] = ' - Mensaje: '.$response_decode->message;

                $this->setError('Error API, Mensaje: '.$response_decode->message);
                
                return false;

            } else if ($response_decode->translations) {
                //recordemos que "translations" sería un array, en este caso contiene otro objeto en su primera posición, de modo que sacamos la propiedad text del objeto en 0 del array translations, del objeto response_decode. Seguro que se puede sacar directamente...
                //por ahora ignoramos $response_decode->translations[0]->detected_source_language

                //22/04/2024 Procesamos la respuesta para retirar las etiquetas de NO traducir antes de devolver la traducción        
                return $this->unexcludeWords($response_decode->translations[0]->text);
            }

        } else {
            
            //no hay respuesta pero cerramos igualmente
            curl_close($curl);           

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, la API no responde a la petición de traducción - Campo: '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes_error[] = ' - Error, la API no responde a la petición de traducción - Campo: '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;

            $this->setError('Error API, no responde a la petición de traducción - Campo: '.$this->campo);

            return false;
        }          

        // return true;
    }


    public function getTextoProducto() {
        //el segundo parámetro false indica que no instanciamos el producto completo, para ahorrar recursos. 1 indica id_lang 1 que es Español. Podría sacarlo poniendo $product->description_short[1] pero así evitamos sacar el resto de idiomas.
        $product = new Product($this->id_product, false, 1);
        // $description = $product->description_short;       

        //para que se construya $product->name etc correctamente teniendo el nombre de la propiedad del objeto $product en una variable de clase tipo $this->campo = 'name' lo ponemos con corchetes
        $text = $product->{$this->campo}; 

        //vamos a sustituir ampersand por "y" aunque no sea algo seguro, dado que Deepl siempre devuelve &amp; cuando hay & en el texto y no encuentro la forma de momento de que lo procese de otra forma. Además, cuando pasa en el nombre, se produce una excepción, ya que el nombre del producto no puede tener puntos, punto y coma etc
        // file_put_contents($this->log_file, date('Y-m-d H:i:s').' -  Campo antes: '.$text.PHP_EOL, FILE_APPEND);

        //lo quito por la posibilidad de que haya & en una url de youtube o algo parecido
        // $text = str_replace('&', ' y ', $text);

        // file_put_contents($this->log_file, date('Y-m-d H:i:s').' -  Campo después: '.$text.PHP_EOL, FILE_APPEND);

        return !empty($text) ? $text : null;
    }

    public function guardaTraduccion() {
        //instanciamos el producto para actualizar el texto, solo para $this->id_lang
        $product = new Product($this->id_product);
        // para descripciones cuando solo queremos afectar a un lenguaje
        // $product->description_short[$this->id_lang] = $this->traduccion;  
        
        // $product->{$this->campo}[$this->id_lang] = $this->traduccion;

        //hasta que vea como evitar el cambio de & por &amp; lo sustituyo, ya que al traducir inglés también mete mucho el &
        $product->{$this->campo}[$this->id_lang] = str_replace('&amp;', '&', $this->traduccion);

        try {
            $product->update();          

            if ($this->updateProductLangTraducciones('campo')) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Traducción efectuada correctamente. - Campo '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND);

                return true;
            } else {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, hubo problemas actualizando tabla lafrips_product_langs_traducciones pero la traducción se almacenó correctamente. - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND);

                $this->error = 1;
            
                $this->mensajes_error[] = ' - Atención, hubo problemas actualizando tabla lafrips_product_langs_traducciones pero la traducción se almacenó correctamente. - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;                

                return false;
            }     

        } catch (Exception $e) {            

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error almacenando la traducción. - Campo '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Texto a guardar: '.$this->traduccion.PHP_EOL, FILE_APPEND);             
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Excepción: '.$e->getMessage().PHP_EOL, FILE_APPEND);     
            
            $this->error = 1;
            
            $this->mensajes_error[] = ' - Error almacenando la traducción. - Campo '.$this->campo.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;
            $this->mensajes_error[] = ' - Texto a guardar: '.$this->traduccion;
            $this->mensajes_error[] = ' - Excepción: '.$e->getMessage();

            $this->setError('Error almacenando traducción Campo '.$this->campo.' - Excepción: '.$e->getMessage());

            return false;
        }
        
    }
    
    //función para hacer updates a lafrips_product_langs_traducciones, marcar como traducido un campo, poner y quitar procesando, quitar y poner en_cola, marcar completado si lo está. 
    //para cada vez que llegamos aquí, en $this->id_product y $this->id_lang tenemos los datos necesarios, y para el caso de hacer update de campo, lo tenemos en $this->campo
    //el parámetro recibido puede ser 'inicio' para indicar marcar procesando y inicio_proceso, 'final' para quitar procesando y en_cola, 'completado' para comprobar si quedan campos por finalizar y marcar completado si está completo. 'campo' inidica poner el campo de $this->campo a 1
    //añadiré otro 'cola' para marcar en_cola a 1 y a poder ser date_metido_cola e id_empleado_metido_cola
    public function updateProductLangTraducciones($gestion) {      

        $procesando_inicio = "";
        $procesando_final = "";
        $en_cola = "";        
        $campo = "";
        $completado = "";

        if ($gestion == 'inicio') {

            $procesando_inicio = " procesando = 1,    
            inicio_proceso = NOW(), ";

        } elseif ($gestion == 'final') {

            $procesando_final = " procesando = 0, 
            en_cola = 0, ";

        } elseif ($gestion == 'campo') {

            $campo = " ".$this->campo." = 1, ";

        } elseif ($gestion == 'cola') {
            //aquí meteremos en_cola = 1, date_metido_cola e id_empleado_metido_cola
            $en_cola = "";   

        } elseif ($gestion == 'completado') {
            //comprobamos si quedan campos sin traducir, si no marcamos completado etc
            if ($this->productoCompleto()) {
                //el producto está completo
                $completado = " completo = 1, 
                date_completo = NOW(), ";
                
            } else {
                //si no está completo no queremos hacer update
                return true;
            }

        }
        
        $sql_update = "UPDATE lafrips_product_langs_traducciones
        SET 
        $procesando_inicio
        $procesando_final
        $campo
        $en_cola 
        $completado      
        date_upd = NOW()
        WHERE id_product = ".$this->id_product."
        AND id_lang = ".$this->id_lang;

        if (!Db::getInstance()->execute($sql_update)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error actualizando tabla lafrips_product_langs_traducciones, gestión -'.$gestion.
            '- '.($gestion == 'campo' ? ' para campo '.$this->campo : '').' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes_error[] = ' - Error actualizando tabla lafrips_product_langs_traducciones, gestión -'.$gestion.
            '- '.($gestion == 'campo' ? ' para campo '.$this->campo : '').' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;

            $this->setError('Error actualizando tabla, gestión -'.$gestion.
            '- '.($gestion == 'campo' ? ' para campo '.$this->campo : ''));

            return false;
        }      
        
        return true;
    }

    public function setError($error_message) {
        $error_message = pSQL($error_message);

        $sql_update = "UPDATE lafrips_product_langs_traducciones
        SET 
        error = 1,
        date_error = NOW(), 
        error_message = CONCAT(error_message, ' | $error_message - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),    
        date_upd = NOW()
        WHERE id_product = ".$this->id_product."
        AND id_lang = ".$this->id_lang;

        if (!Db::getInstance()->execute($sql_update)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error marcando Error en tabla lafrips_product_langs_traducciones. Mensaje error = '.$error_message.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes_error[] = ' - Error marcando Error en tabla lafrips_product_langs_traducciones. Mensaje error = '.$error_message.' - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;
        }

        return;
    }

    //función que devuelve true si el producto revisado tiene todos los campos de traducción a 1, indicando que está completa la traducción, y false si no está completo. 
    public function productoCompleto() {
        $sql_completo = "SELECT id_product_langs_traducciones 
        FROM lafrips_product_langs_traducciones
        WHERE `name` = 1
        AND `description` = 1
        AND description_short = 1
        AND meta_description = 1
        AND meta_title = 1
        AND id_product = ".$this->id_product."
        AND id_lang = ".$this->id_lang;

        //buscamos una línea para ese id_product e id_lang donde todos los campos estén traducidos. getValue() devuelve false si no la encuentra
        if (Db::getInstance()->getValue($sql_completo) !== false) {
            //el producto tiene los campos a 1
            return true;
        } else {
            //no está completo
            return false;
        }
    }

    //función que busca los productos que estando disponibles a la venta no tienen marcado completo en la tabla de traducciones, para los id_lang de $this->langs_cola y los encola para dichos id_lang
    public function checkCola() {
        $sql_update_cola = "UPDATE lafrips_product_langs_traducciones plt
        JOIN lafrips_product pro ON pro.id_product = plt.id_product
        SET
        plt.en_cola = 1, 
        plt.id_employee_metido_cola = 44,
        plt.date_metido_cola = NOW()
        WHERE plt.completo = 0
        AND plt.en_cola = 0
        AND plt.ignorar = 0
        AND pro.active = 1
        AND plt.error = 0
        AND pro.visibility = 'both'
        AND plt.id_lang IN (".implode(",", $this->langs_cola).")";

        $encolados = Db::getInstance()->execute($sql_update_cola);        

        if ($encolados !== false) {
            //Db::getInstance()->execute(update) devuelve 1 o false (true or false) si ha funcionado el update o no. Con numRows sabemos a cauntas líneas ha afectado la última sql, si es 0 es que no había productos
            $affected_rows = Db::getInstance()->numRows();
            if ($affected_rows > 0) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Añadidos '.$affected_rows.' producto-idioma a cola de traducción, para id_lang/s '.implode(",", $this->langs_cola).PHP_EOL, FILE_APPEND); 
            }
            
        } else {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error en update para añadir productos a cola para id_lang/s '.implode(",", $this->langs_cola).PHP_EOL, FILE_APPEND); 

            $this->error = 1;
        
            $this->mensajes_error[] = ' - Error en update para añadir productos a cola para id_lang/s '.implode(",", $this->langs_cola);
        }   

        return;

    }

    public function getApiKey() {
        //Obtenemos la key leyendo el archivo api_deepl.json donde hemos almacenado la contraseña para la API de Deepl
        $secrets_json = file_get_contents(dirname(__FILE__).'/../secrets/api_deepl.json');

        if (!$secrets_json) {
            $this->error = 1;
            
            $this->mensajes_error[] = ' - Error obteniendo API Key para Deepl';
        }
        
        $secrets = json_decode($secrets_json, true);

        //devolvemos la Api Key
        return $secrets['api_key'];
    }

    public function apiUsage() {
        //hacemos una llamada GET a la API a su endpoint /usage que nos devuelve dos valores en json, character_count y character_limit, el uso que llevamos y el total del que disponemos en nuestra cuenta. La cuenta PRO que tenemos admite 5 millones de caracteres al mes a 03/04/2024, esto depende del límite configurado en ella (ahora 100€)        

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $this->deepl_endpoint.'usage',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',        
        CURLOPT_HTTPHEADER => array(            
            'Authorization: DeepL-Auth-Key '.$this->api_key
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

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error haciendo petición a API Deepl para uso de caractéres - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']'.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes_error[] = ' - Error haciendo petición a API Deepl para uso de caractéres - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';
            
            return false;
            
        }
        
        if ($response) {            
            // $curl_info = curl_getinfo($curl);

            // $connect_time = $curl_info['connect_time'];
            // $total_time = $curl_info['total_time'];

            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response);             

            if ($http_code != 200) {
                //el código http no es 200 OK, aunque no tenga por que ser un error fatal, entendemos que la respuesta tiene algún problema. En principio debería devolver un mensaje
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, la respuesta de la API a petición de saldo no es correcta'.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$response_decode->message.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes_error[] = ' - Error, la respuesta de la API a petición de saldo no es correcta';
                $this->mensajes_error[] = ' - Http Response Code = '.$http_code;
                $this->mensajes_error[] = ' - API Message: '.$response_decode->message;

                return false;
            }
            
            // echo '<pre>';
            // print_r($response);
            // echo '</pre>';

            //a 03/04/2024 si la API devuelve correctamente la petición sería un json con dos parámetros, character_count y character_limit
            //31/05/2024 Parece haber algún problema con esta API, voy a dejar que no se meta como error si la respuesta no es correcta ya que muchas veces parece que no funciona bien y son datos que no son imprescindibles
            if ($response_decode->character_count && $response_decode->character_limit) {
                // file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API usage: character_count = '.$response_decode->character_count.' - character_limit = '.$response_decode->character_limit.PHP_EOL, FILE_APPEND);                

                //si $this->caracteres_consumidos contiene algo quiere decir que se llenó en la primera llamada a apiUsage() y ahora estamops en la segunda, al terminar el proceso
                if ($this->caracteres_consumidos_cuenta_deepl) {
                    //sacamos los caracteres consumidos en el proceso actual restando los iniciales a los actuales
                    $this->caracteres_consumidos_proceso = $response_decode->character_count - $this->caracteres_consumidos_cuenta_deepl;

                    //hace las veces de caracteres consumidos al final del proceso
                    $this->caracteres_consumidos_cuenta_deepl = $response_decode->character_count;

                    $this->caracteres_totales_cuenta_deepl = $response_decode->character_limit;
                } else {
                    //hace las veces de caracteres consumidos al inicio del proceso
                    $this->caracteres_consumidos_cuenta_deepl = $response_decode->character_count;

                    $this->caracteres_totales_cuenta_deepl = $response_decode->character_limit;

                    //Estamos al inicio del proceso, si la diferencia entre los caracteres consumidos de la cuenta y los totales de la cuenta no es superior a 128 (name) salimos del proceso. Comprobaremos la hora y unicamente enviaremos email de aviso si estamos entre el minuto 10 y el minuto 20 de la hora
                    if (($this->caracteres_totales_cuenta_deepl - $this->caracteres_consumidos_cuenta_deepl) <= 128) {                       

                        $this->error = 1;
                        
                        $this->mensajes_error[] = ' - Error, agotado saldo de caracteres de cuenta Deepl';
                        $this->mensajes_error[] = ' - Caracteres totales cuenta: '.$this->caracteres_totales_cuenta_deepl;
                        $this->mensajes_error[] = ' - Caracteres consumidos cuenta: '.$this->caracteres_consumidos_cuenta_deepl;

                        if ((date("i") > '10') && (date("i") < '20')) {                             
                            file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
                            file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
                            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, agotado saldo de caracteres de cuenta Deepl'.PHP_EOL, FILE_APPEND);
                            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Caracteres totales cuenta: '.$this->caracteres_totales_cuenta_deepl.PHP_EOL, FILE_APPEND);
                            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Caracteres consumidos cuenta: '.$this->caracteres_consumidos_cuenta_deepl.PHP_EOL, FILE_APPEND); 

                            //mientras funcione el proceso sin saldo enviamos un email por hora
                            $this->enviaEmail();
                        }

                        return false;
                    }
                }                

                return true;     

            } else {
                //31/05/2024 Parece haber algún problema con esta API, voy a dejar que no se meta como error si la respuesta no es correcta ya que muchas veces parece que no funciona bien y son datos que no son imprescindibles
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, la petición de uso de caractéres a la API no ha devuelto los parámetros character_count y character_limit'.PHP_EOL, FILE_APPEND); 

                // $this->error = 1;
            
                $this->mensajes_error[] = ' - Error, la petición de uso de caractéres a la API no ha devuelto los parámetros character_count y character_limit';
                
                return false;
            }

        } else {
            
            //no hay respuesta pero cerramos igualmente
            curl_close($curl);            

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, la API no responde a la petición de uso de caractéres'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes_error[] = ' - Error, la API no responde a la petición de uso de caractéres';

            return false;
        }          

        // return true;
    }

    //esta función busca en lafrips_product_langs_traducciones el id_product y los campos a traducir por id_lang. En la tabla están los campos de lafrips_product_lang con 1 o 0 según ya estén traducidos para cada id_lang. Meteremos en array $this->campos cada campo sin traducir
    public function getCampos() {
        //en este punto tenemos un id_product y un id_lang
        $sql_get_campos = "SELECT `name`, `description`, description_short, meta_description, meta_title
        FROM lafrips_product_langs_traducciones
        WHERE id_product = ".$this->id_product."
        AND id_lang = ".$this->id_lang;

        $get_campos = Db::getInstance()->getRow($sql_get_campos);

        if (!$get_campos) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, el producto - idioma solicitado para traducción no se encuentra en lafrips_product_langs_traducciones - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes_error[] = ' - Atención, el producto - idioma solicitado para traducción no se encuentra en lafrips_product_langs_traducciones - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang;

            return false;
        }

        foreach ($get_campos as $key => $value) {
            if ($value == 0) {
                // si el campo del producto vale 0 hay que traducir y lo metemos a campos, si vale 1 ya esatá traducido. Como venimos de la función getProducts() y se filtran por completo = 0, el producto tiene que tener al menos un campo a 0, salvo que el proceso sea para un solo producto, en cuyo caso, si no hubiera campos sin traducir, devolveremos false
                $this->campos[] = $key;
            }
        }

        if (empty($this->campos)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, el producto - idioma solicitado para traducción no tiene campos sin traducir en lafrips_product_langs_traducciones - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND); 

            return false;
        }
        
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Campos a traducir para - id_product: '.$this->id_product.' - id_lang: '.$this->id_lang.' - Campos: '.implode(",", $this->campos).PHP_EOL, FILE_APPEND); 

        return true;
    }
    

    //función que busca x productos de lafrips_product_langs_traducciones que estén en cola, que no estén completos ni ignorar = 1 en función de $this->id_lang. Se guardará el combo  id_product-id_lang en un array para recorrer luego
    public function getProducts() {
        $sql_get_products = "SELECT id_product, id_lang
        FROM lafrips_product_langs_traducciones
        WHERE en_cola = 1
        AND completo = 0
        AND ignorar = 0
        AND error = 0
        AND id_lang IN (".($this->un_idioma ? $this->id_lang : implode(",", $this->langs)).")
        ORDER BY date_metido_cola ASC
        LIMIT ".$this->limite_productos;

        $product_lines = Db::getInstance()->executeS($sql_get_products);

        if (count($product_lines) < 1) {
            //de momento, cuando no haya productos en cola, para evitar líneas log diciendo que no hay productos para traducir, no ponemos mensaje, devovlemos false y va a exit
            // file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
            // file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
            // file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, no hay productos a la espera de traducción en lafrips_product_langs_traducciones para id_lang '.($this->un_idioma ? $this->id_lang : implode(",", $this->langs)).PHP_EOL, FILE_APPEND); 

            return false;
        }

        foreach ($product_lines AS $product_line) {
            $this->products[] = array(
                "id_product" => $product_line['id_product'],
                "id_lang" => $product_line['id_lang']
            );
        }

        return true;
    }    

    //función que devuelve true o false si el id_product existe o no en lafrips_product
	public function checkIdproduct()
    {        
        return (bool)Db::getInstance()->getValue("SELECT COUNT(*) FROM lafrips_product WHERE id_product = ".$this->id_product);
    }

    //función que devuelve true o false si el id_lang existe o no en lafrips_lang
	public function checkIdlang()
    {        
        return (bool)Db::getInstance()->getValue("SELECT COUNT(*) FROM lafrips_lang WHERE id_lang = ".$this->id_lang);
    }

    //22/04/2024 Función que prepara el texto añadiendo etiquetas html <x></x> a las palabras que no queremos traducir. Sacada del módulo dingedi dgcontenttranslation.
    //el tercer parámetro se podría utilizar en casos manuales, de momento lo dejamos como está
    public function excludeWords($text, $replace, $excludedWords = null)
    {
        $excluded = $this->excluded_words;

        //enc aso de recibir más palabras para no traducir en los parámetros de la función, se unen al array de palabras almacenado en lafrips_configuration
        if (is_array($excludedWords)) {
            $excluded = array_merge($excluded, $excludedWords);
        }

        if (empty($excluded)) {
            return $text;
        }

        if ($replace === true) {
            usort($excluded, function ($a, $b) {
                return strlen($a) < strlen($b);
            });

            $match = $this->excluded_words_wrappers[0] . "$0" . $this->excluded_words_wrappers[1];

            $groups = array_chunk($excluded, 150);

            foreach ($groups as $group) {
                $group = array_map(function ($i) {
                    $i = preg_quote($i, '/');

                    // if (\Tools::version_compare(PHP_VERSION, '7.3', '<')) {
                    //     $i = str_replace('#', '\#', $i);
                    // }

                    $i = str_replace('#', '\#', $i);

                    return $i;
                }, array_filter($group, function ($e) {
                    return trim($e) !== "";
                }));

                $text = preg_replace('/' . implode('|', array_filter($group)) . '/', $match, $text);
            }
        } else {
            usort($excluded, function ($a, $b) {
                return strlen($a) > strlen($b);
            });

            foreach ($excluded as $excluded_word) {
                $match = $this->excluded_words_wrappers[0] . $excluded_word . $this->excluded_words_wrappers[1];

                $text = str_replace($match, $excluded_word, $text);
            }
        }

        return $text;
    }

    //22/04/2024 Función que "limpia" el texto devuelto por la api quitando las etiquetas html <x></x> a las palabras que no queremos traducir. Sacada del módulo dingedi dgcontenttranslation. Lo que hace es llamar a excludeWords() con el parámetro replace como false.
    public function unexcludeWords($text)
    {
        return $this->excludeWords($text, false);
    }

    public function setLog() {          
        
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso Traducciones'.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tiempo máximo ejecución - my_max_execution_time = '.$this->my_max_execution_time.PHP_EOL, FILE_APPEND);       
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - time() inicio '.$this->inicio.PHP_EOL, FILE_APPEND);
        // file_put_contents($this->log_file, date('Y-m-d H:i:s').' - PHP max_execution_time: '.ini_get('max_execution_time').PHP_EOL, FILE_APPEND);
        if ($this->cola) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Proceso para productos en cola - límite de productos-líneas: '.$this->limite_productos.PHP_EOL, FILE_APPEND);            
        } else {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Proceso para un solo producto id_product: '.$this->id_product.PHP_EOL, FILE_APPEND); 
        }
           
        if ($this->id_lang) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Proceso para un solo idioma id_lang: '.$this->id_lang.PHP_EOL, FILE_APPEND);
        } else {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Proceso para varios idiomas, id_lang: '.implode(",", $this->langs).PHP_EOL, FILE_APPEND);
        }             

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Caractéres consumidos de cuenta Deepl en inicio: '.$this->caracteres_consumidos_cuenta_deepl.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Total caractéres disponibles en cuenta: '.$this->caracteres_totales_cuenta_deepl.PHP_EOL, FILE_APPEND);

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - - - - - - - - - - - - - - - - - - - - - - - - - - -'.PHP_EOL, FILE_APPEND);

        return;        
    }    

    public function enviaEmail() {        

        $cuentas = 'sergio@lafrikileria.com';
        
        $asunto = 'ERROR en proceso de TRADUCCIÓN de productos '.date("Y-m-d H:i:s");
        
        $info = [];                
        $info['{employee_name}'] = 'Usuario';
        $info['{order_date}'] = date("Y-m-d H:i:s");
        $info['{seller}'] = "";
        $info['{order_data}'] = "";
        $info['{messages}'] = '<pre>'.print_r($this->mensajes_error, true).'</pre>';
        
        @Mail::Send(
            1,
            'aviso_pedido_webservice', //plantilla
            Mail::l($asunto, 1),
            $info,
            $cuentas,
            'Usuario',
            null,
            null,
            null,
            null,
            _PS_MAIL_DIR_,
            true,
            1
        );

        exit;
    }
}

