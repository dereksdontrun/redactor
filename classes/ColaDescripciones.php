<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');
require_once(dirname(__FILE__).'/Redactame.php');

//https://lafrikileria.com/modules/redactor/classes/ColaDescripciones.php
//https://lafrikileria.com/test/modules/redactor/classes/ColaDescripciones.php

//24/05/2023 Proceso programado que revisa la tabla lafrips_redactor_descripcion buscando productos marcados para la cola de redacción, obtiene sus datos y envía petición a la API de Redacta.me para recibir una nueva descripción y guardarla en el producto.

//Los productos tardan un tiempo variable en obtener la descripción de la API de modo que el proceso debe tener un límite de tiempo. Sacaremos el max_execution_time de PHP que tenemos configurado en el servidor y cuando se termine cada producto lo comprobaremos. Si se ha alcanzado el 90% de ese tiempo o terminado la lista de productos, pararemos el proceso. Cada vez que se finaliza un producto haremos una media de tiempo tardado para calcular si el tiempo que queda hasta el 90% o 95% es suficiente para otro antes de sacar otro de la tabla. 

//Los productos deben marcarse con procesando = 1 y la hora de inicio de proceso al entrar en el proceso. Esos valores se pasarán a 0 al terminar. De ese modo al iniciar el proceso se busca el primer producto con en_cola = 1 y procesando = 0.

//habrá un proceso de comprobación o coche escoba al comenzar la ejecución. Se sacará si hay algún producto con procesando = 1 y se calculará el tiempo que lleva. Si este es superior a x lo pasaremos a procesando = 0 y habrá que revisarlo.

$a = new ColaDescripciones();

class ColaDescripciones
{
    //almacenamos max_execution_time para saber cuando parar si hemos puesto un limit muy alto. El valor que almacenamos es el 90% de la variable php, de modo que si lo superamos no continuaremos con más productos
    public $my_max_execution_time;
    //momento de inicio del script, en segundos, para comparar con max_execution_time
    public $inicio;

    //directorio para log
    // public $directorio_log = _PS_ROOT_DIR_.'/modules/redactor/log/';
    public $log_file = _PS_ROOT_DIR_.'/modules/redactor/log/descripciones.txt';
    public $error = 0;

    public $contador_productos = 0;
    public $contador_correctos = 0;

    public $descripcion_api;
    public $info_api = array();

    public function __construct() {
        $this->inicio = time();
        $this->my_max_execution_time = ini_get('max_execution_time')*0.9; //90% de max_execution_time   
 
        $this->start();
    }

    public function start() {
        $exit = 0;

        do {
            $this->info_api = null;
            $resultado_api = null;
            $this->descripcion_api = null;
            
            $get_info = $this->getProductInfo();

            if ($get_info === true) {

                $this->contador_productos++;

                if ($this->contador_productos == 1) {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' --------------------------------------------------'.PHP_EOL, FILE_APPEND);
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso Cola Descripciones'.PHP_EOL, FILE_APPEND);
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tiempo máximo ejecución - my_max_execution_time = '.$this->my_max_execution_time.PHP_EOL, FILE_APPEND);
                }

                //info de producto obtenida, enviamos a la API mediante la clase Redactame.php
                $resultado_api = Redactame::apiRedactameSolicitudDescripcion($this->info_api);

                if ($resultado_api["result"] == 1) {
                    //tenemos una descripción, supuestamente correcta, o al menos la API no dió error. La tabla ya ha sido actualizada en Redactame.php
                    $this->descripcion_api = $resultado_api["message"];

                    $this->contador_correctos++;

                    if ($this->guardaResultado()) {
                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Descripción generada y guardada para id_product '.$this->info_api["id_product"].PHP_EOL, FILE_APPEND);
                    } else {
                        //marcamos error y quitamos redactado
                        $sql_error = "UPDATE lafrips_redactor_descripcion
                        SET
                        redactado = 0,
                        id_employee_redactado = 0, 
                        date_redactado = '0000-00-00 00:00:00',
                        error = 1, 
                        date_upd = NOW()
                        WHERE id_product = ".$this->info_api["id_product"];

                        Db::getInstance()->executeS($sql_error); 

                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error: Descripción generada NO guardada para id_product '.$this->info_api["id_product"].PHP_EOL, FILE_APPEND);
                    }
                    
                } else {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error generando descripción para id_product '.$this->info_api["id_product"].PHP_EOL, FILE_APPEND);
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Mensaje: '.$resultado_api["message"].PHP_EOL, FILE_APPEND);
                }

            } elseif ($get_info === false) {
                //no hay productos en cola en la tabla, interrumpimos el proceso, salimos del do - while
                break;
            }
            
            if ((time() - $this->inicio) >= $this->my_max_execution_time) {
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
            $sql_info_producto = "SELECT SUBSTRING(name, 1, 50) AS nombre, SUBSTRING(description_short, 1, 500) AS descripcion 
            FROM lafrips_product_lang WHERE id_lang = 1 AND id_product = $id_product";

            $info_producto = Db::getInstance()->getRow($sql_info_producto);

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
            $this->info_api = array(
                "id_product" => $id_product,
                "title" => $info_producto['nombre'],
                "description" => $info_producto['descripcion'],
                "keywords" => "",
                "tone" => "Professional"
            );

            //marcamos la tabla redactor_descripcion como procesando
            if (!$id_employee = Context::getContext()->employee->id) {
                $id_employee = 44;
            }
            //insertamos fecha y empleado de redactar en lafrips_redactor_descripcion
            $sql_redactando = "UPDATE lafrips_redactor_descripcion
            SET                
            procesando = 1,
            inicio_proceso = NOW(),
            id_employee_redactado = $id_employee,                            
            date_upd = NOW()
            WHERE id_product = $id_product";

            Db::getInstance()->executeS($sql_redactando); 

            return true;

        } else {
            return false;
        }
    }

    public function guardaResultado() {
        $id_product = $this->info_api["id_product"];
        $descripcion = $this->descripcion_api;
        
        $sql_guarda_descripcion = "UPDATE lafrips_product_lang
        SET                
        description_short = '$descripcion'
        WHERE id_lang = 1
        AND id_product = $id_product";

        return Db::getInstance()->executeS($sql_guarda_descripcion); 
    }

}

?>