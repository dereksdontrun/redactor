<?php
/**
 * Gestión para aplicar descripciones creadas con la API de redacta.me
 *
 *  @author    Sergio™ <sergio@lafrikileria.com>
 *    
 */

if (!defined('_PS_VERSION_'))
    exit;

class AdminRedactorDescripcionesController extends ModuleAdminController {

    public $id_product;
    public $nombre;
    public $descripcion;
    public $tono;
    public $keywords;
    
    public function __construct() {    
        //situamos Redactame.php para acceder a sus funciones
        require_once (dirname(__FILE__) .'/../../classes/Redactame.php');   
        require_once (dirname(__FILE__) .'/../../classes/OpenAIRedactor.php');   
        require_once (dirname(__FILE__) .'/../../classes/RedactorTools.php');   

        $this->lang = false;
        $this->bootstrap = true;        
        $this->context = Context::getContext();
        
        parent::__construct();
        
    }
    
    /**
     * AdminController::init() override
     * @see AdminController::init()
     */
    public function init() {
        $this->display = 'add';
        parent::init();
    }
   
    /*
     *
     */
    public function setMedia(){
        parent::setMedia();
        $this->addJs($this->module->getPathUri().'views/js/back_redactor.js?v=1.3');
        //añadimos la dirección para el css
        $this->addCss($this->module->getPathUri().'views/css/back_redactor.css');
    }


    /**
     * AdminController::renderForm() override
     * @see AdminController::renderForm()
     */
    public function renderForm() {    

        //generamos el token de AdminRedactorDescripciones ya que lo vamos a usar en el archivo de javascript . Lo almacenaremos en un input hidden para acceder a el desde js
        $token_admin_modulo = Tools::getAdminTokenLite('AdminRedactorDescripciones');

        $this->fields_form = array(
            'legend' => array(
                'title' => 'Productos',
                'icon' => 'icon-pencil'
            ),
            'input' => array( 
                //input hidden con el token para usarlo por ajax etc
                array(  
                    'type' => 'hidden',                    
                    'name' => 'token_admin_modulo_'.$token_admin_modulo,
                    'id' => 'token_admin_modulo_'.$token_admin_modulo,
                    'required' => false,                                        
                ),                 
            ),
            
            // 'reset' => array('title' => 'Limpiar', 'icon' => 'process-icon-eraser icon-eraser'),   
            // 'submit' => array('title' => 'Guardar', 'icon' => 'process-icon-save icon-save'),            
        );

        // $this->displayInformation(
        //     'Revisar productos que actualmente se encuentran en la categoría Prepedido, vendidos o no, o revisar productos vendidos sin stock que se encuentran en pedidos en espera, con o sin categoría prepedido'
        // );
        
        return parent::renderForm();
    }

    public function postProcess() {

        parent::postProcess();

        
    }

    /*
    * Función que busca proveedores y fabricantes para llenar las select del formulario y las devuelve en formato "nombre" => "id". Es facilmente adaptable a meter categorías, tipo, etc (en módulo Ventas y proveedores)
    *
    */
    public function ajaxProcessCargaSelects(){    
        //recogemos la peticion que viene via ajax  
        $peticion = Tools::getValue('peticion',0);
                
        if (!$peticion) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Se produjo un error al cargar las select de productos.')));
        }        

        $response = true;

        if ($peticion == 'fabricante') {
            $fabricantes = Manufacturer::getManufacturers(); 

            if (!$fabricantes || empty($fabricantes)) {
                die(Tools::jsonEncode(array('error'=> true, 'message'=>'Se produjo un error al obtener los fabricantes de productos.')));
            }

            $contenido_select = [];           
            foreach($fabricantes as $key => $row){  
                $contenido_select[$row['name']] = $row['id_manufacturer'];
            }

            ksort($contenido_select);
            
                 
        } else if ($peticion == 'proveedor') {
            $proveedores = Supplier::getSuppliers(); 

            if (!$proveedores || empty($proveedores)) {
                die(Tools::jsonEncode(array('error'=> true, 'message'=>'Se produjo un error al obtener los proveedores de productos.')));
            }

            $contenido_select = [];           
            foreach($proveedores as $key => $row){  
                $contenido_select[$row['name']] = $row['id_supplier'];
            }

            ksort($contenido_select);

        } else {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Se produjo un error al cargar las select de productos.')));
        }
        

