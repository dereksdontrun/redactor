/**
* 2007-2023 PrestaShop
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
*  @copyright 2007-2023 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*
* Don't forget to prefix your containers with your own identifier
* to avoid any conflicts with others containers.
*/

//para mostrar el botón de scroll arriba, aparecerá cuando se haga scroll abajo y desaparecerá al volver arriba
$(window).scroll(function(){    
    if ($(this).scrollTop() > 400) {
      $('#boton_scroll').fadeIn();
    } else {
      $('#boton_scroll').fadeOut();
    }
});

document.addEventListener('DOMContentLoaded', start);

function start() {
    //quitamos cosas del panel header que pone Prestashop por defecto, para que haya más espacio. 
    document.querySelector('h2.page-title').remove(); 
    document.querySelector('div.page-bar.toolbarBox').remove(); 
    document.querySelector('div.page-head').style.height = '36px';  
    
    //el panel que contiene el formulario, etc donde aparecerá el contenido lo hacemos relative y colocamos para que aparezca inicialmente bajo el panel superior, poniendo top -80px ¿?
    const panel_contenidos = document.querySelector('div#content div.row'); 
    panel_contenidos.style.position = 'relative';
    panel_contenidos.style.top = '-80px';    

    //al div con id fieldset_0 que viene a contener todo esto, le añadimos clase clearfix para que la tabla etc quede siempre dentro
    const panel_fieldset_0 = document.querySelector('div#fieldset_0'); 
    panel_fieldset_0.classList.add('clearfix');    

    //vamos a añadir un panel lateral para visualizar la descripción de un producto concreto, llamado div_descripciones, lo creamos y ponemos adjunto antes que la tabla, de modo que se desplaza a la derecha al poner el panel de tabla
    //div para mostrar las descripciones
    const div_descripciones = document.createElement('div');
    div_descripciones.classList.add('clearfix','col-lg-4');
    div_descripciones.id = 'div_descripciones';
    document.querySelector('div.panel-heading').insertAdjacentElement('afterend', div_descripciones);

    //generamos la tabla "vacia" para los productos resultados de las consultas
    //utilizamos el mismo formato de prestashop para mostrar los productos, con tabla responsiva etc.
    //div contenedor de la tabla
    const div_tabla = document.createElement('div');
    div_tabla.classList.add('table-responsive-row','clearfix','col-lg-8');
    div_tabla.id = 'div_tabla';
    document.querySelector('div.panel-heading').insertAdjacentElement('afterend', div_tabla);    

    //generamos tabla
    const tabla = document.createElement('table');
    tabla.classList.add('table');
    tabla.id = 'tabla';
    document.querySelector('#div_tabla').appendChild(tabla);    

    //generamos head de tabla
    const thead = document.createElement('thead');
    thead.id = 'thead';
    thead.innerHTML = `
        <tr class="nodrag nodrop" id="tr_campos_tabla">
            <th class="fixed-width-xs row-selector text-center">
                <input class="noborder" type="checkbox" name="selecciona_todos_productos" id="selecciona_todos_productos">
            </th>            
            <th class="fixed-width-sm center">
                <span class="title_box active">ID
                    <a id="orden_id_product_abajo" class="filtro_orden orden_activo"><i class="icon-caret-down"></i></a>
                    <a id="orden_id_product_arriba" class="filtro_orden"><i class="icon-caret-up"></i></a>
                </span>
            </th>    
            <th class="fixed-width-md text-center">
                <span class="title_box">Referencia
                </span>
            </th>
            <th class="fixed-width-xl text-center">
                <span class="title_box">Nombre
                </span>
            </th>        
            <th class="fixed-width-md text-center">
                <span class="title_box">Proveedor
                </span>
            </th>
            <th class="fixed-width-md text-center">
                <span class="title_box">Fabricante
                </span>
            </th>
            <th class="fixed-width-xs text-center">
                <span class="title_box">Indexado
                </span>
            </th>    
            <th class="fixed-width-xs text-center">
                <span class="title_box">Redactado
                </span>
            </th>   
            <th class="fixed-width-xs text-center">
                <span class="title_box">Revisado
                </span>
            </th>                              
            <th class="fixed-width-sm text-center">
                <span class="title_box">Fecha creación
                    <a id="orden_fecha_abajo" class="filtro_orden"><i class="icon-caret-down"></i></a>
                    <a id="orden_fecha_arriba" class="filtro_orden"><i class="icon-caret-up"></i></a>
                </span>
            </th>            
            <th colspan="3" class="fixed-width-md text-center"></th>            
        </tr>
        <tr class="nodrag nodrop filter row_hover">
            <th class="text-center">--</th>
            <th class="text-center"><input type="text" class="filter" id="filtro_id_product" value=""></th> 
            <th class="text-center"><input type="text" class="filter" id="filtro_referencia" value=""></th>
            <th class="text-center"><input type="text" class="filter" id="filtro_nombre" value=""></th>
            <th class="text-center">
                <select class="filter center"  name="filtro_proveedor" id="filtro_proveedor">                                                                                       
                </select>
            </th> 
            <th class="text-center">
                <select class="filter center"  name="filtro_fabricante" id="filtro_fabricante">                                                                                       
                </select>
            </th>           
            <th class="text-center">
                <select class="filter center" name="filtro_indexado"  id="filtro_indexado">
                    <option value="0">-</option>
                    <option value="1">Si</option>  
                    <option value="2" selected="selected">No</option>                                                                    
                </select>
            </th>  
            <th class="text-center">
                <select class="filter center" name="filtro_redactado"  id="filtro_redactado">
                    <option value="0" selected="selected">-</option>
                    <option value="1">Si</option>  
                    <option value="2">En cola</option>   
                    <option value="3">No</option>                                                                 
                </select>
            </th>
            <th class="text-center">
                <select class="filter center" name="filtro_revisado"  id="filtro_revisado">
                    <option value="0" selected="selected">-</option>
                    <option value="1">Si</option>  
                    <option value="2">No</option>                                                                    
                </select>
            </th>            
            <th class="text-right">
				<div class="row">
                    <div class="input-group fixed-width-md center">
                        <input class="input_date" id="filtro_desde" type="text" placeholder="Desde" name="creacion_desde" value="" min="1997-01-01" max="2030-12-31" onfocus="(this.type='date')" onblur="if(this.value==''){this.type='text'}"> 
                    </div>
                    <div class="input-group fixed-width-md center">
                        <input class="input_date" id="filtro_hasta" type="text" placeholder="Hasta" name="creacion_hasta" value="" min="1997-01-01" max="2030-12-31" onfocus="(this.type='date')" onblur="if(this.value==''){this.type='text'}">                        
                    </div>										
                </div>
			</th>                        
            <th class="text-left" colspan="3">
                <div class="row">
                    <div class="text-center col-md-6 col-lg-6 col-sm-6">
                        <select class="filter center" name="filtro_limite_productos"  id="filtro_limite_productos">
                            <option value="20" selected="selected">20</option>
                            <option value="50">50</option>  
                            <option value="100">100</option>   
                            <option value="500">500</option>   
                            <option value="0">Todos</option>
                        </select>	
                    </div>
                    <div class="col-md-6 col-lg-6 col-sm-6">
                        / <span id="total_productos">-</span>
                    </div>													
                </div>
                <div class="row">
                    <div class="text-center">
                        <ul class="pagination center">
                            <li>
                                <a id="pagination_left_left" class="flechas_paginacion">
                                    <i class="icon-double-angle-left"></i>
                                </a>
                            </li>
                            <li>
                                <a id="pagination_left" class="flechas_paginacion">
                                    <i class="icon-angle-left"></i>
                                </a>
                            </li>
                            <li>
                                <a id="page_number" class="deshabilita_paginador">
                                    <span id="numero_pagina">1</span>
                                </a>
                            </li>	
                            <li>
                                <a id="pagination_right" class="flechas_paginacion">
                                    <i class="icon-angle-right"></i>
                                </a>
                            </li>
                            <li>
                                <a id="pagination_right_right" class="flechas_paginacion">
                                    <i class="icon-double-angle-right"></i>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </th>            
        </tr>
        `; 
    document.querySelector('#tabla').appendChild(thead);

    //los inputs de fecha en lugar de type date, que obliga a placeholder tipo dd-mm-yyyy los pongo type text, que permite cambiar placeholder, y añadimos un onfocus que lo cambia a type date y un onblur, que si su value es '' lo devuelve a type text al salir el ratón  
    
    //añadimos un botón en el panel heading, llevado a la izquierda, para Añadir a cola los productos seleccionados.
    var boton_cola = `        
        <div class="btn-group pull-left">
            <button type="button" name="meter_cola"  id="meter_cola" class="btn btn-success" title="Añadir a cola los productos seleccionados" disabled>
                <i class="icon-folder"></i>
                Añadir a cola
            </button>                  
        </div>        
    `;     
    
    document.querySelector('.panel-heading').innerHTML = boton_cola;   

    //Cargamos los SELECTs de Proveedores y fabricantes. Enviamos el id del select de modo que formamos #"id" concatenando en la respuesta ajax.
    datosSelect('fabricante');
    datosSelect('proveedor');    

    //añado eventListener al check para seleccionar deseleccionar todos los productos mostrados al mismo tiempo. El botón de añadir a cola estará enabled solo si hay algún check marcado y disabled si no. Se comprobará cada check, si está disabled (se acaba de añadir a lista un producto, por ejemplo) no se marcará
    var checkbox_todos_productos = document.querySelector("#selecciona_todos_productos");

    checkbox_todos_productos.addEventListener('change', function() {        
        var checkboxes = document.querySelectorAll(".checks_linea_producto");

        if (this.checked) {
            // console.log("Checkbox is checked..");
            //se marcan todos los checks de producto
            checkboxes.forEach( item => {
                if (item.disabled == false) {
                    item.checked = true;
                }                
            });
            //se hace botón enable
            document.querySelector('#meter_cola').disabled = false;
        } else {
            // console.log("Checkbox is not checked..");
            //se desmarcan todos los checks de producto
            checkboxes.forEach( item => {
                item.checked = false;
            });
            //se hace botón disabled
            document.querySelector('#meter_cola').disabled = true;
        }
    });

    //añadimos eventlistener a las flechas de ordenar (id de producto, fehcas.. que tienen clase común filtro_orden)
    //Para "recordar" la seleccionada se le añade al <a> pulsado la calse "orden_activo" y se elimina de donde esté. Por defecto lo tendrá id_order de mayor a menor, es decir, id="orden_id_abajo", de modo que se utilizará como otro parámetro para la llamada a controlador, sacando que flecha esta "marcada"
    const flechas_ordenar = document.querySelectorAll('.filtro_orden');

    flechas_ordenar.forEach( item => {
        item.addEventListener('click', function (e) {   
            //si modificamos el orden de los productos por página no estando en la página 1 debemos resetear el número de página, de modo que se lanzará la búsqueda a partir de página 1
            if (document.querySelector('#numero_pagina').innerHTML != 1) {
                document.querySelector('#numero_pagina').innerHTML = 1;
            }  
            //llamamos a buscarOrdenado, que recogerá lo que tengamos en select e inputs para llamar al controlador.
            buscarOrdenado(e);        
        })
    });

    //añadimos eventlistener a las flechas de paginación (siguiente/anterior página, última/primera página). 
    const flechas_paginacion = document.querySelectorAll('.flechas_paginacion');

    flechas_paginacion.forEach( item => {
        item.addEventListener('click', buscarOrdenado); 
    });

    //añadimos event listener para el select de paginación, para cuando se cambia
    document.querySelector('#filtro_limite_productos').addEventListener('change', function (e) {   
        //si modificamos el número de productos por página no estando en la página 1 debemos resetear el número de página, de modo que se lanzará la búsqueda a partir de página 1
        if (document.querySelector('#numero_pagina').innerHTML != 1) {
            document.querySelector('#numero_pagina').innerHTML = 1;
        }  
        //llamamos a buscarOrdenado, que recogerá lo que tengamos en select e inputs.
        buscarOrdenado(e);        
    });

    //añadimos event listener para el input de id_product, para cuando se escriba y se pulse Enter
    document.querySelector('#filtro_id_product').addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            //llamamos a buscarOrdenado, que recogerá lo que hayamos introducido en el input. Esto entra como valor flechaid, pero no importa porque el controlador luego desecha el contenido
            buscarOrdenado(e);
        }
    });

    //añadimos event listener para el input de referencia de producto, para cuando se escriba y se pulse Enter
    document.querySelector('#filtro_referencia').addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            //llamamos a buscarOrdenado, que recogerá lo que hayamos introducido en el input. Esto entra como valor flechaid, pero no importa porque el controlador luego desecha el contenido
            buscarOrdenado(e);
        }
    });

    //añadimos event listener para el input de nombre de producto, para cuando se escriba y se pulse Enter
    document.querySelector('#filtro_nombre').addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            //llamamos a buscarOrdenado, que recogerá lo que hayamos introducido en el input. Esto entra como valor flechaid, pero no importa porque el controlador luego desecha el contenido
            buscarOrdenado(e);
        }
    });

    //añadimos event listener para el select de proveedor, para cuando se cambia
    document.querySelector('#filtro_proveedor').addEventListener('change', function (e) {        
        //llamamos a buscarOrdenado, que recogerá lo que tengamos en select e inputs.
        buscarOrdenado(e);        
    });

    //añadimos event listener para el select de fabricante, para cuando se cambia
    document.querySelector('#filtro_fabricante').addEventListener('change', function (e) {        
        //llamamos a buscarOrdenado, que recogerá lo que tengamos en select e inputs.
        buscarOrdenado(e);        
    });

    //añadimos event listener para el select de indexado, para cuando se cambia
    document.querySelector('#filtro_indexado').addEventListener('change', function (e) {        
        //llamamos a buscarOrdenado, que recogerá lo que tengamos en select e inputs.
        buscarOrdenado(e);        
    });

    //añadimos event listener para el select de Redactado, para cuando se cambia
    document.querySelector('#filtro_redactado').addEventListener('change', function (e) {        
        //llamamos a buscarOrdenado, que recogerá lo que tengamos en select e inputs.
        buscarOrdenado(e);        
    });

    //añadimos event listener para el select de Revisado, para cuando se cambia
    document.querySelector('#filtro_revisado').addEventListener('change', function (e) {        
        //llamamos a buscarOrdenado, que recogerá lo que tengamos en select e inputs.
        buscarOrdenado(e);        
    });

    //generamos el botón para subir hasta arriba haciendo scroll
    const boton_scroll = document.createElement('div');    
    boton_scroll.id = "boton_scroll";
    boton_scroll.innerHTML =  `<i class="icon-arrow-up"></i>`;

    boton_scroll.addEventListener('click', scrollArriba);

    //lo append al panel, y con css lo haremos fixed
    div_descripciones.appendChild(boton_scroll);

    //eventlistener para botón de Añadir a cola AQUÍ NO FUNCIONA ¿?
    // const boton_meter_cola_bulk = document.querySelector('#meter_cola');
    // boton_meter_cola_bulk.addEventListener('click', meterColaBulk);

    //finalmente,trás cargar la tabla vacía, filtros etc, obtenemos los productos para mostrarlos al cargar la página inicialmente
    obtenerProductos();
}

