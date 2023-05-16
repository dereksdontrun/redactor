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
    
    public function __construct() {       

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
        $this->addJs($this->module->getPathUri().'views/js/back_redactor.js');
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

    //función que devuelve al front los proveedores existentes de productos para formar el SELECT en el controlador
    public function ajaxProcessObtenerProveedores() {       

        $info_proveedores = array();

        foreach ($proveedores_dropshipping AS $id_supplier) {
            $proveedor = array();
            $proveedor['id_supplier'] = $id_supplier;
            $proveedor['name'] = Supplier::getNameById($id_supplier);

            $info_proveedores[] = $proveedor;
        }

        if ($info_proveedores) {
            //ordenamos el array por el campo name
            $columnas = array_column($info_proveedores, 'name');
            array_multisort($columnas, SORT_ASC, $info_proveedores);

            //devolvemos la lista 
            die(Tools::jsonEncode(array('message'=>'Info de proveedores obtenida correctamente', 'info_proveedores' => $info_proveedores)));
        } else { 
            //error al sacar los proveedores           
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error obteniendo la información de los proveedores')));
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
        pro.id_manufacturer AS id_manufacturer, man.name AS manufacturer, 
        CASE
        WHEN pla.link_rewrite LIKE '%_kidscrd' OR pla.link_rewrite LIKE '%-noindxr' THEN 0
        ELSE 1
        END AS indexado,
        pro.date_add AS date_add,
        CASE
        WHEN red.redactado = 1 AND red.en_cola = 0 THEN 1        
        WHEN red.procesando = 1 OR red.en_cola = 1 THEN 2
        ELSE 0
        END AS redactado,
        IFNULL(red.revisado, 0) AS revisado,
        IFNULL(CONCAT( '$url_base', ima.id_image, '-home_default/', pla.link_rewrite, '.jpg'), CONCAT('$url_base', 'img/logo_producto_medium_default.jpg')) AS url_imagen,
        CONCAT( '$url_product_back', pro.id_product) AS url_producto
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

        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }

        $array_ok = array();

        foreach ($array_id_product AS $id_product) {
            //comprobamos si el produco ya está en la tabla, en cuyo caso haremos un update
            if ($id_redactor_descripcion = Db::getInstance()->getValue("SELECT id_redactor_descripcion FROM lafrips_redactor_descripcion WHERE procesando = 0 AND en_cola = 0 AND id_product = $id_product")) {
                $sql_update_producto_cola = "UPDATE lafrips_redactor_descripcion
                SET
                en_cola = 1,
                date_metido_cola = NOW(),
                id_employee_metido_cola = $id_employee,
                date_upd = NOW()
                WHERE id_redactor_descripcion = $id_redactor_descripcion";  

                if (Db::getInstance()->Execute($sql_update_producto_cola)) {
                    $array_ok[] = $id_product;
                }

            } else {
                $sql_insert_producto_cola = "INSERT INTO lafrips_redactor_descripcion
                (id_product, en_cola, date_metido_cola, id_employee_metido_cola, date_add) 
                VALUES 
                ($id_product, 1, NOW(), $id_employee, NOW())";

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
            $sql_update_producto_cola = "UPDATE lafrips_redactor_descripcion
            SET
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
    public function ajaxProcessMostrarProducto() {
        $id_product = Tools::getValue('id_product',0);               

        if (empty($id_product)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error mostrando datos de producto')));
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
        IFNULL(DATE_FORMAT(red.inicio_proceso,'%d-%m-%Y %H:%i:%S'), 'No disponible') AS date_inicio_proceso, 
		IFNULL(DATE_FORMAT(red.date_metido_cola,'%d-%m-%Y %H:%i:%S'), 'No disponible') AS date_metido_cola, 
        IFNULL(DATE_FORMAT(red.date_redactado,'%d-%m-%Y %H:%i:%S'), 'No disponible') AS date_redactado, 
        IFNULL(DATE_FORMAT(red.date_revisado,'%d-%m-%Y %H:%i:%S'), 'No disponible') AS date_revisado, 
        IFNULL((SELECT CONCAT(firstname,' ',lastname) FROM lafrips_employee WHERE id_employee = red.id_employee_metido_cola), 'No disponible') AS employee_metido_cola, 
        IFNULL((SELECT CONCAT(firstname,' ',lastname) FROM lafrips_employee WHERE id_employee = red.id_employee_redactado), 'No disponible') AS employee_redactado, 
        IFNULL((SELECT CONCAT(firstname,' ',lastname) FROM lafrips_employee WHERE id_employee = red.id_employee_revisado), 'No disponible') AS employee_revisado,   
        red.api_json AS api_json,        
        IFNULL(CONCAT( '$url_base', ima.id_image, '-home_default/', pla.link_rewrite, '.jpg'), CONCAT('$url_base', 'img/logo_producto_medium_default.jpg')) AS url_imagen,
        CONCAT( '$url_product_back', pro.id_product) AS url_producto
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

    //función que recibe un id_product y una descripción y nombre y actualiza product_name y description_short del producto para id_lang 1. También marcará Revisado a 1, En cola a 0, etc
    public function ajaxProcessRevisarDescripcion() {
        $id_product = Tools::getValue('id_product',0);  
        $nombre = Tools::getValue('nombre',0);  
        $descripcion = Tools::getValue('descripcion',0);               

        if (empty($id_product) || empty($nombre) || empty($descripcion)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error con la información del producto a revisar')));
        }

        if (!Validate::isCleanHtml($nombre) || !Validate::isCleanHtml($descripcion)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error, los campos a guardar contienen elementos inválidos')));
        }

        //instanciamos el producto para actualizar nombre y descripción, solo para id_lang 1
        $product = new Product($id_product);
        // para nombre se hace así, y para descripciones como abajo, cuando solo queremos afectar a un lenguaje
        $product->name[1] = $nombre;
        $product->description_short = array( 1=> $descripcion);
        if ($product->update()) {
            //marcamos como redactado y revisado, quitamos de cola
            $id_employee = Context::getContext()->employee->id;

            $sql_update_producto = "UPDATE lafrips_redactor_descripcion
            SET
            en_cola = 0,
            redactado = 1, 
            revisado = 1,
            date_revisado = NOW(),
            id_employee_revisado = $id_employee,
            date_upd = NOW()
            WHERE id_product = $id_product";            

            Db::getInstance()->Execute($sql_update_producto);

            die(Tools::jsonEncode(array(
                'message'=>'Producto marcado como revisado, descripción y nombre actualizados',
            )));

        } else {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error actualizando los campos a guardar')));
        }        
    }

    //función que recibe los datos para enviar a la API y llama a la clase Redactame para hacer la petición. Actualizará Encola, procesando, etc si es necesario
    public function ajaxProcessGenerarDescripcion() {
        $id_product = Tools::getValue('id_product',0);  
        $nombre = Tools::getValue('nombre',0);  
        $keywords = Tools::getValue('keywords',0);  
        $tono = Tools::getValue('tono',0);  
        $descripcion = Tools::getValue('descripcion',0);               

        if (empty($id_product) || empty($nombre) || empty($descripcion) || empty($tono)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error con la información para enviar a la API')));
        }

        if (!Validate::isCleanHtml($nombre) || !Validate::isCleanHtml($descripcion) || !Validate::isCleanHtml($keywords)) {
            die(Tools::jsonEncode(array('error'=> true, 'message'=>'Error, los campos a procesar contienen elementos inválidos')));
        }


        $descripcion_api = $nombre.$descripcion;
        die(Tools::jsonEncode(array(
            'message'=>'Descripción generada correctamente, revisala antes de salir para guardarla',
            'descripcion_api' => $descripcion_api
        )));


    }

}
