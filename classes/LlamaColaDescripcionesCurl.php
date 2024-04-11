<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');

// https://lafrikileria.com/modules/redactor/classes/LlamaColaDescripcionesCurl.php
// https://lafrikileria.com/test/modules/redactor/classes/LlamaColaDescripcionesCurl.php

//20/03/2024 Por algún error que aún no encuentro, la ejecución de algunos scripts con cron jobs se interrumpe antes de que finalice o alcance su tiempo límite, pero parece que si a ese script le llamo desde un cURL no se interrumpe, es decir, el cron job llama a un script que contiene un cURL que llama al script original. Para este caso programaría un cron cada 5 minutos a https://lafrikileria.com/modules/redactor/classes/LlamaColaDescripcionesCurl.php, que es este script, donde se llama vía cURL a ColaDescripciones.php, y suele procesar todos los productos que puede.

//no parece afectar
// ini_set('max_execution_time', 3000);

$a = new LlamaColaDescripcionesCurl();

unset($a); 

class LlamaColaDescripcionesCurl {
 
  public $end_point = 'https://lafrikileria.com/modules/redactor/classes/ColaDescripciones.php';

  
  public function __construct()
    {	      
      $this->start();            
    }

    public function start() {      

      $this->launchCURL();
      
      exit;
    }
   

    public function launchCURL() {
      
      $curl = curl_init();

      curl_setopt_array($curl, array(
        CURLOPT_URL => $this->end_point,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        // CURLOPT_HTTPHEADER => array(
        //   'Cookie: PHPSESSID=bctblo7p95fdlbki40rkg038n1'
        // ),
      ));

      curl_exec($curl);

      curl_close($curl);

      return;
    }
    
}