//FUNCIONES
//función para subir cuando se pulsa el botón de scroll arriba
function scrollArriba() {
    $('html, body').animate({scrollTop : 0},1000);    
}

//función que recibe un parámetro que indica el select a generar, pidiendo los datos por ajax al controlador
function datosSelect(peticion) {
    var dataObj = {};
    dataObj['peticion'] = peticion;
    
    //el token lo hemos sacado arriba del input hidden
    $.ajax({
        url: 'index.php?controller=AdminRedactorDescripciones' + '&token=' + token + "&action=carga_selects" + '&ajax=1' + '&rand=' + new Date().getTime(),
        type: 'POST',
        data: dataObj,
        cache: false,
        dataType: 'json',
        success: function (data, textStatus, jqXHR)
        
        {
            if (typeof data.error === 'undefined')
            {                
                //recibimos via ajax un array con los datos para el select correspondiente
                // console.dir(data.contenido_select); 
                const contenido_select = data.contenido_select;
                // console.dir(contenido_select);

                //dependiendo de peticion se escoge el id por su select y se rellena con lo que viene via ajax.
                const select = document.querySelector('#filtro_'+peticion);
                
                //vaciamos los select
                select.innerHTML = '';    

                var options_select = '<option value="0" selected> -- </option>'; //permitimos un valor nulo, que si es seleccionado no aplica filtro    
                // var options_select = '';
                
                Object.entries(contenido_select).forEach(entry => {
                    const [key, value] = entry;
                    options_select += '<option value="'+value+'">'+key+'</option>';
                });
                
                // console.log(options_select);
                select.innerHTML = options_select;                           
                
            }
            else
            {      
                showErrorMessage(data.message);
            }

        },
        error: function (jqXHR, textStatus, errorThrown)
        {
            showErrorMessage('ERRORS: ' + textStatus);
        }
    });  //fin ajax

}

