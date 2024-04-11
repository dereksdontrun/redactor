<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');


//https://lafrikileria.com/modules/redactor/classes/ActualizaTablaTraducciones.php
//https://lafrikileria.com/test/modules/redactor/classes/ActualizaTablaTraducciones.php

//10/04/2024 Proceso que al ejecutarse buscará todos los id_product en lafrips_product, obtendrá los id_lang de los idiomas que queremos que se traduzcan con el proceso Traducciones() de la tabla lafrips_configuration y con ello buscará en lafrips_product_langs_traducciones cada id_product y se asegurará de que exista una línea para dicho id_product y cada id_lang. Si no la hay hará el insert correspondiente. Este proceso permite la carga inicial de la tabla lafrips_product_langs_traducciones antes de comenzar el proceso de traducciones. Dicha tabla recibirá un insert por cada id_lang de configuration cada vez que se cree un nuevo producto gracias al trigger trg_after_product_lang_insert_traducciones, pero este proceso se podrá volver a utilizar si decidimos añadir un nuevo idioma a los traducibles, añadiéndolo en configuration, lo que buscará de nuevo que cada id_product tenga cada id_lang en la tabla.
//como puede ser un proceso muy largo y ponemos límite de productos a procesar tenemos que saber en donde acabamos en la anterior ejecución para que la siguiente vez se busquen productos con id superior al último. Para ello la consulta de getProducts ordena por id_product ASC, por tanto buscaremos id_product mayor que el último buscado, que habremos almacenado en lafrips_configuration con name TRADUCCIONES_LAST_ID_PRODUCT_TABLA. Al terminar el proceso actualizaremos al último id_product revisado y en la siguiente ejecución se partirá de ahí. Esto hay que tenerlo en cuenta para volver a ponerlo a 0 si se añade un nuevo idioma a traducir o ignorará los producots anteriores a dicho id_product.

$a = new ActualizaTablaTraducciones();

class ActualizaTablaTraducciones 
{        

    public $id_product = null;

    public $id_lang = null;

    public $products;
  
    //array que donde guardaremos el contenido de TRADUCCIONES_ID_LANGS de la tabla lafrips_configuration, los id_lang a los que traducir los productos
    public $langs = array();
    
    //límite productos a procesar, dado que la carga inicial tiene que procesar más de 50000, con al menos 150000 inserts
    public $limite_productos = 10000;

    //variable para almacenar el último id_product procesado en la ejecución anterior de este proceso
    public $last_id_product;
    
    public $error = 0;
    public $mensajes_error = array();
    
    public function __construct()
    {	                
        echo '<br>Inicio proceso time() = '.time();

        $this->getLangs();

        $this->getLastIdProduct();

        $this->getProducts();

        $this->processProducts();

        $this->setLastIdProduct();

        echo '<br>Fin proceso time() = '.time();
        
        exit;        
    }

    public function processProducts() {
        foreach ($this->products AS $product) {
            $this->id_product = $product['id_product'];
            
            $this->checkProduct();
        }
    }
 
    public function checkProduct() {
        foreach ($this->langs AS $this->id_lang) {
            //si no existe id_product para id_lang en lafrips_product_langs_traducciones lo insertamos
            if (!$this->checkProductLang()) {
                $this->insertProductLang();
            } 
        }

        return;
    }

    public function insertProductLang() {
        $sql_insert = "INSERT INTO lafrips_product_langs_traducciones
        (id_product, id_lang, date_add)
        VALUES
        (".$this->id_product.", ".$this->id_lang.", NOW())";

        if (!Db::getInstance()->execute($sql_insert)) {
            echo '<br>Error insertando en lafrips_product_langs_traducciones combinación id_product = '.$this->id_product.' - id_lang = '.$this->id_lang;
        }

        return;
    }

    public function getProducts() {
        $sql_get_products = "SELECT id_product
        FROM lafrips_product
        WHERE id_product > ".$this->last_id_product."
        ORDER BY id_product ASC
        LIMIT ".$this->limite_productos;

        $this->products = Db::getInstance()->executeS($sql_get_products);

        if (count($this->products) < 1) {
            echo '<br>Error con productos, no encontrados en Prestashop';

            exit;
        }        

        // echo '<pre>';
        // print_r($this->products);
        // echo '</pre>';

        return;
    }    
    

    public function getLangs() {
        //primero obtenemos el contenido de la variable TRADUCCIONES_ID_LANGS en lafrips_configuration y lo transformamos a array
        $this->langs = explode(",",Configuration::get('TRADUCCIONES_ID_LANGS'));       

        // echo '<pre>';
        // print_r($langs);
        // echo '</pre>';

        //nos aseguramos de que los id_lang existan en Prestashop
        foreach ($this->langs AS $this->id_lang) {
            if (!$this->checkIdlang()) {
                echo '<br>Error con id_langs. id_lang '.$this->id_lang.' no existe en Prestashop';
    
                exit;
            }
        }

        return;        
    }

    //función que devuelve true o false si el id_lang existe o no en lafrips_lang
	public function checkIdlang()
    {        
        return (bool)Db::getInstance()->getValue("SELECT COUNT(*) FROM lafrips_lang WHERE id_lang = ".$this->id_lang);
    }

    //función que devuelve true o false si el id_product existe o no en lafrips_product_langs_traducciones para el id_lang 
	public function checkProductLang()
    {        
        return (bool)Db::getInstance()->getValue("SELECT COUNT(*) 
            FROM lafrips_product_langs_traducciones 
            WHERE id_product = ".$this->id_product."
            AND id_lang = ".$this->id_lang);
    }

    public function getLastIdProduct() {
        $this->last_id_product = (int)Configuration::get('TRADUCCIONES_LAST_ID_PRODUCT_TABLA');  

        // echo '<br><br>getLastIdProduct(): '.$this->last_id_product;

        return;
    }

    //función que actualiza el id_product del último producto revisado en este proceso antes de terminar la ejecución
    public function setLastIdProduct() {
        // echo '<br><br>setLastIdProduct(): '.$this->id_product;

        Configuration::updateValue('TRADUCCIONES_LAST_ID_PRODUCT_TABLA', $this->id_product);

        return;
    }
    
}

