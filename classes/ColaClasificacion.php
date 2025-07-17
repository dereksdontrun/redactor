<?php
// https://lafrikileria.com/modules/redactor/classes/ColaClasificacion.php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');
require_once(dirname(__FILE__).'/OpenAIClasificador.php');
require_once(dirname(__FILE__).'/ClasificadorCategoriaManager.php');
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

class ColaClasificacion
{
    private $inicio;
    private $max_execution_time;
    private $manager;
    private $log_file = _PS_ROOT_DIR_.'/modules/redactor/log/clasificador.txt';

    private $logger;

    public function __construct()
    {
        $this->inicio = time();

        //establecemos el tiempo máximo de ejecución en el más bajo de 90% de max_execution_time o 290 segundos, es decir, serán 280 segundos, ya que la tarea cron de la cola se lanzará cada 5 minutos
        $max_time_ini = ini_get('max_execution_time');
        $this->max_execution_time = ($max_time_ini && $max_time_ini > 0) ? min($max_time_ini * 0.9, 280) : 280;

        $this->manager = new ClasificadorCategoriaManager();

        $this->logger = new LoggerFrik($this->log_file);

        //para llevarnos $logger a ClasificadorCategoriaManager:
        $this->manager->setLogger($this->logger);
        //para llevarnos $logger a OpenAIClasificador:
        OpenAIClasificador::setLogger($this->logger);

        $this->logger->log("-----     -----     -----     -----     -----", 'INFO');
        $this->logger->log("Iniciado proceso de clasificación de productos", 'INFO');

        // Reset de productos estancados más de x minutos
        $this->manager->resetProcesandoAntiguos(); 

        $this->start();
    }

    private function start()
    {
        do {
            $producto = $this->manager->obtenerProductoEnCola();

            if ($producto === false) {
                $this->logger->log("No hay productos pendientes en cola", 'INFO');
                break;
            }

            if (isset($producto['skip']) && $producto['skip']) {

                $this->logger->log("Producto {$producto['id_product']} omitido por posible doble procesamiento", 'WARNING');
                
                continue;
            }

            $id_product = $producto['id_product'];            

            try {
                $this->manager->clasificarProducto($id_product, $this->logger);

                // $this->logger->log("Producto $id_product clasificado correctamente", 'SUCESS');
            } catch (Exception $e) {
                $error_msg = $e->getMessage();
                $this->manager->marcarError($id_product, $error_msg);
                $this->logger->log("Error en producto $id_product: $error_msg", 'ERROR');
            }

        } while ((time() - $this->inicio) < $this->max_execution_time);

        $duracion = time() - $this->inicio;
        $this->logger->log("Proceso finalizado tras $duracion segundos", 'INFO');
    }    
}

new ColaClasificacion();