//función que hace la llamada a obtenerProductos() asignando el filtro de ordenar correspondiente a la flecha pulsada, o input rellenado. Recoge todos los valores presentes en inputs o flechas de orden etc, para enviarlos como parámetros a la función obtenerProductos()
function buscarOrdenado(e) { 
    //obtenemos el id de la flecha pulsada que define como se ordenará la búsqueda. Si la llamada procede de pulsar el Enter en un input o utilizar un select, no importa ya que en el controlador, si el valor no es flecha tal arriba o abajo, ignora lo que ponga, y el value del input o select se recoge aquí., sacamos el contenido de los inputs y select de abajo si tienen algo y lo enviamos todo como parámetro a la función obtenerProductos() que hará la llamada ajax
    //Para "recordar" la flecha de orden seleccionada si se ha pulsado alguna se le añade al <a> pulsado la clase "orden_activo" y se elimina de donde esté. Por defecto al cargar el módulo lo tendrá id_product de mayor a menor, es decir, id="orden_id_abajo", de modo que se utilizará como otro parámetro para la llamada a controlador, sacando que flecha esta "marcada". Para esto comprobamos aquí si lo que ha disparado la petición de los pedidos contiene la clase "filtro_orden" que son las flechas de id y fecha. Si la contiene comprobamos la clase "orden_activo", si no es una flecha de orden, no hacemos nada
    //para la paginación, comprobamos si se ha llegado aquí por la pulsación de una flecha de paginación con la clase flechas_paginación. Si es así asignamos a la variable paginacion el id de la flecha pulsada para enviar al controlador, si no  va vacio

    var paginacion = '';

    if (e.currentTarget.classList.contains('filtro_orden')) {
        // console.log('contiene filtro_orden'); 
        //el elemento es una flecha de orden (id_product o fecha creación). Rotamos por todos los elementos de clase filtro_orden eliminando la clase "orden_activo" y después le ponemos a este elemento dicha clase
        
        document.querySelectorAll('.filtro_orden').forEach( item => {
            item.classList.remove('orden_activo'); 
        });
        
        //añadimos la clase al elemento actual
        e.currentTarget.classList.add('orden_activo');

    } else if (e.currentTarget.classList.contains('flechas_paginacion')) {
        // console.log('contiene flechas_paginacion='+e.currentTarget.id); 
        //se ha pulsado una flecha de paginación, su id indica left o left_left o right o right_right
        paginacion = e.currentTarget.id;        
    }

    //buscamos que flecha tiene la clase "orden_activo" para enviar el orden como parámetro (en caso de que la llamada a pedidos no se haya realizado con una flecha de orden, así obtenemos el orden que se ha pedido antes o el por defecto)   
    const flecha_orden = document.querySelector('.orden_activo').id;

    //recogemos lo que haya en inputs etc, que pueden estar vacíos o no tener nada seleccionado.
    const busqueda_id_product = document.querySelector('#filtro_id_product').value;  
    const busqueda_referencia = document.querySelector('#filtro_referencia').value;
    const busqueda_nombre = document.querySelector('#filtro_nombre').value;  
    const busqueda_proveedor = document.querySelector('#filtro_proveedor').value;  
    const busqueda_fabricante = document.querySelector('#filtro_fabricante').value;  
    const busqueda_indexado = document.querySelector('#filtro_indexado').value; 
    const busqueda_redactado = document.querySelector('#filtro_redactado').value; 
    const busqueda_revisado = document.querySelector('#filtro_revisado').value; 
    const busqueda_fecha_desde = document.querySelector('#filtro_desde').value; 
    const busqueda_fecha_hasta = document.querySelector('#filtro_hasta').value; 
    const busqueda_limite_productos = document.querySelector('#filtro_limite_productos').value; 
    //obtenemos el valor de número de página del paginador
    const numero_pagina = document.querySelector('#numero_pagina').innerHTML; 

    // console.log('flechaid='+flechaId); 
    // console.log(busqueda_id);
    // console.log(busqueda_proveedor);  
    // console.log(busqueda_estado);
    // console.log(busqueda_fecha_desde);
    // console.log(busqueda_fecha_hasta);
    console.log('numero_pagina='+numero_pagina); 
        
    obtenerProductos(busqueda_id_product, busqueda_referencia, busqueda_nombre, busqueda_proveedor, busqueda_fabricante, busqueda_indexado, busqueda_redactado, busqueda_revisado, busqueda_fecha_desde, busqueda_fecha_hasta, flecha_orden, busqueda_limite_productos, numero_pagina, paginacion);
    
}