        if ($response) {
            //devolvemos la petición
            die(Tools::jsonEncode(array('contenido_select' => $contenido_select)));
        } else { 
            //error al sacar petición           
            die(Tools::jsonEncode(array('message'=>'Se produjo un error al cargar las select de productos')));
        }              
    }
  
    /*
    * Función que busca los productos para procesar descripciones
    *
    */
    public function ajaxProcessListaProductos(){        
        //comprobamos si han llegado argumentos para la búsqueda y filtros        
        $id_product = Tools::getValue('id_product',0);
        $reference = Tools::getValue('reference',0);
        $product_name = Tools::getValue('product_name',0);
        $id_supplier = Tools::getValue('id_supplier',0);
        $id_manufacturer = Tools::getValue('id_manufacturer',0);
        $activado = Tools::getValue('activado',0);
        $indexado = Tools::getValue('indexado',0);
        $redactado = Tools::getValue('redactado',0);
        $revisado = Tools::getValue('revisado',0);
        $fecha_desde = Tools::getValue('fecha_desde',0);
        $fecha_hasta = Tools::getValue('fecha_hasta',0);
        $orden = Tools::getValue('orden',0);
        $limite_productos = Tools::getValue('limite_productos',0);
        $pagina_actual = Tools::getValue('pagina_actual',0);
        $sentido_paginacion = Tools::getValue('sentido_paginacion',0);        

        //preparamos las condiciones de la select en función de lo recibido vía ajax

        if ($id_product) {
            $where_id_product = " AND pro.id_product = $id_product";
        } else {
            $where_id_product = "";
        }

        if ($reference) {
            $where_referencia = " AND pro.reference LIKE '%$reference%'";
        } else {
            $where_referencia = "";
        }

        if ($product_name) {
            $where_nombre = " AND pla.name LIKE '%$product_name%'";
        } else {
            $where_nombre = "";
        }

        if ($id_supplier) {
            $where_proveedor = " AND pro.id_supplier = $id_supplier";
        } else {
            $where_proveedor = "";
        }

        if ($id_manufacturer) {
            $where_fabricante = " AND pro.id_manufacturer = $id_manufacturer";
        } else {
            $where_fabricante = "";
        }

        if ($activado == 1) {
            $where_activado = " AND pro.active = 1";
        } elseif ($activado == 2) {
            $where_activado = " AND pro.active = 0";
        } else {
            $where_activado = "";
        }

        if ($indexado == 1) {
            $where_indexado = " AND pla.link_rewrite NOT LIKE '%_kidscrd' AND pla.link_rewrite NOT LIKE '%-noindxr'";
        } elseif ($indexado == 2) {
            $where_indexado = " AND (pla.link_rewrite LIKE '%_kidscrd' OR pla.link_rewrite LIKE '%-noindxr')";
        } else {
            $where_indexado = "";
        }

        if ($redactado == 1) { //ya ha sido generada la descripción, pero si se ha vuelto a meter en cola no mostramos
            $where_redactado = " AND red.redactado = 1 AND red.en_cola = 0";
        } elseif ($redactado == 2) { //el producto se está procesando en este momento o está marcado en cola
            $where_redactado = " AND (red.procesando = 1 OR red.en_cola = 1)";
        } elseif ($redactado == 3) { //el producto no está ni redactado, ni procesando ni en cola, puede no estar en la tabla
            $where_redactado = " AND (red.redactado = 0 OR red.redactado IS NULL) AND (red.procesando = 0 OR red.procesando IS NULL) AND (red.en_cola = 0 OR red.en_cola IS NULL)";
        } elseif ($redactado == 4) { //el producto tiene error = 1, en principio el resto estarán a 0, pero solo buscamos error
            $where_redactado = " AND red.error = 1";
        } else {
            $where_redactado = ""; //sacar todos
        } 

        if ($revisado == 1) { //ya ha sido generada la descripción y se ha revisado que esté bien
            $where_revisado = " AND red.revisado = 1";
        } elseif ($revisado == 2) { //el producto puede o no tener la descripción generada, pero no se ha marcado como revisada
            $where_revisado = " AND (red.revisado = 0 OR red.revisado IS NULL)";
        } else {
            $where_revisado = ""; //sacar todos
        } 

        if ($fecha_desde && $fecha_hasta) {
            $where_fecha = " AND pro.date_add BETWEEN  '$fecha_desde'  AND '$fecha_hasta' + INTERVAL 1 DAY ";            
        } elseif ($fecha_desde && !$fecha_hasta) {
            $where_fecha = " AND pro.date_add > '$fecha_desde' ";  
        } elseif (!$fecha_desde && $fecha_hasta) {
            $where_fecha = " AND pro.date_add < '$fecha_hasta' ";    
        } else {
            $where_fecha = ''; 
        }

        //este valor indica como ordenar el resultado de búsqueda. De momento puede ser por id_product arriba y abajo, o fecha de creación de producto arriba y abajo        
        if ($orden == 'orden_fecha_abajo') {
            $order_by = ' ORDER BY pro.date_add DESC';
        } elseif ($orden == 'orden_fecha_arriba') {
            $order_by = ' ORDER BY pro.date_add ASC';
        } elseif ($orden == 'orden_id_product_abajo') {
            $order_by = ' ORDER BY pro.id_product DESC';
        } elseif ($orden == 'orden_id_product_arriba') {
            $order_by = ' ORDER BY pro.id_product ASC';
        } else {
            $order_by = ' ORDER BY pro.date_add DESC';
        }

        //antes de sacar la lista y datos a mostrar necesitamos saber el total de productos que cumplen las condiciones de la petición sin limites ni offset, para pasarlo de vuelta como parámetro y también poder usarlo en el offset si se pulsa la flecha de mostrar la última página
        $sql_total_productos = "SELECT COUNT(pro.id_product)
        FROM lafrips_product pro
        JOIN lafrips_product_lang pla ON pro.id_product = pla.id_product AND pla.id_lang = 1
        LEFT JOIN lafrips_redactor_descripcion red ON red.id_product = pro.id_product
        WHERE 1 
        $where_id_product
        $where_referencia
        $where_nombre
        $where_proveedor
        $where_fabricante
        $where_activado
        $where_indexado
        $where_redactado 
        $where_revisado       
        $where_fecha";

        $total_productos = Db::getInstance()->getValue($sql_total_productos); 

        //según la página en que nos encontramos, el limite de productos a mostrar según el select de paginación, y si se pulsa una flecha de paginación, formamos el where de límite y offset. Actualizaremos el valor de pa´gina actual para devolverlo al front
        $limite_y_offset = '';
        $offset = 0;
        $limit = 0;        
        
        if (!$limite_productos) {
            //si $limite_productos vale 0 significa que queremos todos los productos, luego no hay paginación ni offset, se pone página actual a 1             
            $pagina_actual = 1;

        } else if (!$sentido_paginacion) {
            //si $sentido_paginacion está vacío, se muestran los productos que corresponden al límite y página actual
            $offset = $limite_productos*($pagina_actual - 1);
            $limite_y_offset = " LIMIT $limite_productos OFFSET $offset";
        } else if ($sentido_paginacion) {
            //si $sentido_paginacion contiene una petición trabajamos con ella. Puede ser una página a izquierda (pagination_left) o a derecha (pagination_right) o primera página (pagination_left_left) o última página (pagination_right_right)
            if ($sentido_paginacion == 'pagination_left') {
                //formamos la variable limite_y_offset con el limite y la página actual menos uno por pagination_left
                $offset = $limite_productos*($pagina_actual - 2);
                $limite_y_offset = " LIMIT $limite_productos OFFSET $offset";
                $pagina_actual--;

            } else if ($sentido_paginacion == 'pagination_right') {
                //formamos la variable limite_y_offset con el limite y la página actual más uno por pagination_right
                $offset = $limite_productos*$pagina_actual;
                $limite_y_offset = " LIMIT $limite_productos OFFSET $offset";
                $pagina_actual++;
                
            } else if ($sentido_paginacion == 'pagination_left_left') {
                //formamos la variable limite_y_offset con el limite, sin offset al pedir la primera página
                $offset = 0;
                $limite_y_offset = " LIMIT $limite_productos OFFSET $offset";
                $pagina_actual = 1;
                
            } else if ($sentido_paginacion == 'pagination_right_right') {
                //usamos limite, pero para el offset tenemos que calcularlo en función del total de productos. Hay que calcular cual es la última página y cuantos productos la componen teniendo en cuenta el límite. Pej, limite 10 y hay 27 productos, hay que mostrar la página 3 que tendrá 7 productos.
                //el número de páginas se saca dividiendo el total de productos entre el límite redondeando arriba
                // 27/10= 2.7 => 3
                $pagina_actual = ceil($total_productos/$limite_productos);
                //el offset será el número de productos en las páginas anteriores a la última, es decir, (pagina_actual - 1)*limite_productos
                $offset = ($pagina_actual - 1)*$limite_productos;
                $limite_y_offset = " LIMIT $limite_productos OFFSET $offset";                
            }
            
        }

        //obtenemos el token de AdminCatalog para crear el enlace al producto en backoffice
        $id_employee = Context::getContext()->employee->id;
        $tab = 'AdminProducts';
        $token_adminproducts = Tools::getAdminToken($tab . (int) Tab::getIdFromClassName($tab) . (int) $id_employee);
        
        $url_base = Tools::getHttpHost(true).__PS_BASE_URI__;
        // index.php?controller=AdminProducts&id_product=45631&updateproduct&token=1f1d270097f1d42ecc5dd1c6600dc3ac
        $url_product_back = $url_base.'lfadminia/index.php?controller=AdminProducts&updateproduct&token='.$token_adminproducts.'&id_product=';  

        $sql_productos = "SELECT pro.id_product AS id_product, pro.reference AS reference, pla.name AS name, pro.id_supplier AS id_supplier, sup.name AS supplier,
        pro.id_manufacturer AS id_manufacturer, man.name AS manufacturer, pro.active AS activo,
        CASE
        WHEN pla.link_rewrite LIKE '%_kidscrd' OR pla.link_rewrite LIKE '%-noindxr' THEN 0
        ELSE 1
        END AS indexado,
        pro.date_add AS date_add,
        CASE
        WHEN red.error = 1 THEN 3
        WHEN red.redactado = 1 AND red.en_cola = 0 THEN 1        
        WHEN red.procesando = 1 OR red.en_cola = 1 THEN 2
        ELSE 0
        END AS redactado,
        IFNULL(red.revisado, 0) AS revisado,
        IFNULL(CONCAT( '$url_base', ima.id_image, '-home_default/', pla.link_rewrite, '.jpg'), CONCAT('$url_base', 'img/logo_producto_medium_default.jpg')) AS url_imagen,
        CONCAT( '$url_product_back', pro.id_product) AS url_producto,
        red.api AS api_seleccionada
        FROM lafrips_product pro
        JOIN lafrips_product_lang pla ON pro.id_product = pla.id_product AND pla.id_lang = 1
        LEFT JOIN lafrips_supplier sup ON sup.id_supplier = pro.id_supplier
        LEFT JOIN lafrips_manufacturer man ON man.id_manufacturer = pro.id_manufacturer
        LEFT JOIN lafrips_redactor_descripcion red ON red.id_product = pro.id_product
        LEFT JOIN lafrips_image ima ON ima.id_product = pro.id_product AND ima.cover = 1   
        WHERE 1
        $where_id_product
        $where_referencia
        $where_nombre
        $where_proveedor
        $where_fabricante
        $where_activado
        $where_indexado
        $where_redactado
        $where_revisado
        $where_fecha
        $order_by
        $limite_y_offset";

        // die(Tools::jsonEncode(array('error'=> true, 'message'=>$sql_productos)));

        if ($productos = Db::getInstance()->executeS($sql_productos)) {
            // foreach ($productos AS &$producto) {  
            //     //sacamos imagen de producto
            //     $product = new Product((int)$producto['id_product'], false, 1, 1);
            //     $image = Image::getCover((int)$producto['id_product']);			
            //     $image_link = new Link;//because getImageLInk is not static function
            //     $image_path = $image_link->getImageLink($product->link_rewrite, $image['id_image'], 'home_default');

            //     $producto['image_path'] = $image_path;
            // }

            die(Tools::jsonEncode(array('message'=>'Lista de productos obtenida correctamente', 'info_productos' => $productos, 'total_productos' => $total_productos, 'pagina_actual' => $pagina_actual)));

        } else { 
            //error al sacar los productos           
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'No hay productos o Error obteniendo lista de productos', 'total_productos' => $total_productos)));
        }             
    }

    //función que añade a cola de redactor los productos recibidos
    public function ajaxProcessMasColaProductos(){
        $array_id_product = Tools::getValue('productos',0);               

        if (empty($array_id_product)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error recibiendo los productos a añadir a cola de redacción')));
        }

        $api_seleccionada = Tools::getValue('api_seleccionada');               

        if (empty($api_seleccionada)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error, no seleccionada API de redacción al añadir a cola de redacción')));
        }

        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }

        $array_ok = array();

        foreach ($array_id_product AS $id_product) {
            //comprobamos si el producto ya está en la tabla, en cuyo caso haremos un update. Lo buscamos y si está comprobamos si ya está en cola.
            if ($id_redactor_descripcion = Db::getInstance()->getValue("SELECT id_redactor_descripcion FROM lafrips_redactor_descripcion WHERE id_product = $id_product")) {
                $sql_update_producto_cola = "UPDATE lafrips_redactor_descripcion
                SET
                api = '$api_seleccionada',
                en_cola = 1,
                date_metido_cola = NOW(),
                id_employee_metido_cola = $id_employee,
                error = 0,
                date_upd = NOW()
                WHERE id_redactor_descripcion = $id_redactor_descripcion";  

                if (Db::getInstance()->Execute($sql_update_producto_cola)) {
                    $array_ok[] = $id_product;
                }

            } else {
                $sql_insert_producto_cola = "INSERT INTO lafrips_redactor_descripcion
                (id_product, api, en_cola, date_metido_cola, id_employee_metido_cola, date_add) 
                VALUES 
                ($id_product, '$api_seleccionada', 1, NOW(), $id_employee, NOW())";

                if (Db::getInstance()->Execute($sql_insert_producto_cola)) {
                    $array_ok[] = $id_product;
                }
            }
        }

        if (count($array_ok) != count($array_id_product)) {
            $warning = 'Algunos productos no pudieron añadirse a la cola';
        } else {
            $warning = '';
        }

        if (count($array_ok) > 1) {            
            die(Tools::jsonEncode(array(
                'message'=>'Productos añadidos a cola correctamente', 
                'warning' => $warning,
                'productos_cola' => $array_ok
            )));
        } elseif (count($array_ok) == 1) {            
            die(Tools::jsonEncode(array(
                'message'=>'Producto añadido a cola correctamente', 
                'warning' => $warning,
                'productos_cola' => $array_ok
            )));
        } else {
            die(Tools::jsonEncode(array(
                'error'=> true, 
                'message'=>'Error procesando cola de redacción'
            )));
        }        
    }

    //función que elimina de la cola de redactor los productos recibidos. Por ahora solo permito de uno en uno con el botón de cola. 
    public function ajaxProcessMenosColaProductos(){
        $id_product = Tools::getValue('producto',0);               

        if (empty($id_product)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error recibiendo el producto a eliminar de cola de redacción')));
        }

        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }        

        
        //primero comprobamos que no este en proceso
        if (Db::getInstance()->getValue("SELECT id_product FROM lafrips_redactor_descripcion WHERE procesando = 1 AND id_product = $id_product")) {
            die(Tools::jsonEncode(array(
                'error'=> true, 
                'message'=>'El producto seleccionado está siendo procesado en este momento, no puede eliminarse de la cola'
            )));
        } else {
            //30/12/2024 al quitar de cola eliminamos la api seleccionada también
            $sql_update_producto_cola = "UPDATE lafrips_redactor_descripcion
            SET
            api = '',
            en_cola = 0,
            date_eliminado_cola = NOW(),
            id_employee_eliminado_cola = $id_employee,
            date_upd = NOW()
            WHERE id_product = $id_product";            

            if (Db::getInstance()->Execute($sql_update_producto_cola)) {
                //comprobamos si ya estaba redactado para saber que mensaje poner
                $redactado = Db::getInstance()->getValue("SELECT redactado FROM lafrips_redactor_descripcion WHERE id_product = $id_product");

                die(Tools::jsonEncode(array(
                    'message'=>'Productos eliminado de la cola correctamente',                     
                    'id_producto_cola' => $id_product,
                    'redactado' => $redactado
                )));
            } else {
                die(Tools::jsonEncode(array(
                    'error'=> true, 
                    'message'=>'Error eliminando el producto de la cola de redacción'
                )));
            }
        }
          
    }

    //función que recibe un id_product y devuelve toda la información concerniente a las descripciones, además de foto, referencia etc, para mostrar en el recuadro lateral de la tabla. 
    //Tendrá un input descripción que contendrá la descripción actual del producto. Otro input que contendrá la info que se va a pasar por defecto a la API de redacta.me. Esta info será el contenido de la descripción si el producto no figura como redactado = 1, ya que si ha sido redactado, la descripción será o bien la que devolvió la api o algo similar. Si es así, en el input se pondrá el contenido que se utilizó para que la API generara la descripción y que estará guardado en api_json en la tabla redactor_descripcion. Si no ha sido redactado, es posible que el producto ya tenga una descripción completa, en cuyo caso mostraremos un mensaje de aviso o algo así, si esta tiene más de 500 caracteres que es el máximo que se puede pasar a la api.
    //18/09/2024 redacta.me ha ampliado el límite de caracteres de la descripción de 500 a 5000
    //al recibir en esta función primero hay que comprobar que la tabla lafrips_redactor_descripcion ya tenga una entrada para el producto cuyo botón se ha pulsado, para insertarla si es que no    
    public function ajaxProcessMostrarProducto() {
        $id_product = Tools::getValue('id_product',0);               

        if (empty($id_product)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error mostrando datos de producto')));
        }

        //llamamos a checkTablaRedactor() para asegurarnos de que el producto a mostrar ya existe en lafrips_redactor_descripcion y si no es así lo insertaremos        
        if (!$this->checkTablaRedactor($id_product)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error insertando producto en tabla de Redactor')));
        }

        //obtenemos el token de AdminCatalog para crear el enlace al producto en backoffice
        $id_employee = Context::getContext()->employee->id;
        $tab = 'AdminProducts';
        $token_adminproducts = Tools::getAdminToken($tab . (int) Tab::getIdFromClassName($tab) . (int) $id_employee);
        
        $url_base = Tools::getHttpHost(true).__PS_BASE_URI__;
        // index.php?controller=AdminProducts&id_product=45631&updateproduct&token=1f1d270097f1d42ecc5dd1c6600dc3ac
        $url_product_back = $url_base.'lfadminia/index.php?controller=AdminProducts&updateproduct&token='.$token_adminproducts.'&id_product=';  

        $sql_producto = "SELECT pro.id_product AS id_product, pro.reference AS reference, pla.name AS name, pro.id_supplier AS id_supplier, sup.name AS supplier,
        pro.id_manufacturer AS id_manufacturer, man.name AS manufacturer, 
        pla.description_short AS descripcion, CHAR_LENGTH(pla.description_short) AS longitud_descripcion,
        CASE
        WHEN pla.link_rewrite LIKE '%_kidscrd' OR pla.link_rewrite LIKE '%-noindxr' THEN 0
        ELSE 1
        END AS indexado,
        DATE_FORMAT(pro.date_add,'%d-%m-%Y %H:%i:%S') AS date_creado,
        IFNULL(red.redactado, 0) AS redactado, 
        IFNULL(red.en_cola, 0) AS en_cola, 
        IFNULL(red.revisado, 0) AS revisado, 
        IFNULL(red.procesando, 0) AS procesando,
        IFNULL(red.error, 0) AS en_error,
        IFNULL(DATE_FORMAT(red.date_error,'%d-%m-%Y %H:%i:%S'), 'No disponible') AS date_error,
        IFNULL(red.error_message, 'No disponible') AS error_message,
        IFNULL(DATE_FORMAT(red.inicio_proceso,'%d-%m-%Y %H:%i:%S'), 'No disponible') AS date_inicio_proceso, 
		IFNULL(DATE_FORMAT(red.date_metido_cola,'%d-%m-%Y %H:%i:%S'), 'No disponible') AS date_metido_cola, 
        IFNULL(DATE_FORMAT(red.date_redactado,'%d-%m-%Y %H:%i:%S'), 'No disponible') AS date_redactado, 
        IFNULL(DATE_FORMAT(red.date_revisado,'%d-%m-%Y %H:%i:%S'), 'No disponible') AS date_revisado, 
        IFNULL((SELECT CONCAT(firstname,' ',lastname) FROM lafrips_employee WHERE id_employee = red.id_employee_metido_cola), 'No disponible') AS employee_metido_cola, 
        IFNULL((SELECT CONCAT(firstname,' ',lastname) FROM lafrips_employee WHERE id_employee = red.id_employee_redactado), 'No disponible') AS employee_redactado, 
        IFNULL((SELECT CONCAT(firstname,' ',lastname) FROM lafrips_employee WHERE id_employee = red.id_employee_revisado), 'No disponible') AS employee_revisado,   
        red.api_json AS api_json,        
        IFNULL(CONCAT( '$url_base', ima.id_image, '-home_default/', pla.link_rewrite, '.jpg'), CONCAT('$url_base', 'img/logo_producto_medium_default.jpg')) AS url_imagen,
        CONCAT( '$url_product_back', pro.id_product) AS url_producto,
        pro.active AS activo,
        red.api AS api_seleccionada, red.redactado_api AS redactado_api
        FROM lafrips_product pro
        JOIN lafrips_product_lang pla ON pro.id_product = pla.id_product AND pla.id_lang = 1
        LEFT JOIN lafrips_image ima ON ima.id_product = pro.id_product AND ima.cover = 1   
        LEFT JOIN lafrips_supplier sup ON sup.id_supplier = pro.id_supplier
        LEFT JOIN lafrips_manufacturer man ON man.id_manufacturer = pro.id_manufacturer
        LEFT JOIN lafrips_redactor_descripcion red ON red.id_product = pro.id_product        
        WHERE pro.id_product = $id_product";

        // die(Tools::jsonEncode(array('error'=> true, 'message'=>$sql_productos)));

        if ($producto = Db::getInstance()->getRow($sql_producto)) {
            //comprobamos si hay datos en api_json para mostrar en front la info que se pasó a la api en un proceso previo almacenado
            if ($producto['api_json']) {
                $producto['info_api'] = json_decode($producto['api_json']);
            } else {
                $producto['info_api'] = 0;
            }

            //pequeña ñapa rápida para que si el nombre del empleado es Automatizador Automatizador solo lo ponga una vez
            if ($producto['employee_redactado'] == "Automatizador Automatizador") {
                $producto['employee_redactado'] = "Automatizador";
            }

            die(Tools::jsonEncode(array(
                'message'=>'Información de producto',                     
                'info_producto' => $producto,                
            )));

        } else {
            die(Tools::jsonEncode(array(
                'error'=> true, 
                'message'=>'Error obteniendo la información detallada del producto'
            )));
        }
    }

    //función que comprueba si un producto ya está en la tabla lafrips_redactor_descripcion y si no lo inserta, para evitar que si se pulsa en Proecsar sin haber metido el producto antes en cola no pueda hacer updates dado que no existe en la tabla
    public function checkTablaRedactor($id_product) {
        //comprobamos si el producto ya está en la tabla, en cuyo caso no haremos nada
        if (!Db::getInstance()->getValue("SELECT id_redactor_descripcion FROM lafrips_redactor_descripcion WHERE id_product = $id_product")) {
            $sql_insert_tabla_redactor = "INSERT INTO lafrips_redactor_descripcion
            (id_product, date_add) 
            VALUES 
            ($id_product, NOW())";

            if (Db::getInstance()->Execute($sql_insert_tabla_redactor)) {
                return true;
            } else {
                return false;
            }
        }

        return true;
    }

    //función que recibe un id_product y una descripción y nombre y llama a Redactame.php para actualizar product_name y description_short del producto para id_lang 1. También marcará Revisado a 1, En cola a 0, etc desde allí.
    //30/12/2024 Al añadir otra api, openai para generar descripciones, cuando revisamos un producto queremos poner con cual fue redactado. La api seleccionada para generar la descripción se guarda en 'api' cuando se mete en cola o se pulsa generar desde el controlador, de modo que al revisar copiaremos en redactado_api lo que haya en api. Si hubieramos revisado el producto desde el controlador sin antes generar la descripción, es decir, modificar lo que hubiera, en 'api' no habría nada y redactado_api quedaría vacío, lo que sería correcto. O podría ser un producto redactado en cola y revisado después desde el controlador, con lo que tenemos que recoger de api la api seleccionada.
    public function ajaxProcessRevisarDescripcion() {
        $id_product = Tools::getValue('id_product', false);  
        $nombre = Tools::getValue('nombre', false);  
        $descripcion = Tools::getValue('descripcion', false);   
        //08/03/2024 Recogemos valor de input hidden id redactado_hidden_id_product que indica si la descripción que llega se acaba de generar en el front, o sino sería o bien generada en cola o lo que hubiera en el producto de prestashop
        $redactado = (int) Tools::getValue('redactado_ahora', false);            

        if (empty($id_product) || empty($nombre) || empty($descripcion)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error con la información del producto a revisar')));
        }

        if (!Validate::isCleanHtml($nombre) || !Validate::isCleanHtml($descripcion)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error, los campos a guardar contienen elementos inválidos')));
        }

        if (($retorno_actualiza_producto = RedactorTools::actualizaProducto($id_product, $descripcion, $nombre)) === true) {

            //marcar revisado. Para marcar redactado, la descripción debe haber sido solicitada a la API desde el front también
            if ($redactado) {
                RedactorTools::updateTablaRedactorRedactado(1, $id_product);        
            }

            RedactorTools::updateTablaRedactorRevisado($id_product);            

            die(Tools::jsonEncode(array(
                'message'=>'Producto marcado como revisado, descripción y nombre actualizados',
            )));

        } else {

            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error actualizando los campos a guardar - '.$retorno_actualiza_producto)));
        }        
    }

    //función que recibe los datos para enviar a la API y llama a la clase Redactame para hacer la petición. Actualizará Encola, procesando, etc si es necesario
    //30/12/2024 metermos en 'api' de tabla redactor la api seleccionada en el controlador
    //como ahora hay dos apis para generar descipciones, llamaremos a cada función dependiendo de la api seleccionada en el controlador
    public function ajaxProcessGenerarDescripcion() {
        $api_seleccionada = Tools::getValue('api_seleccionada');  

        if (empty($api_seleccionada)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error con la información de API seleccionada para redacción')));
        }

        $this->id_product = Tools::getValue('id_product',0);  
        $this->nombre = Tools::getValue('nombre',0);
        $this->descripcion = Tools::getValue('descripcion',0);         

        if (!Validate::isCleanHtml($this->nombre) || !Validate::isCleanHtml($this->descripcion)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error, los campos a procesar contienen elementos inválidos')));
        }

        if ($api_seleccionada == 'redactame') {
            $this->keywords = Tools::getValue('keywords',0);  
            $this->tono = Tools::getValue('tono',0);

            if (empty($this->id_product) || empty($this->nombre) || empty($this->descripcion) || empty($this->tono)) {
                die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error con la información para enviar a la API')));
            }

            $this->generarDescripcionRedactame();
        } elseif ($api_seleccionada == 'openai') {
            if (empty($this->id_product) || empty($this->nombre) || empty($this->descripcion)) {
                die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error con la información para enviar a la API')));
            }

            $this->generarDescripcionOpenAI();
        }
    }

    public function generarDescripcionRedactame() {        

        $info_api = array(
            "id_product" => $this->id_product,
            "title" => $this->nombre,
            "description" => $this->descripcion,
            "keywords" => $this->keywords,
            "tone" => $this->tono
        );                

        $resultado_api = Redactame::apiRedactameSolicitudDescripcion($info_api);

        if ($resultado_api["result"] == 1) {
            //si desde el front hemos solicitado una descripción a la API, reseteamos redactado, y de paso se pondrá procesando a 0 también. Después tendrán que pulsar sobre revisado para que veulva a estar como redactado.
            RedactorTools::updateTablaRedactorRedactado(0, $this->id_product);  

            die(Tools::jsonEncode(array(
                'message'=>'Descripción generada correctamente, revisala antes de salir para guardarla',
                'descripcion_api' => $resultado_api["message"]
            )));
        }

        die(Tools::jsonEncode(array(
            'error' => true,
            'message'=>'Error generando la descripción',
            'error_message' => $resultado_api["message"]
        )));
    }

    public function generarDescripcionOpenAI() {
        $info_api = array(
            "id_product" => $this->id_product,
            "title" => $this->nombre,
            "description" => $this->descripcion
        );                
        
        $resultado_api = OpenAIRedactor::apiOpenAISolicitudDescripcion($info_api);

        if ($resultado_api["result"] == 1) {
            //si desde el front hemos solicitado una descripción a la API, reseteamos redactado, y de paso se pondrá procesando a 0 también. Después tendrán que pulsar sobre revisado para que vuelva a estar como redactado.
            RedactorTools::updateTablaRedactorRedactado(0, $this->id_product);  

            //si ha devuelvto titulo lo añadimos
            if (isset($resultado_api['titulo'])) {
                $titulo_producto = $resultado_api['titulo'];
            } else {
                $titulo_producto = 0;
            }

            die(Tools::jsonEncode(array(
                'message'=>'Descripción generada correctamente, revisala antes de salir para guardarla',
                'descripcion_api' => $resultado_api["message"],
                'titulo_producto' => $titulo_producto 
            )));
        }

        die(Tools::jsonEncode(array(
            'error' => true,
            'message'=>'Error generando la descripción',
            'error_message' => $resultado_api["message"]
        )));
    }

    //función que activa un producto en Prestashop
    public function ajaxProcessActivarProducto(){
        $id_product = Tools::getValue('id_product',0);               

        if (empty($id_product)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error recibiendo el producto a activar')));
        }

        $product = new Product($id_product);
        
        $product->active = true;
        
        if ($product->update()) {
            //lanzamos esta función para que se "repase" el stock, de modo que el hook de importaproveedor mirará si este producto está en frik_import_catalogos con disponibilidad de stock y lo pondrá en permitir pedidos si no tiene stock, pero a comienzos de 2024 ya solo tenemos automatizados pocos proveedores de import_catalogos (Heo, Abysse y SD, reduciendo)
            StockAvailable::synchronize($id_product);

            die(Tools::jsonEncode(array(
                'message'=>'Producto activado correctamente'                 
            )));
        } else {
            die(Tools::jsonEncode(array(
                'error'=> true, 
                'message'=>'Error activando producto'
            )));
        }     
    }

    //17/04/2024 función que añade a cola de traducción el producto recibido. OJO, lo mete para todos los idiomas en la tabla y resetea las traducciones, es decir, marca 0 los campos a traducir y completo
    public function ajaxProcessMasColaTraducciones(){
        $id_product = Tools::getValue('id_product',0);   

        // die(Tools::jsonEncode(array(                
        //     'message'=>'prueba'
        // )));
        
        if (!$id_product) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error recibiendo el producto a añadir a cola de traducción')));
        }

        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }

        $message = "Añadido a cola manualmente por ".Context::getContext()->employee->firstname;

        $sql_update_cola_traducciones = "UPDATE lafrips_product_langs_traducciones
        SET
        `name` = 0,
        `description` = 0,
        description_short = 0, 
        meta_description = 0,
        meta_title = 0,
        completo = 0,
        en_cola = 1, 
        id_employee_metido_cola = $id_employee,
        date_metido_cola = NOW(),
        error_message = CONCAT(error_message, ' | $message - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),
        date_upd = NOW()
        WHERE id_product = $id_product";

        if (Db::getInstance()->Execute($sql_update_cola_traducciones)) {
            die(Tools::jsonEncode(array(                
                'message'=>'Producto añadido a cola de traducción'
            )));
        } else {
            die(Tools::jsonEncode(array(
                'error'=> true, 
                'message'=>'Error añadiendo producto a cola de traducción'
            )));
        }    
    }

}