//función que llama al controlador y pide los productos en función de los filtros y parámetros de búsqueda marcados. Se llama a esta función desde la función buscarOrdenado() si se cambia algún filtro u orden, pero para la carga inicial recibe unos parámetros por defecto.
function obtenerProductos(id_product = "", referencia = "", nombre = "", proveedor = 0, fabricante = 0, indexado = 2, redactado = 0, revisado = 0, fecha_desde = "", fecha_hasta = "", orden = "", limite_productos = 20, numero_pagina = 1, paginacion = "") {
    console.log(arguments);
    //ante cualquier búsqueda, si hay algo en el panel lateral limpiamos    
    if (document.contains(document.querySelector('#div_descripciones'))) {
        document.querySelector('#div_descripciones').remove();
    } 
    
    //mostramos spinner
    spinnerOn();
     
    //preparamos la llamada ajax
    var dataObj = {};

    dataObj['id_product'] = id_product;
    dataObj['reference'] = referencia;    
    dataObj['product_name'] = nombre;  
    dataObj['id_supplier'] = proveedor;
    dataObj['id_manufacturer'] = fabricante;
    dataObj['indexado'] = indexado;
    dataObj['redactado'] = redactado;
    dataObj['revisado'] = revisado;
    dataObj['fecha_desde'] = fecha_desde;  
    dataObj['fecha_hasta'] = fecha_hasta;    
    dataObj['orden'] = orden;
    dataObj['limite_productos'] = limite_productos;
    dataObj['pagina_actual'] = numero_pagina;
    dataObj['sentido_paginacion'] = paginacion;

    console.dir(dataObj);
    
    $.ajax({
        url: 'index.php?controller=AdminRedactorDescripciones' + '&token=' + token + "&action=lista_productos" + '&ajax=1' + '&rand=' + new Date().getTime(),
        type: 'POST',
        data: dataObj,
        cache: false,
        dataType: 'json',
        success: function (data, textStatus, jqXHR)
        
        {
            if (typeof data.error === 'undefined')
            {                
                //recibimos via ajax en data.info_pedidos la información de los pedidos
                // console.log('data.info_pedidos = '+data.info_pedidos);
                console.dir(data);    
                
                //nos aseguramos de que el check de todos productos no esté checado y el botón de añadir a cola este disabled
                document.querySelector("#selecciona_todos_productos").checked = false;
                document.querySelector('#meter_cola').disabled = true;

                //con los datos, llamamos a la función que nos los mostrará
                muestraListaProductos(data.info_productos, data.total_productos, data.pagina_actual); 

                //eliminamos spinner
                spinnerOff();

            }
            else
            {       
                //limpiamos tabla
                if (document.contains(document.querySelector('#tbody'))) {
                    document.querySelector('#tbody').remove();
                }

                //nos aseguramos de que el check de todos productos no esté checado y el botón de añadir a cola esté disbled
                document.querySelector("#selecciona_todos_productos").checked = false;
                document.querySelector('#meter_cola').disabled = true;

                //hacemos que las flechas de paginación no se puedan pulsar
                document.querySelectorAll('.flechas_paginacion').forEach( item => {
                    if (!item.classList.contains('deshabilita_paginador')) {
                        item.classList.add('deshabilita_paginador'); 
                    }
                });

                //añadimos al texto de panel-heading el número de productos totales que corresponden a los filtros que en este caso deberían ser 0
                if (document.contains(document.querySelector('#num_productos'))) {
                    document.querySelector('#num_productos').remove();
                } 
            
                document.querySelector('.panel-heading').innerHTML = document.querySelector('.panel-heading').innerHTML + '<span id="num_productos"><i class="icon-pencil"></i> PRODUCTOS - ' + data.total_productos + ' </span>';  

                document.querySelector('#total_productos').innerHTML = data.total_productos;

                //ponemos el número de página en que estamos en el paginador
                document.querySelector('#numero_pagina').innerHTML = 1;

                //eliminamos spinner
                spinnerOff();

                showErrorMessage(data.message);
            }

        },
        error: function (jqXHR, textStatus, errorThrown)
        {
            showErrorMessage('ERRORS: ' + textStatus);
        }
    });  //fin ajax
}

function muestraListaProductos(productos, total_productos, pagina_actual) {
    //primero limpiamos el tbody
    if (document.contains(document.querySelector('#tbody'))) {
        document.querySelector('#tbody').remove();
    } 
    //generamos el tbody con los productos obtenidos, que insertaremos tras el thead
    const tbody = document.createElement('tbody');
    tbody.id = 'tbody';
    var num_productos = 0;
    //por cada producto, generamos un tr que hacemos appenchild a tbody
    productos.forEach(
        producto => {
            num_productos++;     
            var indexado = '';
            var redactado = '';     
            var revisado = '';  
            var badge_redactado = '';
            var badge_revisado = '';         

            //ponemos badge en redactado y en revisado. Redactado tendrá badge 'info' si aún no se ha hecho nada, 'warning' si está en lista o procesando y success si ya está redactado. Revisado tendrá 'info' si el producto aún no ha sido redactado, danger si ha sido redactado pero no revisado, y success cuando está redactado y revisado.     
            if (producto.indexado == 1) {
                indexado = 'Si';
            } else {
                indexado = 'No';
            } 
            
            var check_disabled = "";
            if (producto.redactado == 1) {
                redactado = 'Si';
                badge_redactado = 'success';
            } else if (producto.redactado == 2) {
                check_disabled = " disabled";
                redactado = 'En cola';
                badge_redactado = 'warning';
            } else {
                redactado = 'No';
                badge_redactado = 'info';
            } 

            //en función de si el botón está en cola o no mostraremos el botón +Cola o -Cola para añadir o quitar de la cola. Un producto ya redactado se puede volver a añadir a cola si se quiere
            if (producto.redactado == 2) {
                var boton_cola = `
                    <button class="btn btn-default menos_cola_producto" type="button" title="Eliminar producto de cola" id="menos_cola_${producto.id_product}" name="${producto.id_product}">
                        <i class="icon-minus"></i> Cola
                    </button> 
                `;
            } else {
                var boton_cola = `
                    <button class="btn btn-default mas_cola_producto" type="button" title="Añadir producto a cola" id="mas_cola_${producto.id_product}" name="${producto.id_product}">
                        <i class="icon-plus"></i> Cola
                    </button> 
                `;
            }

            if (producto.revisado == 1) {
                revisado = 'Si';
                badge_revisado = 'success'; 
            } else if ((producto.revisado == 0) && ((producto.redactado == 0) || (producto.redactado == 2))) {
                revisado = 'No';
                badge_revisado = 'info'; 
            } else if ((producto.revisado == 0) && (producto.redactado == 1)) {
                revisado = 'No';
                badge_revisado = 'danger'; 
            }

            var tr_producto = document.createElement('tr');
            tr_producto.id = 'tr_'+producto.id_product;
            tr_producto.innerHTML = `
                <td class="row-selector text-center">
                    <input class="noborder checks_linea_producto" type="checkbox" id="product_checkbox_${producto.id_product}"  name="product_checkbox_${producto.id_product}" value="${producto.id_product}" ${check_disabled}>
                </td>
                <td class="fixed-width-sm center">
                    ${producto.id_product} 
                </td>
                <td class="fixed-width-md center">
                    ${producto.reference}
                </td>
                <td class="fixed-width-xl center">
                    ${producto.name} 
                </td>
                <td class="fixed-width-md center">
                    ${producto.supplier}
                </td>
                <td class="fixed-width-md center">
                    ${producto.manufacturer}
                </td>
                <td class="fixed-width-xs center">
                    ${indexado}
                </td>
                <td class="fixed-width-xs center" id="redactado_${producto.id_product}">
                    <span class="badge badge-${badge_redactado}">${redactado}</span>
                </td>
                <td class="fixed-width-xs center" id="revisado_${producto.id_product}">
                    <span class="badge badge-${badge_revisado}">${revisado}</span>
                </td>                
                <td class="fixed-width-sm center">
                    ${producto.date_add} 
                </td>                    
                <td class="text-right"> 
                    <div class="btn-group pull-right">
                        <a href="${producto.url_producto}" target="_blank" class="btn btn-default" title="Ir a producto">
                            <i class="icon-search-plus"></i> Ir
                        </a>    
                    </div> 
                </td>
                <td class="text-right"> 
                    <div class="btn-group pull-right" id="boton_cola_${producto.id_product}">
                        ${boton_cola}                          
                    </div> 
                </td>
                <td class="text-right"> 
                    <div class="btn-group pull-right">
                        <button class="btn btn-default procesa_producto" type="button" title="Procesar descripción de producto" id="procesar_${producto.id_product}" name="procesar_${producto.id_product}">
                            <i class="icon-wrench"></i> Procesar
                        </button>    
                    </div>           
                </td>
            `;

            tbody.appendChild(tr_producto);

        }     
    ) 

    //añadimos al texto de panel-heading el número de pedidos totales que corresponden a los filtros, independientemente de los que se muestran por la paginación. 
    //nos aseguramos de que no haya un span anterior con el número de productos 
    if (document.contains(document.querySelector('#num_productos'))) {
        document.querySelector('#num_productos').remove();
    } 

    document.querySelector('.panel-heading').innerHTML = document.querySelector('.panel-heading').innerHTML + '<span id="num_productos"><i class="icon-pencil"></i> PRODUCTOS - ' + total_productos + ' </span>';    

    document.querySelector('#total_productos').innerHTML = total_productos;

    //ponemos el número de página en que estamos en el paginador
    document.querySelector('#numero_pagina').innerHTML = pagina_actual;

    //en función de los datos obtenidos, la página actual y la posibilidad de mostrar otras páginas, añadimos o quitamos la clase "deshabilita_paginador" a las flechas de paginación que toque. Pej, si estamos en primera página no podemos pulsar a izquierda, pero si además no hubiera más que una página tampoco podríamos a la derecha. Primero obtenemos el límite por página.
    var limite_pagina = document.querySelector('#filtro_limite_productos').value; 
    // console.log('limite_pagina='+limite_pagina);
    // console.log('total_productos='+total_productos);
    // console.log('pagina_actual='+pagina_actual);
    
    //por alguna razón tengo que forzar con parseInt() ya que si limite_pagina es 100 no valida la comparación
    if (limite_pagina == 0 || (parseInt(limite_pagina) > parseInt(total_productos))) {
        // console.log('if 1');
        //si límite es 0 es que se muestran todos, no debe pulsarse ninguna flecha. Tampoco si limite es mayor que el total de productos
        document.querySelectorAll('.flechas_paginacion').forEach( item => {
            if (!item.classList.contains('deshabilita_paginador')) {
                item.classList.add('deshabilita_paginador'); 
            }
        });
    } else if (pagina_actual == 1) {
        // console.log('if 2');
        //si estamos en la primera página no se debe pulsar izquierda, y nos aseguramos de que se pueda derecha
        if (!document.querySelector('#pagination_left_left').classList.contains('deshabilita_paginador')) {
            document.querySelector('#pagination_left_left').classList.add('deshabilita_paginador'); 
        }
        if (!document.querySelector('#pagination_left').classList.contains('deshabilita_paginador')) {
            document.querySelector('#pagination_left').classList.add('deshabilita_paginador'); 
        }

        if (document.querySelector('#pagination_right_right').classList.contains('deshabilita_paginador')) {
            document.querySelector('#pagination_right_right').classList.remove('deshabilita_paginador'); 
        }
        if (document.querySelector('#pagination_right').classList.contains('deshabilita_paginador')) {
            document.querySelector('#pagination_right').classList.remove('deshabilita_paginador'); 
        }     
        
    } else if (pagina_actual == Math.ceil(total_productos/limite_pagina)) {
        // console.log('if 3');
        //si la página actual es la última, no se debe pulsar derecha y aseguramos que se pueda izquierda
        //la página final se calcula dividiendo el total de productos entre el límite redondeando arriba
        // 27/10= 2.7 => 3 Usamos Math.ceil(total_productos/limite_pagina)
        if (document.querySelector('#pagination_left_left').classList.contains('deshabilita_paginador')) {
            document.querySelector('#pagination_left_left').classList.remove('deshabilita_paginador'); 
        }
        if (document.querySelector('#pagination_left').classList.contains('deshabilita_paginador')) {
            document.querySelector('#pagination_left').classList.remove('deshabilita_paginador'); 
        }

        if (!document.querySelector('#pagination_right_right').classList.contains('deshabilita_paginador')) {
            document.querySelector('#pagination_right_right').classList.add('deshabilita_paginador'); 
        }
        if (!document.querySelector('#pagination_right').classList.contains('deshabilita_paginador')) {
            document.querySelector('#pagination_right').classList.add('deshabilita_paginador'); 
        }    

    } else {
        // console.log('if 4');
        //si no se da ninguno de los casos anteriores permitmos pulsar todas
        document.querySelectorAll('.flechas_paginacion').forEach( item => {
            if (item.classList.contains('deshabilita_paginador')) {
                item.classList.remove('deshabilita_paginador'); 
            }
        });
    }

    
    //ponemos tbody en la tabla
    document.querySelector('#tabla').appendChild(tbody);

    //añadimos event listener para cada botón de Procesar, que llamará a la función para mostrar la descripción del producto y poder generar una independiente
    const botones_procesa_producto = document.querySelectorAll('.procesa_producto');

    botones_procesa_producto.forEach( item => {
        item.addEventListener('click', procesarProducto);             
    });

    //añadimos event listener para cada botón de Cola, que llamará a la función para meter o sacar el producto de la cola en función de si es mas_cola o menos_cola
    const botones_mas_cola_producto = document.querySelectorAll('.mas_cola_producto');

    botones_mas_cola_producto.forEach( item => {
        item.addEventListener('click', function() {
            //enviamos name, que es el id_product, dentro de un array, a masColaProducto
            masColaProducto(new Array(item.name)); 
        });           
    });

    const botones_menos_cola_producto = document.querySelectorAll('.menos_cola_producto');

    botones_menos_cola_producto.forEach( item => {
        item.addEventListener('click', function() {
            //enviamos name, que es el id_product, de momento no como array, a menosColaProducto
            menosColaProducto(item.name); 
        });           
    });
    
    //añadimos eventlistener a cada check de producto, si se marca o desmarca se comprobarán todos, si alguno está marcado el botón de añadir a lista  deberá estar enabled, si ninguno está marcado estará disabled. 
    const checks_productos = document.querySelectorAll('.checks_linea_producto');
    checks_productos.forEach( item => {
        item.addEventListener('change', 
            function check_checkboxes () {                
                var marcado = 0;
                checks_productos.forEach( item => {
                    if (item.checked == true) {
                        marcado = 1;
                    }
                });

                if (marcado == 1) {
                    document.querySelector('#meter_cola').disabled = false;
                } else {
                    document.querySelector('#meter_cola').disabled = true;
                }
            }
        ); 
    });

    //añadimos eventlistenr al botón general de meter en cola aquí, ya que si lo ponemos al generar la cabecera y el botón no funciona.
    document.querySelector('#meter_cola').addEventListener('click', meterColaBulk);
} 

//función llamada cuando se pulsa el botón de Añadir a cola para varios productos. Recogerá los checks marcados para identificar los productos y enviará su id_product a la función masColaProducto() como argumento
function meterColaBulk() {
    // console.log('en meter cola bulk');  
    var array_ids = new Array();  
    var productos_checked = document.querySelectorAll('.checks_linea_producto:checked');
    productos_checked.forEach( item => {        
        //metemos en el array el value, que es el id_product
        array_ids.push(item.value);
    });

    //enviamos el array
    masColaProducto(array_ids);
    
}

//función que recibe como parámetro un array con el id_product del producto a meter en cola. Si se llamó desde meterColaBulk() el array podrá llevar varios ids. Llamará al controlador para hacer el insert (o update) en lafrips_redactor_descripcion
function masColaProducto(array_ids) {
    // console.log('cola producto '+e.currentTarget.id);
    console.log(array_ids);

    //mostramos spinner
    spinnerOn();
     
    //preparamos la llamada ajax
    var dataObj = {};

    dataObj['productos'] = array_ids;    
    
    $.ajax({
        url: 'index.php?controller=AdminRedactorDescripciones' + '&token=' + token + "&action=mas_cola_productos" + '&ajax=1' + '&rand=' + new Date().getTime(),
        type: 'POST',
        data: dataObj,
        cache: false,
        dataType: 'json',
        success: function (data, textStatus, jqXHR)
        
        {
            if (typeof data.error === 'undefined')
            {                
                
                console.dir(data);  
                
                //si se metieron correctamente en cola, hay que actualizar los productos, marcando como en cola, e impidiendo que se puedan enviar de nuevo, tanto impidiendo el checkbox como el botón Cola

                data.productos_cola.forEach(id_product => {
                    console.log(id_product);
                    document.querySelector("#boton_cola_"+id_product).innerHTML = `
                        <button class="btn btn-default menos_cola_producto" type="button" title="Eliminar producto de cola" id="menos_cola_${id_product}" name="${id_product}">
                            <i class="icon-minus"></i> Cola
                        </button> 
                    `;

                    document.querySelector("#redactado_"+id_product).innerHTML = `
                        <span class="badge badge-warning">En cola</span>
                    `;

                    document.querySelector("#product_checkbox_"+id_product).checked = false;
                    document.querySelector("#product_checkbox_"+id_product).disabled = true;

                });

                showSuccessMessage(data.message);

                // showNoticeMessage('notice notice');                
                
                //eliminamos spinner
                spinnerOff();
            }
            else
            {       
                //eliminamos spinner
                spinnerOff();               

                showErrorMessage(data.message);
            }

        },
        error: function (jqXHR, textStatus, errorThrown)
        {
            showErrorMessage('ERRORS: ' + textStatus);
        }
    });  //fin ajax

}

//función que recibe como parámetro el id_product del producto a eliminar de cola. De momento no permitiré hacer bulk a varios al mismo tiempo. Llamará al controlador para hacer el update en lafrips_redactor_descripcion
function menosColaProducto(id_product) {
    // console.log('cola producto '+e.currentTarget.id);
    console.log(id_product);

    //mostramos spinner
    spinnerOn();
     
    //preparamos la llamada ajax
    var dataObj = {};

    dataObj['producto'] = id_product;    
    
    $.ajax({
        url: 'index.php?controller=AdminRedactorDescripciones' + '&token=' + token + "&action=menos_cola_productos" + '&ajax=1' + '&rand=' + new Date().getTime(),
        type: 'POST',
        data: dataObj,
        cache: false,
        dataType: 'json',
        success: function (data, textStatus, jqXHR)
        
        {
            if (typeof data.error === 'undefined')
            {                
                
                console.dir(data);    

                //si se elimnaron correctamente de cola, hay que actualizar los productos, marcando como No o Redactado, y permitiendo que se puedan enviar de nuevo, tanto activando el checkbox como cambiando el botón Cola a Mas cola
                console.log(data.id_producto_cola);
                document.querySelector("#boton_cola_"+data.id_producto_cola).innerHTML = `
                    <button class="btn btn-default mas_cola_producto" type="button" title="Añadir producto a cola" id="mas_cola_${data.id_producto_cola}" name="${data.id_producto_cola}">
                        <i class="icon-plus"></i> Cola
                    </button>
                `;

                //si el producto ya está redactado mostramos success y si no info con if else ternario
                document.querySelector("#redactado_"+data.id_producto_cola).innerHTML = 
                data.redactado == 1 ? 
                `<span class="badge badge-success">Si</span>` : `<span class="badge badge-info">No</span>`;

                document.querySelector("#product_checkbox_"+data.id_producto_cola).checked = false;
                document.querySelector("#product_checkbox_"+data.id_producto_cola).disabled = false;                

                //eliminamos spinner
                spinnerOff();

            }
            else
            {       
                

                //eliminamos spinner
                spinnerOff();

                showErrorMessage(data.message);
            }

        },
        error: function (jqXHR, textStatus, errorThrown)
        {
            showErrorMessage('ERRORS: ' + textStatus);
        }
    });  //fin ajax

}

function procesarProducto(e) {
    console.log('procesar producto '+e.currentTarget.id);
}



//spinner de carga
function spinnerOn() {
    // console.log('función spinner');
    //nos aseguramos de que no haya un spinner presente
    if (document.contains(document.querySelector('#spinner'))) {
        document.querySelector('#spinner').remove();
    }

    //para que el spinner quede más o menos colocado le vamos hacer append al div fieldset_0
    const fieldset_0 = document.querySelector('#fieldset_0');
    // console.log(botonera);
  
    const divSpinner = document.createElement('div');
    divSpinner.id = 'spinner';
    divSpinner.classList.add('sk-circle');
  
    divSpinner.innerHTML = `
        <div class="sk-circle1 sk-child"></div>
        <div class="sk-circle2 sk-child"></div>
        <div class="sk-circle3 sk-child"></div>
        <div class="sk-circle4 sk-child"></div>
        <div class="sk-circle5 sk-child"></div>
        <div class="sk-circle6 sk-child"></div>
        <div class="sk-circle7 sk-child"></div>
        <div class="sk-circle8 sk-child"></div>
        <div class="sk-circle9 sk-child"></div>
        <div class="sk-circle10 sk-child"></div>
        <div class="sk-circle11 sk-child"></div>
        <div class="sk-circle12 sk-child"></div>
    `;

    // console.log(divSpinner);
    fieldset_0.appendChild(divSpinner);
}

//eliminar spinner de carga si está en el documento
function spinnerOff() {
    //eliminamos spinner
    if (document.contains(document.querySelector('#spinner'))) {
        document.querySelector('#spinner').remove();
    }
}

