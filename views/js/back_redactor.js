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

    //vamos a añadir un panel lateral para visualizar la descripción de un producto concreto, llamado div_productos, lo creamos y ponemos adjunto antes que la tabla, de modo que se desplaza a la derecha al poner el panel de tabla
    //div para mostrar las descripciones
    const div_productos = document.createElement('div');
    div_productos.classList.add('clearfix','col-lg-5');
    div_productos.id = 'div_productos';
    document.querySelector('div.panel-heading').insertAdjacentElement('afterend', div_productos);

    //generamos la tabla "vacia" para los productos resultados de las consultas
    //utilizamos el mismo formato de prestashop para mostrar los productos, con tabla responsiva etc.
    //div contenedor de la tabla
    const div_tabla = document.createElement('div');
    div_tabla.classList.add('table-responsive-row','clearfix','col-lg-7');
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
            <th class="center">
				<span class="title_box">Imagen</span>
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
                <span class="title_box"> Activo
                </span>
            </th>
            <th class="fixed-width-xs text-center">
                <span class="title_box"> Index
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
            <th class="text-center">--</th> 
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
                <select class="filter center" name="filtro_activado"  id="filtro_activado">
                    <option value="0" selected="selected">-</option>
                    <option value="1">Si</option>  
                    <option value="2">No</option>                                                                    
                </select>
            </th>           
            <th class="text-center">
                <select class="filter center" name="filtro_indexado"  id="filtro_indexado">
                    <option value="0" selected="selected">-</option>
                    <option value="1">Si</option>  
                    <option value="2">No</option>                                                                    
                </select>
            </th>  
            <th class="text-center">
                <select class="filter center" name="filtro_redactado"  id="filtro_redactado">
                    <option value="0" selected="selected">-</option>
                    <option value="1">Si</option>  
                    <option value="2">En cola</option>   
                    <option value="3">No</option>      
                    <option value="4">Error</option>                                                           
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
            <button type="button" name="meter_cola_varios"  id="meter_cola_varios" class="btn btn-success" title="Añadir a cola los productos seleccionados" disabled>
                <i class="icon-folder"></i>
                Añadir a cola
            </button>                  
        </div>        
    `;     
    
    document.querySelector('.panel-heading').innerHTML = boton_cola;   

    //26/12/2024 Vamos a modificar todo para que se pueda seleccionar la API con la que solictar la descripción, hasta ahora era redacta.me y vamos a añadir un GPT de Openai. Pondremos dos botones al cargar la pantalla, por defecto pulsado el de Openai, y si se pulsa el de Redactame se deshabilita el otro y viceversa.
    var radiobutton_apis = `
    <div class="col-lg-2 pull-right">
        <div class="switch-container">
            <input type="radio" id="radio_openai" name="switch_apis" value="openai" checked>
            <label for="radio_openai" class="switch-label">OpenAI</label>

            <input type="radio" id="radio_redactame" name="switch_apis" value="redactame">
            <label for="radio_redactame" class="switch-label">Redacta.me</label>

            <div class="slider"></div>
        </div>
    </div> 
    `;    
    
    document.querySelector('.panel-heading').innerHTML += radiobutton_apis;      

    //parece que al añadir de esta forma el checkbox no es detectado correctamente para el eventListener con change, lo que hacemos es poner el eventListener al panel-heading que ya existía, y preguntar sobre qué elemento es el evento    
    document.querySelector('.panel-heading').addEventListener('change', function(e) {
        if (e.target.matches('input[name="switch_apis"]')) {
            console.log(`Seleccionada API: ${e.target.value}`);  
            //si se cambia de api para los textos quiero "reiniciar" el panel lateral quitÃ¡ndolo
            if (document.contains(document.querySelector('#div_producto'))) {
                document.querySelector('#div_producto').remove();
            }          
        }
    });
       

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
            document.querySelector('#meter_cola_varios').disabled = false;
        } else {
            // console.log("Checkbox is not checked..");
            //se desmarcan todos los checks de producto
            checkboxes.forEach( item => {
                item.checked = false;
            });
            //se hace botón disabled
            document.querySelector('#meter_cola_varios').disabled = true;
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

    //añadimos event listener para el select de activado, para cuando se cambia
    document.querySelector('#filtro_activado').addEventListener('change', function (e) {        
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
    div_productos.appendChild(boton_scroll);

    //14/03/2024 Voy a añadir un div al body que inicialmente tiene display none y cuando estemos procesando algo se hará visible, oscuerciendo la pantalla e impidiendo que el usuario toque nada hasta que el proceso haya terminado
    const panel_procesando = document.createElement("div");
    panel_procesando.id = "panel_procesando";
    panel_procesando.style.position = "fixed";
    panel_procesando.style.top = "0";
    panel_procesando.style.left = "0";
    panel_procesando.style.width = "100%";
    panel_procesando.style.height = "100%";
    panel_procesando.style.backgroundColor = "rgba(0, 0, 0, 0.5)"; // semi-transparent black
    panel_procesando.style.zIndex = "9999";
    panel_procesando.style.display = "none"; // initially hidden

    // Append the overlay div to the body
    document.querySelector('body').appendChild(panel_procesando);

    //eventlistener para botón de Añadir a cola AQUÍ NO FUNCIONA ¿?
    // const boton_meter_cola_varios_bulk = document.querySelector('#meter_cola_varios');
    // boton_meter_cola_varios_bulk.addEventListener('click', meterColaBulk);

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
    const busqueda_activado = document.querySelector('#filtro_activado').value;
    const busqueda_indexado = document.querySelector('#filtro_indexado').value; 
    const busqueda_redactado = document.querySelector('#filtro_redactado').value; 
    const busqueda_revisado = document.querySelector('#filtro_revisado').value; 
    const busqueda_fecha_desde = document.querySelector('#filtro_desde').value; 
    const busqueda_fecha_hasta = document.querySelector('#filtro_hasta').value; 
    const busqueda_limite_productos = document.querySelector('#filtro_limite_productos').value; 
    //obtenemos el valor de número de página del paginador
    var numero_pagina = document.querySelector('#numero_pagina').innerHTML; 

    //si la página actual no es 1 y filtramos por algo falla al buscar. Página actual es útil para cuando vamos avanzando por las páginas, pero si hacemos un nuevo filtro queremos que obtenga los productos "desde cero", de modo que si paginacion = "", es decir, no se ha pulsado ninguna flecha de paginador para hacer esta llamda de búsqueda, reseteamos numero_pagina a 1.
    if (paginacion == "") {
        numero_pagina = 1;
    }

    // console.log('flechaid='+flechaId); 
    // console.log(busqueda_id);
    // console.log(busqueda_proveedor);  
    // console.log(busqueda_estado);
    // console.log(busqueda_fecha_desde);
    // console.log(busqueda_fecha_hasta);
    console.log('numero_pagina='+numero_pagina); 
        
    obtenerProductos(busqueda_id_product, busqueda_referencia, busqueda_nombre, busqueda_proveedor, busqueda_fabricante, busqueda_activado, busqueda_indexado, busqueda_redactado, busqueda_revisado, busqueda_fecha_desde, busqueda_fecha_hasta, flecha_orden, busqueda_limite_productos, numero_pagina, paginacion);
    
}

//función que llama al controlador y pide los productos en función de los filtros y parámetros de búsqueda marcados. Se llama a esta función desde la función buscarOrdenado() si se cambia algún filtro u orden, pero para la carga inicial recibe unos parámetros por defecto.
function obtenerProductos(id_product = "", referencia = "", nombre = "", proveedor = 0, fabricante = 0, activado = 0, indexado = 0, redactado = 0, revisado = 0, fecha_desde = "", fecha_hasta = "", orden = "", limite_productos = 20, numero_pagina = 1, paginacion = "") {
    console.log(arguments);
    //ante cualquier búsqueda, si hay algo en el panel lateral limpiamos    
    if (document.contains(document.querySelector('#div_producto'))) {
        document.querySelector('#div_producto').remove();
    } 
    
    //mostramos spinner
    spinnerOn();

    //sacamos panel procesando
    showPanelProcesando();
     
    //preparamos la llamada ajax
    var dataObj = {};

    dataObj['id_product'] = id_product;
    dataObj['reference'] = referencia;    
    dataObj['product_name'] = nombre;  
    dataObj['id_supplier'] = proveedor;
    dataObj['id_manufacturer'] = fabricante;
    dataObj['activado'] = activado;
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
                document.querySelector('#meter_cola_varios').disabled = true;

                //con los datos, llamamos a la función que nos los mostrará
                muestraListaProductos(data.info_productos, data.total_productos, data.pagina_actual); 

                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()

            }
            else
            {       
                //limpiamos tabla
                if (document.contains(document.querySelector('#tbody'))) {
                    document.querySelector('#tbody').remove();
                }

                //nos aseguramos de que el check de todos productos no esté checado y el botón de añadir a cola esté disbled
                document.querySelector("#selecciona_todos_productos").checked = false;
                document.querySelector('#meter_cola_varios').disabled = true;

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

                //escondemos panel procesando
                hidePanelProcesando()

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
            var activado = '';  
            var indexado = '';
            var redactado = '';     
            var revisado = '';  
            var badge_activado = '';
            var badge_redactado = '';
            var badge_revisado = '';         

            //27/12/2024 Hay que mostrar cuando están en cola, con que api, el dato debe llegar desde el controlador, api_seleccionada
            var api_seleccionada = producto.api_seleccionada;

            //ponemos badge en redactado y en revisado. 07/09/2023 añadimos Activo (activado. Badge success si si, danger si no). Redactado tendrá badge 'info' si aún no se ha hecho nada, 'warning' si está en lista o procesando y success si ya está redactado. Revisado tendrá 'info' si el producto aún no ha sido redactado, danger si ha sido redactado pero no revisado, y success cuando está redactado y revisado.     
            if (producto.indexado == 1) {
                indexado = 'Si';
            } else {
                indexado = 'No';
            } 

            if (producto.activo == 1) {
                activado = 'Si';
                badge_activado = 'success';
            } else {
                activado = 'No';
                badge_activado = 'danger';
            }
            
            var check_disabled = "";
            if (producto.redactado == 1) {
                redactado = 'Si';
                badge_redactado = 'success';
            } else if (producto.redactado == 2) {
                check_disabled = " disabled";                
                badge_redactado = 'warning';

                if (api_seleccionada == 'redactame') {
                    redactado = 'En cola<br><small>Redacta.me</small>';
                } else if (api_seleccionada == 'openai') {
                    redactado = 'En cola<br><small>OpenAI</small>';
                } else {
                    redactado = 'En cola<br><small>ERROR</small>';
                }

            } else if (producto.redactado == 3) {                
                redactado = 'Error';
                badge_redactado = 'danger';
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
            } else if (((producto.revisado == 0) && (producto.redactado == 1)) || producto.redactado == 3) {
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
                <td class="center">
                    <img src="${producto.url_imagen}" alt="" class="imgm img-thumbnail"  width="43" height="45"> 
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
                <td class="fixed-width-xs center" id="activado_${producto.id_product}">
                    <span class="badge badge-${badge_activado}">${activado}</span>
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
                <td class="text-left quita_padding"> 
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

    //añadimos al texto de panel-heading el número de productos totales que corresponden a los filtros, independientemente de los que se muestran por la paginación. 
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
                    document.querySelector('#meter_cola_varios').disabled = false;
                } else {
                    document.querySelector('#meter_cola_varios').disabled = true;
                }
            }
        ); 
    });

    //añadimos eventlistenr al botón general de meter en cola aquí, ya que si lo ponemos al generar la cabecera y el botón no funciona.
    document.querySelector('#meter_cola_varios').addEventListener('click', meterColaBulk);
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
//27/12/2024 Añadimos otro valor a enviar al controlador, la api seleccionada para utilizar en la redacción
function masColaProducto(array_ids) {
    // console.log('cola producto '+e.currentTarget.id);
    console.log(array_ids);

    let api_seleccionada = document.querySelector('input[name="switch_apis"]:checked').value;

    //mostramos spinner
    spinnerOn();

    //sacamos panel procesando
    showPanelProcesando();
     
    //preparamos la llamada ajax
    var dataObj = {};

    dataObj['productos'] = array_ids;    
    dataObj['api_seleccionada'] = api_seleccionada;    
    
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

                    //añadimos eventlistener al nuevo botón
                    document.querySelector("#menos_cola_"+id_product).addEventListener('click', function() {
                        //enviamos name, que es el id_product, de momento no como array, a menosColaProducto
                        menosColaProducto(id_product); 
                    });  

                    if (api_seleccionada == 'redactame') {
                        var api_seleccionada_small = 'Redacta.me';
                    } else if (api_seleccionada == 'openai') {
                        var api_seleccionada_small = 'OpenAI';
                    }

                    document.querySelector("#redactado_"+id_product).innerHTML = `
                        <span class="badge badge-warning">En cola<br><small>${api_seleccionada_small}</small></span>
                    `;

                    document.querySelector("#product_checkbox_"+id_product).checked = false;
                    document.querySelector("#product_checkbox_"+id_product).disabled = true;

                });

                showSuccessMessage(data.message);

                // showNoticeMessage('notice notice');                
                
                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()
            }
            else
            {       
                //eliminamos spinner
                spinnerOff();         
                
                //escondemos panel procesando
                hidePanelProcesando()

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

    //sacamos panel procesando
    showPanelProcesando();
     
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

                //añadimos eventlistener a botón
                document.querySelector("#mas_cola_"+data.id_producto_cola).addEventListener('click', function() {
                    //enviamos name, que es el id_product, dentro de un array, a masColaProducto
                    masColaProducto(new Array(data.id_producto_cola)); 
                }); 

                //si el producto ya está redactado mostramos success y si no info con if else ternario
                document.querySelector("#redactado_"+data.id_producto_cola).innerHTML = 
                data.redactado == 1 ? 
                `<span class="badge badge-success">Si</span>` : `<span class="badge badge-info">No</span>`;

                document.querySelector("#product_checkbox_"+data.id_producto_cola).checked = false;
                document.querySelector("#product_checkbox_"+data.id_producto_cola).disabled = false;                

                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()

            }
            else
            {       
                

                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()

                showErrorMessage(data.message);
            }

        },
        error: function (jqXHR, textStatus, errorThrown)
        {
            showErrorMessage('ERRORS: ' + textStatus);
        }
    });  //fin ajax

}

//función que recibe un id_product y llama vía ajax al controlador para añadirlo a cola de traducciones
function masColaTraduccion(id_product) {   
    //mostramos spinner
    spinnerOn();

    //sacamos panel procesando
    showPanelProcesando();
     
    //preparamos la llamada ajax
    var dataObj = {};

    dataObj['id_product'] = id_product;  

    $.ajax({
        url: 'index.php?controller=AdminRedactorDescripciones&action=masColaTraducciones&token=' + token + '&ajax=1&rand=' + new Date().getTime(),
        type: 'POST',
        data: dataObj,
        cache: false,
		// async: true,
        dataType: 'json',
        success: function (data, textStatus, jqXHR)
        
        {
            if (typeof data.error === 'undefined')
            {                
                
                console.dir(data);  
                
                //si se metió correctamente en cola, hay que deshabilitar el botón Cola traducción
                document.querySelector("#boton_cola_traduccion_"+id_product).disabled = true;                 

                showSuccessMessage(data.message);

                // showNoticeMessage('notice notice');                
                
                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()
            }
            else
            {       
                //eliminamos spinner
                spinnerOff();         
                
                //escondemos panel procesando
                hidePanelProcesando()

                showErrorMessage(data.message);
            }

        },
        error: function (jqXHR, textStatus, errorThrown)
        {
            showErrorMessage('ERRORS jqXHR: ' + JSON.stringify(jqXHR));
			showErrorMessage('ERRORS textStatus: ' + textStatus);
			showErrorMessage('ERRORS errorThrown: ' + errorThrown);
        }
    });  //fin ajax

}

//al recibir en esta función primero hay que comprobar que la tabla lafrips_redactor_descripcion ya tenga una entrada para el producto cuyo botón se ha pulsado, para insertarla si no, se hará en el controlador al mismo tiempo que sacamos la info del producto
function procesarProducto(e) {
    console.log('procesar producto '+e.currentTarget.id);

    //primero limpiamos el div id div_producto por si hay algo, no div_productos
    if (document.contains(document.querySelector('#div_producto'))) {
        document.querySelector('#div_producto').remove();
    } 

    //usamos currentTarget en lugar de target, ya que si se pulsa sobre el icono del botón lo interpreta como target, no teniendo la clase que buscamos, ni el id, etc. Con currentTarget, se va hacia arriba buscando el disparador del event listener    
    if(e.currentTarget && e.currentTarget.classList.contains('procesa_producto')){                    
        //para sacar el id del producto, cogemos el id del botón pulsado y separamos por _        
        var botonId = e.currentTarget.id;
        var splitBotonId = botonId.split('_');
        var id_product = splitBotonId[splitBotonId.length - 1];     
        
        console.log(id_product);       
        
        //mostramos spinner
        spinnerOn();

        //sacamos panel procesando
        showPanelProcesando();

        var dataObj = {};
        dataObj['id_product'] = id_product;
        //el token lo hemos sacado arriba del input hidden
        $.ajax({
            url: 'index.php?controller=AdminRedactorDescripciones' + '&token=' + token + "&action=mostrar_producto" + '&ajax=1' + '&rand=' + new Date().getTime(),
            type: 'POST',
            data: dataObj,
            cache: false,
            dataType: 'json',
            success: function (data, textStatus, jqXHR)
            
            {
                if (typeof data.error === 'undefined')
                {                                 
                    console.dir(data.info_producto);     
                    
                    muestraProducto(data.info_producto);  
                    
                            

                    //eliminamos spinner
                    spinnerOff();

                    //escondemos panel procesando
                    hidePanelProcesando()

                }
                else
                {                    
                    //eliminamos spinner
                    spinnerOff();

                    //escondemos panel procesando
                    hidePanelProcesando()

                    showErrorMessage(data.message);
                }

            },
            error: function (jqXHR, textStatus, errorThrown)
            {
                showErrorMessage('ERRORS: ' + textStatus);
            }
        });  //fin ajax
    }
}


function muestraProducto(producto) {
    // console.log(info);

    //13/03/2024 Ha habido problemas de que no se elimine el panel de producto, volvemos a preguntar aquí si hay uno y lo eliminamos. limpiamos el div id div_producto por si hay algo, no div_productos
    if (document.contains(document.querySelector('#div_producto'))) {
        document.querySelector('#div_producto').remove();
    } 

    //27/12/2024 Al añadir la posibilidad de utilizar Openai para las descripciones, vamos a mostrar el producto un poco diferente si es para una u otra api, comprobamos cual es la api seleccionada en el radiobutton de apis
    let api_seleccionada_controlador = document.querySelector('input[name="switch_apis"]:checked').value;

    console.log(`API seleccionada: ${api_seleccionada_controlador}`);  
    
    //si tenemos seleccionada redactame ocultaremos la línea de keywords y tono
    let row_redactame = ' style = "display: none"';
    if (api_seleccionada_controlador == 'redactame') {
        row_redactame = "";
    }

    const div_producto = document.createElement('div');
    div_producto.classList.add('clearfix','panel_sticky');
    div_producto.id = 'div_producto';
    document.querySelector('div#div_productos').appendChild(div_producto);

    //tenemos que mostrar un row con info del producto, foto, referencia, etc. Si ha sido redactado antes, revisado, si está en cola o procesando, con avisos. Después un input con la info pasada a la API sacada de api_json si ya fue redactado, o con la descripción del producto, que sería lo que enviaremos a la API. Si la descripción tiene más de 500 caracteres se avisa. Mostramos también la descripción actual en otro input, esta coincidirá con el anterior input si el producto no ha sido redactado. Para Redacta.me habrá un select para seleccionar el "tono" a asignar a la api (persuasive, etc) por defecto Profesional, y un input para keywords, que son opcionales. Ambas cosas desaparecerán para OpenAI
    //18/09/2024 redacta.me ha ampliado el límite de caracteres de la descripción de 500 a 5000
    //se puede guardar la descripción si se modifica, ya que esta pantalla sirve para pedir una nueva descripción o para revisarla, marcando botón de revisado.

    if (producto.indexado) {
        var indexado = "SI";
    } else {
        var indexado = "NO";
    }

    //30/12/2024 guardamos el dato de si hay una api de redacción seleccionada (si está en cola por ejemplo) y si ha sido redactado, con que api
    var api_seleccionada_producto = producto.api_seleccionada;
    var redactado_api = producto.redactado_api;

    var esta_procesando = "";
    var disable_procesando = "";
    var mensaje_procesando = "";
    if (producto.procesando == 1) {
        esta_procesando = `
        <span id="procesando_badge" class="badge badge-danger" title="Este producto está siendo procesado, los botones están deshabilitados. Espera y recarga para continuar">Atención - Procesando - Api: <strong>${api_seleccionada_producto}</strong></span> ${producto.date_inicio_proceso}<br>        
        `;
        //si el producto está procesandose deshabilitamos los botones
        disable_procesando = " disabled";
        mensaje_procesando = `
          <span class="badge badge-danger" title="Este producto está siendo procesado, los botones están deshabilitados. Espera y recarga para continuar">Atención - Procesando</span>
        `;
    }

    var esta_en_error = "";
    if (producto.en_error == 1) {
        esta_en_error = `
        <span id="en_error_badge" class="badge badge-danger" title="Este producto tuvo un error al generar la descripción">Error</span> 
        ${producto.date_error}<br>
        ${producto.error_message}<br>
        `;
    }

    var esta_encola = "";
    if (producto.en_cola == 1) {
        esta_encola = `
        <span id="en_cola_badge" class="badge badge-warning" title="Este producto está en la cola en espera de ser procesado">En Cola - Api: <strong>${api_seleccionada_producto}</strong></span> 
        ${producto.employee_metido_cola} - ${producto.date_metido_cola}<br>
        `;
    }

    var esta_redactado = "";
    if ((producto.redactado == 1) && (producto.revisado == 1)) {
        esta_redactado = `
        <span id="redactado_badge" class="badge badge-success" title="Este producto ya ha sido redactado y revisado">Redactado - Api: <strong>${redactado_api}</strong></span>
        ${producto.employee_redactado} - ${producto.date_redactado}<br>
        `;
    } else if ((producto.redactado == 1) && (producto.revisado == 0)) {
        esta_redactado = `
        <span id="redactado_badge" class="badge badge-info" title="Este producto ya ha sido redactado">Redactado - Api: <strong>${redactado_api}</strong></span>
        ${producto.employee_redactado} - ${producto.date_redactado}<br>
        `;
    }

    var esta_revisado = "";
    if ((producto.redactado == 1) && (producto.revisado == 1)) {
        esta_revisado = `
        <span id="revisado_badge" class="badge badge-success" title="Este producto ya ha sido revisado">Revisado - Api: <strong>${api_seleccionada_producto}</strong></span>
        ${producto.employee_revisado} - ${producto.date_revisado}<br>
        `;
    } else if ((producto.redactado == 1) && (producto.revisado == 0)) {
        esta_revisado = `<span id="revisado_badge" class="badge badge-warning" title="Este producto aún no ha sido revisado">No Revisado - Api: <strong>${api_seleccionada_producto}</strong></span><br>`;
    } else {
        esta_revisado = "";
    } 

    var panel_info_procesos = "";
    if (esta_en_error != "" || esta_procesando != "" || esta_encola != "" || esta_redactado != "" || esta_revisado != "") {
        panel_info_procesos = `
        <div class="panel panel_producto panel_info_procesos">
            ${esta_en_error}
            ${esta_procesando}
            ${esta_encola}
            ${esta_redactado}
            ${esta_revisado}
        </div>
        `;
    }

    //si el producto está desactivado, mostramos un botón para activarlo    
    var disable_activo = "";
    var esta_activo = "";
    if (producto.activo == 1) {
        esta_activo = "<span class='badge badge-success' title='Producto activo en Prestashop'>Activo</span>";
        disable_activo = " disabled";        
    } else {
        esta_activo = "<span class='badge badge-danger' title='Producto NO activo en Prestashop'>No Activo</span>";
    }   

    var info_producto = `
    <div class="panel clearfix panel_producto">
        <div class="col-lg-3">
            <img src="${producto.url_imagen}" alt="${producto.name}"  height="180px" width="135px"  title="${producto.name}">
        </div>
        <div class="col-lg-1">
        </div>
        <div class="col-lg-8">
            <div class="row">
                <div class="col-lg-5">
                    ID: <b>${producto.id_product}</b>  <span id="esta_activo_${producto.id_product}">${esta_activo}</span><br>
                    Indexado: <b>${indexado}</b><br>  
                    Creado: <b>${producto.date_creado}</b><br><br>
                </div>
                <div class="col-lg-7">
                    Referencia: <b>${producto.reference}</b><br> 
                    Proveedor: <b>${producto.supplier}</b><br> 
                    Fabricante: <b>${producto.manufacturer}</b><br> 
                </div>
            </div>    
            <div class="row">        
                <div id="info_procesos">
                    ${panel_info_procesos}
                </div>
            </div>
        </div>
    </div>
    `;

    //si se hizo petición anterior, tenemos en producto.info_api lo que se envió en descripción en forma de objeto. Si hay contenido probamos a sacarlo primero para formato json  openai y sino para redactame, si aún así no sale nada pondremos la descripción de producto
    if (producto.info_api) {
        var descripcion_api = getApiDescription(producto.info_api);

        if (!descripcion_api) {
            descripcion_api = producto.descripcion;
        }       
        
    } else {
        //si no tenemos nada ponemos la description_short
        var descripcion_api = producto.descripcion;
    }

    //la API de redacta.me necesita un nombre, hasta 50 char, una descripción, hasta 500char, palabras clave, que no usamos pero pongo input y el tono, opcional también, que usamos por defecto Profesional ponemos select, aunque cuando se haga mediante lista se usará el por defecto.
    //18/09/2024 redacta.me ha ampliado el límite de caracteres de la descripción de 500 a 5000
    //08/03/2024 Añadimos un input hidden donde guardar si se genera descripción desde aquí con el botón procesar, de modo que al marcar revisar sepamos desde el controlador que estamos revisando una descripción generada en el momento y no una de la cola de redacción o simplemnte la descripción no generada por aPI
    //13/03/2024 Añadimos el id_product a todos los inputs y textareas para evitar un posible error en ocasiones, que aparentemente no se elimina el panel de un producto al mostrar otro y al pulsar revisar por ejemplo no recoje el textarea del producto del botón.
    //17/04/2024 Ponemos un botón de Cola Traducción junto al de activar el producto, que llamará vía Ajax a masColaTraducciones() para añadir producto a cola de traducciones.
    //27/12/2024 Dependiendo de la api elegida para generar la descripción mostraremos keywords y tono (redactame) o no (openai)
    var api_descripcion = `
    <div class="panel clearfix panel_producto">
        <h3>INFO API y descripción${mensaje_procesando}</h3>
        <div class="row info_api">
            <div class="row row_api">
                <label for="input_nombre_api_${producto.id_product}" class="control-label col-sm-2 col-form-label col-form-label-sm">
                    <span title="Max. 50 caracteres" data-toggle="tooltip" class="label-tooltip" data-html="true">
                        Nombre
                    </span>
                </label>
                <div class="col-sm-9">
                    <input type="text" id="input_nombre_api_${producto.id_product}" value="${producto.name}" onkeyup="cuentaCaracteres(this);">
                </div>
                <div class="col-sm-1">
                    <span id="caracteres_nombre_api_${producto.id_product}"></span>
                </div>
            </div>
            <div class="row row_api" ${row_redactame}>
                <label for="input_keywords_api_${producto.id_product}" class="control-label col-sm-2 col-form-label col-form-label-sm">
                    <span title="Introduce palabras clave separadas por coma (opcional)" data-toggle="tooltip" class="label-tooltip" data-html="true">
                        Keywords
                    </span>
                </label>
                <div class="col-sm-7">
                    <input type="text" id="input_keywords_api_${producto.id_product}" placeholder="Opcional">
                </div>
                <label for="select_tono_api_${producto.id_product}" class="control-label col-sm-1 col-form-label col-form-label-sm">Tono</label>
                <div class="col-sm-2">
                    <select id="select_tono_api_${producto.id_product}">                        
                        <option value="Aggressive">Agresivo</option>
                        <option value="Creative">Creativo</option>
                        <option value="Formal">Formal</option>
                        <option value="Informal">Informal</option>
                        <option value="Witty">Ingenioso</option>
                        <option value="Ironic">Irónico</option>
                        <option value="Persuasive">Persuasivo</option>
                        <option value="Professional" selected>Profesional</option>
                    </select>
                </div>   
            </div>
            <div class="row">
                <label for="textarea_descripcion_api_${producto.id_product}" class="control-label col-form-label col-form-label-sm">
                    <span title="Última petición a API o descripción actual. Max. 5000 caracteres" data-toggle="tooltip" class="label-tooltip" data-html="true">
                        Instrucciones o datos del producto para enviar a la API
                    </span>
                    <span class="caracteres_descripcion_api" id="caracteres_descripcion_api_${producto.id_product}"></span>
                </label>
                <textarea class="form-control" id="textarea_descripcion_api_${producto.id_product}" onkeyup="cuentaCaracteres(this);">${descripcion_api}</textarea>
                <br>
            </div>
        </div>        
        <div class="row descripcion">
            <div class="panel clearfix panel_producto">
                <h3>
                    <span id="contenido_textarea_${producto.id_product}">
                        <span title="Contenido de la descripción corta del producto en Prestashop" data-toggle="tooltip" class="label-tooltip" data-html="true">
                            Descripción actual del producto
                        </span>
                    </span>
                    <div class="btn-group pull-right">                         
                        <button class="btn btn-small" type="button" title="Marcar en negrita" id="boton_negrita" name="boton_negrita">
                            <i class="icon-bold"></i>
                        </button> 
                        Shift+B
                    </div>
                </h3>                
                <textarea class="form-control area_descripcion" id="textarea_descripcion_actual_producto_${producto.id_product}" rows="9">${producto.descripcion}</textarea>
                <div class="btn-group pull-left">
                    <button class="btn btn-default activa_producto" type="button" title="Activar el producto en Prestashop" id="boton_activar_${producto.id_product}" name="boton_activar_${producto.id_product}"  ${disable_activo}>
                        <i class="icon-money"></i> Activar
                    </button> 
                    <button class="btn btn-default" type="button" title="Añadir producto a cola de traducciones" id="boton_cola_traduccion_${producto.id_product}" name="boton_cola_traduccion_${producto.id_product}">
                        <i class="icon-globe"></i> Cola Traducción
                    </button> 
                </div>
                <div class="btn-group pull-right">
                    <button class="btn btn-default revisa_descripcion_producto" type="button" title="Marcar descripción de producto como revisada. Guardará el contenido en la ficha de producto" id="boton_revisar_${producto.id_product}" name="boton_revisar_${producto.id_product}" ${disable_procesando}>
                        <i class="icon-thumbs-up"></i> Revisar
                    </button> 
                    <button class="btn btn-default genera_descripcion_producto" type="button" title="Generar descripción de producto con API" id="boton_generar_${producto.id_product}" name="boton_generar_${producto.id_product}" ${disable_procesando}>
                        <i class="icon-pencil"></i> Generar
                    </button>   
                    <input type="hidden" id="redactado_hidden_${producto.id_product}" name="redactado_hidden_${producto.id_product}" value="0">
                </div> 
            </panel>
        </div>                
    </div>
    `;

    div_producto.innerHTML = `
        <div class="panel panel_producto">                        
            <h3>${producto.name}</h3> 
            <div class="row">
                ${info_producto}      
            </div>
            <div class="row">
                ${api_descripcion} 
            </div>
        </div>
    `;

    //llamamos por primera vez a la función que nos cuenta y muestra el número de caracteres de la descripción a enviar a la API
    cuentaCaracteres(document.querySelector('#textarea_descripcion_api_'+producto.id_product));
    cuentaCaracteres(document.querySelector('#input_nombre_api_'+producto.id_product));

    //queremos que el textarea de la descripción generada se adapte al contenido, de modo que si excede las rows de textarea aumente su altura. Para ello lo cogemos y reseteamos su altura con "auto" y después le asignamos scrollHeight que es la altura de scroll, su altura total. Esto unido a que el panel sticky lateral es de top:109px a bottom:0px con overflow auto, hará que si se supera la medida de la pantalla aparezca un nuevo scroll vertical para el panel
    const textarea = document.querySelector('#textarea_descripcion_actual_producto_'+producto.id_product);
    // console.log('height1'+textarea.style.height);
    textarea.style.height = "auto"; // Reset the height to allow content to fit
    // console.log('height2'+textarea.style.height);
    textarea.style.height = textarea.scrollHeight + "px"; 
    // console.log('height3'+textarea.style.height);

    //añadimos eventlisteners a los botones. El botón activar, si está activo llama a la función para activar el producto desde el módulo. El botón Revisar indica que el texto ha sido revisado y por tanto lo guardamos como quede en product_lang y el botón Generar recoge los datos en los inputs para la API y llama a la clase de Redactame para hacer la petición.
    const boton_activar = document.querySelector("#boton_activar_"+producto.id_product);

    boton_activar.addEventListener('click', function(){     
        activarProducto(producto.id_product)
    }); 

    //17/04/2024 Ponemos un botón de Cola Traducción junto al de activar el producto, que llamará vía Ajax a masColaTraducciones() para añadir producto a cola de traducciones.
    const boton_cola_traduccion = document.querySelector("#boton_cola_traduccion_"+producto.id_product);

    boton_cola_traduccion.addEventListener('click', function(){     
        masColaTraduccion(producto.id_product)
    }); 

    const boton_revisar = document.querySelector("#boton_revisar_"+producto.id_product);

    boton_revisar.addEventListener('click', function(){     
        revisaDescripcion(producto.id_product)
    }); 

    const boton_generar = document.querySelector("#boton_generar_"+producto.id_product);

    boton_generar.addEventListener('click', function(){  
         generaDescripcion(producto.id_product)
    }); 

    //para el botón de poner negrita. Si se pulsa se comprueba que haya algo seleccionado dentro del textarea llamando a getTextoNegrita()
    const boton_negrita = document.querySelector("#boton_negrita");

    boton_negrita.addEventListener('click', function(){  
        getTextoNegrita(producto.id_product)
    }); 
    
    //queremos que si se pulsa la combinación de teclas Shift+B se compruebe si hay algo seleccionado dentro del text area y también se ponga en negrita como al pulsar el botón. El código de evento de la letra B es 66 o keyB. El de Shift es 16, pero está asociado a shiftKey
    document.addEventListener('keydown', function (event) {        
        // console.log(event);
        if (event.shiftKey && event.code === 'KeyB') {
            console.log('pulsado shift+B');            
            getTextoNegrita(producto.id_product);

            //para evitar que se escriba la B de Shift+B hacemos event.preventDefault, de modo que el comportamiento por defecto de escribir la letra pulsada se evita, siempre que entremos en la combinación shift+b
            event.preventDefault();
        }        
    });

}

//función que devuelve la descripción almacenada en info_api (api_json), si la hay, buscando en el json para los casos de que sea de api redactame o api openai. 
function getApiDescription(info_api) {
    console.dir(info_api);
    //primero probamos a sacar si fuera de Openai, si no contiene nada probamos con redactame, si no devolvemos null
     //como el json no es simple hay que hacerlo más complicado. Necesitamos entrar en messages y ahí sacar el objeto con role = user, y dentro de esos sacar el objeto type = text, y de ahí sacar text. Si hubiera varios type text se podrían sacar todos usando filter en lugar de find: 
    //var textos = user_role_messages ? user_role_messages.content.filter(c => c.type === "text").map(c => c.text) : [];
    if (info_api.messages) {
        var user_role_messages = info_api.messages.find(m => m.role === "user");
        //tenemos el objeto del role user, buscamos uno con type text y le sacamos el text, si fuera null, ponemos la descripción actual del producto, auqnue sería un error
        var descripcion_api = user_role_messages ? user_role_messages.content.find(c => c.type === "text").text : null;

        if (descripcion_api) {
            return descripcion_api;
        }
    }

    //no hemos sacado descripción con json formato openai, probamos redactame
    var descripcion_api = info_api.parameters.Description;

    if(descripcion_api) {
        return descripcion_api;
    } else {
        //no encontramos texto, devolvemos null
        return null;
    }

    // var user_role_messages = info_api.messages.find(m => m.role === "user");
    // //tenemos el objeto del role user, buscamos uno con type text y le sacamos el text, si fuera null, ponemos la descripción actual del producto, auqnue sería un error
    // var descripcion_api = user_role_messages ? user_role_messages.content.find(c => c.type === "text").text : null;

    // if (descripcion_api) {
    //     return descripcion_api;
    // } else {
    //     //probamos con formato json redactame
    //     descripcion_api = info_api.parameters.Description;

    //     if(descripcion_api) {
    //         return descripcion_api;
    //     } else {
    //         //no encontramos texto, devolvemos null
    //         return null;
    //     }
    // }     
}

//función que es llamada al pulsar el boton_negrita y revisa si dentrodel textarea hay alguna selección de texto. Para ello busca selectionStart y selectionEnd dentro del textarea, si estos son diferentes es que hay algo seleccionado.
//después ponemos el rango seleccionado a 0 de nuevo (es como resetear) para que si pulsamos de nuevo el botón ya no tenga nada seleccionado con setSelectionRange(0, 0) ya que si no si pulsas con algo seleccionado fuera del textarea sigue teniendo "en memoria" la anterior selección
function getTextoNegrita(id_product) {
    // console.log('dentro negrita');

    const textarea = document.querySelector('#textarea_descripcion_actual_producto_'+id_product);

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    
    if (start !== end) {
        //hay un rango seleccionado, sacamos el texto con substring
        const texto_seleccionado = textarea.value.substring(start, end);
        
        //añadimos las etiquetas de negritas al texto seleccionado
        const texto_negritas = "<strong>"+texto_seleccionado+"</strong>"; 
    
        //el nuevo texto dentro de textarea es elslice inicial hasta selectionStart con el slice final desde selectionEnd y en medio el texto con etiquetas de negrita
        const nuevo_texto_textarea = textarea.value.slice(0, start) + texto_negritas + textarea.value.slice(end);

        //añadimos el texto de nuevo al value del textarea
        textarea.value = nuevo_texto_textarea;       

        //reseteamos la selección para evitar que siga ahí si pulsamos de nuevo el botón
        textarea.setSelectionRange(0, 0);
    }

    return;
}

//función que pide la activación de un producto via Ajax
//07/09/2023 Hemos añadido filtro por producto Activo o no, cuando se active desde aquí cambiaremos el badeg en lista de productos a Activo y success
function activarProducto(id_product) {
    //mostramos spinner
    spinnerOn();

    //sacamos panel procesando
    showPanelProcesando();
     
    //preparamos la llamada ajax
    var dataObj = {};

    dataObj['id_product'] = id_product;    
    
    $.ajax({
        url: 'index.php?controller=AdminRedactorDescripciones' + '&token=' + token + "&action=activar_producto" + '&ajax=1' + '&rand=' + new Date().getTime(),
        type: 'POST',
        data: dataObj,
        cache: false,
        dataType: 'json',
        success: function (data, textStatus, jqXHR)
        
        {
            if (typeof data.error === 'undefined')
            {                
                
                // console.dir(data);    

                //si se activó el producto, deshabilitamos le botón de activar y cambiamos en la casilla de información Activado por SI
                document.querySelector("#boton_activar_"+id_product).disabled = true; 

                document.querySelector("#esta_activo_"+id_product).innerHTML = "<span class='badge badge-success' title='Producto activo en Prestashop'>Activo</span>";     
                
                document.querySelector("#activado_"+id_product).innerHTML = `
                    <span class="badge badge-success">Si</span>
                `;

                showSuccessMessage(data.message);

                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()

            }
            else
            {                      

                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()

                showErrorMessage(data.message);
            }

        },
        error: function (jqXHR, textStatus, errorThrown)
        {
            showErrorMessage('ERRORS: ' + textStatus);
        }
    });  //fin ajax
}

//función que marca un producto como revisado y al dar por buena la descripción de producto la actualiza en lafrips_product_lang, junto con el nombre
//no indicamos nada de las apis de redacción dado que se puede revisar un producto que acaba de ser redactado con una api y cambiar la api en el selector, o se puede revisar un producto que ha sido redactado con la cola y tampoco tiene que coincidir, de modo que al enviar el producto a redactar se guarda en api la api seleccionada y esa será la que cuente
function revisaDescripcion(id_product) {
    console.log(document.querySelector("#textarea_descripcion_actual_producto_"+id_product).value);

    const descripcion = document.querySelector("#textarea_descripcion_actual_producto_"+id_product).value;
    const nombre = document.querySelector("#input_nombre_api_"+id_product).value;
    //sacamos value del input hidden que se pone a 1 si hacemos la petición de descripción a la API
    const redactado_ahora = document.querySelector("#redactado_hidden_"+id_product).value;

    //mostramos spinner
    spinnerOn();

    //sacamos panel procesando
    showPanelProcesando();

    //ponemos el badge de Revisando
    var esta_revisando = `
        <br>
        <span id="revisando_badge" class="badge badge-warning" title="Este producto está siendo revisado, los botones están deshabilitados. Espera y recarga para continuar">Atención - Revisando</span><br>        
        `;

    if (document.contains(document.querySelector('.panel_info_procesos'))) {        
        document.querySelector('div.panel.panel_info_procesos').innerHTML = document.querySelector('div.panel.panel_info_procesos').innerHTML+esta_revisando;
    } else {
        var panel_info_procesos = `
        <div class="panel panel_producto panel_info_procesos">
            ${esta_revisando}            
        </div>
        `;
        document.querySelector('#info_procesos').innerHTML = panel_info_procesos;
    }
    //deshabilitamos botones mientras tanto
    document.querySelector('#boton_revisar_'+id_product).disabled = true;
    document.querySelector('#boton_generar_'+id_product).disabled = true;

    var dataObj = {};
    dataObj['id_product'] = id_product;
    dataObj['descripcion'] = descripcion;
    dataObj['nombre'] = nombre;
    dataObj['redactado_ahora'] = redactado_ahora;
    //el token lo hemos sacado arriba del input hidden
    $.ajax({
        url: 'index.php?controller=AdminRedactorDescripciones' + '&token=' + token + "&action=revisar_descripcion" + '&ajax=1' + '&rand=' + new Date().getTime(),
        type: 'POST',
        data: dataObj,
        cache: false,
        dataType: 'json',
        success: function (data, textStatus, jqXHR)
        
        {
            if (typeof data.error === 'undefined')
            {                                 
                console.dir(data);     
                
                //si se guardó correctamente la descripción, hay que actualizar los productos, marcando como Redactado, Revisado, quitar Procesando, y permitiendo que se puedan enviar de nuevo, tanto activando el checkbox como cambiando el botón Cola a Mas cola
                // console.log(data.id_producto_cola);
                document.querySelector("#boton_cola_"+id_product).innerHTML = `
                    <button class="btn btn-default mas_cola_producto" type="button" title="Añadir producto a cola" id="mas_cola_${id_product}" name="${id_product}">
                        <i class="icon-plus"></i> Cola
                    </button>
                `;

                //añadimos eventlistener a botón
                document.querySelector("#mas_cola_"+id_product).addEventListener('click', function() {
                    //enviamos name, que es el id_product, dentro de un array, a masColaProducto
                    masColaProducto(new Array(id_product)); 
                }); 

                //08/03/2024 Para indicar Redactado obtenemos el value del input hidden redactado_hidden_idproduct, cuyo valor 1 indica que esta descripción se ha generado ahora y no que estemos revisando una que ya estaba en el producto, sea o no de API
                var mostrar_redactado = "";
                if (document.querySelector("#redactado_hidden_"+id_product).value == 1) {
                    //el producto acaba de ser marcado como redactado. mostramos success
                    document.querySelector("#redactado_"+id_product).innerHTML = `<span class="badge badge-success">Si</span>`;

                    mostrar_redactado = `<span id="redactado_badge" class="badge badge-success" title="Este producto ya ha sido redactado">Redactado</span> 
                    <br>`;
                }
                
                //el producto ya está revisado (incluso si no se llamó a la API, revisado cuenta como Redactado) mostramos success
                document.querySelector("#revisado_"+id_product).innerHTML = `<span class="badge badge-success">Si</span>`;
                //habilitamos el checkbox en caso de no estarlo
                document.querySelector("#product_checkbox_"+id_product).checked = false;
                document.querySelector("#product_checkbox_"+id_product).disabled = false;            
                
                //el panel de  procesos lo dejamos con Redactado, según el caso, y Revisado
                var panel_info_procesos = `
                <div class="panel panel_producto panel_info_procesos">
                    ${mostrar_redactado}
                    <span id="revisado_badge" class="badge badge-success" title="Este producto ya ha sido revisado">Revisado</span>      
                </div>
                `;
                document.querySelector('#info_procesos').innerHTML = panel_info_procesos;          
                
                //habilitamos botones 
                document.querySelector('#boton_revisar_'+id_product).disabled = false;
                document.querySelector('#boton_generar_'+id_product).disabled = false;
                        
                showSuccessMessage(data.message);
        
                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()

            }
            else 
            {              
                //el panel de  procesos lo dejamos con Error Revisado
                var panel_info_procesos = `
                <div class="panel panel_producto panel_info_procesos">
                    <span id="revisado_badge" class="badge badge-danger" title="Error con el contenido a guardar">Error revisando producto</span> 
                    <br>                                            
                </div>
                `;
                document.querySelector('#info_procesos').innerHTML = panel_info_procesos; 

                //habilitamos botones 
                document.querySelector('#boton_revisar_'+id_product).disabled = false;
                document.querySelector('#boton_generar_'+id_product).disabled = false;

                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()

                showErrorMessage(data.message);
            }

        },
        error: function (jqXHR, textStatus, errorThrown)
        {
            showErrorMessage('ERRORS: ' + textStatus);
        }
    });  //fin ajax
}

//función que recoge los datos para enviar a la API, llama a esta y muestra el resultado
//30/12/2024 deber recoger la api seleccionada para enviarla también al controlador
function generaDescripcion(id_product) {
    console.log(id_product);

    let api_seleccionada_controlador = document.querySelector('input[name="switch_apis"]:checked').value;

    //recogemos valores del formulario destinado a la API, dependiendo de la api seleccionada, ya que openai no usa keywords ni tono. Tono recoge el value del select, que es la palabra en inglés como requiere la API. Keywords, si lleva algo, lo guardaremos como venga
    let nombre = document.querySelector("#input_nombre_api_"+id_product).value;    
    let descripcion = document.querySelector("#textarea_descripcion_api_"+id_product).value;
    let keywords = '';
    let tono = '';
    if (api_seleccionada_controlador == 'redactame') {
        keywords = document.querySelector("#input_keywords_api_"+id_product).value;
        tono = document.querySelector("#select_tono_api_"+id_product).value;

        //18/09/2024 redacta.me ha ampliado el límite de caracteres de la descripción de 500 a 5000
        if (descripcion.length > 5000) {
            showErrorMessage('La descripción a enviar a la API no puede tener más de 5000 caracteres');
            return;
        } else if (nombre.length > 50) {
            showErrorMessage('El nombre a enviar a la API no puede tener más de 50 caracteres');
            return;
        }
    }      

    //mostramos spinner
    spinnerOn();

    //sacamos panel procesando
    showPanelProcesando();

    //ponemos el badge de Procesando
    var esta_procesando = `
        <br>
        <span id="procesando_badge" class="badge badge-danger" title="Este producto está siendo procesado, los botones están deshabilitados. Espera y recarga para continuar">Atención - Procesando</span><br>        
        `;

    if (document.contains(document.querySelector('.panel_info_procesos'))) {        
        document.querySelector('div.panel.panel_info_procesos').innerHTML = document.querySelector('div.panel.panel_info_procesos').innerHTML+esta_procesando;
    } else {
        var panel_info_procesos = `
        <div class="panel panel_producto panel_info_procesos">
            ${esta_procesando}            
        </div>
        `;
        document.querySelector('#info_procesos').innerHTML = panel_info_procesos;
    }
    //deshabilitamos botones mientras tanto
    document.querySelector('#boton_revisar_'+id_product).disabled = true;
    document.querySelector('#boton_generar_'+id_product).disabled = true;

    var dataObj = {};
    dataObj['id_product'] = id_product;
    dataObj['nombre'] = nombre;    
    dataObj['descripcion'] = descripcion;
    dataObj['api_seleccionada'] = api_seleccionada_controlador;
    if (api_seleccionada_controlador == 'redactame') {
        dataObj['keywords'] = keywords;
        dataObj['tono'] = tono;
    }

    console.log(dataObj);
    //el token lo hemos sacado arriba del input hidden
    $.ajax({
        url: 'index.php?controller=AdminRedactorDescripciones' + '&token=' + token + "&action=generar_descripcion" + '&ajax=1' + '&rand=' + new Date().getTime(),
        type: 'POST',
        data: dataObj,
        cache: false,
        dataType: 'json',
        success: function (data, textStatus, jqXHR)
        
        {
            if (typeof data.error === 'undefined')
            {                                 
                console.dir(data);     
                
                //si recibimos correctamente la descripción generada desde la API, hay que actualizar los productos, quitar Procesando, y permitiendo que se puedan enviar de nuevo, tanto activando el checkbox como cambiando el botón Cola a Mas cola
                //08/03/2024 Cambiamos el value del input hidden redactado_hidden_idproduct para indicar que la descripción ha sido generada aquí si pulsamos en revisar. Redactado solo marcamos si pulsamos revisado, ya que si no pulsamos revisado y cerramos o mostramos otro producto, la descripción se pierde
                // console.log(data.id_producto_cola);
                document.querySelector("#boton_cola_"+id_product).innerHTML = `
                    <button class="btn btn-default mas_cola_producto" type="button" title="Añadir producto a cola" id="mas_cola_${id_product}" name="${id_product}">
                        <i class="icon-plus"></i> Cola
                    </button>
                `;

                //añadimos eventlistener a botón
                document.querySelector("#mas_cola_"+id_product).addEventListener('click', function() {
                    //enviamos name, que es el id_product, dentro de un array, a masColaProducto
                    masColaProducto(new Array(id_product)); 
                }); 

                //no cambiamos lo de Redactado y Revisado de la tabla ya que no está guardado, eso sucederá al pulsar revisado
                // //el producto ya está redactado  mostramos success
                // document.querySelector("#redactado_"+id_product).innerHTML = `<span class="badge badge-success">Si</span>`;
                // //el producto no está revisado ,mostramos warning
                // document.querySelector("#revisado_"+id_product).innerHTML = `<span class="badge badge-warning">No</span>`;
                //habilitamos el checkbox en caso de no estarlo
                document.querySelector("#product_checkbox_"+id_product).checked = false;
                document.querySelector("#product_checkbox_"+id_product).disabled = false;            
                
                //el panel de  procesos lo dejamos con Redactado
                var panel_info_procesos = `
                <div class="panel panel_producto panel_info_procesos">
                    <span id="redactado_badge" class="badge badge-warning" title="La descripción de este producto ha sido generada y se guardará al ser revisado">Descripción generada a espera de Revisar / guardar</span> 
                    <br>                        
                </div>
                `;
                document.querySelector('#info_procesos').innerHTML = panel_info_procesos;          
                
                //habilitamos botones 
                document.querySelector('#boton_revisar_'+id_product).disabled = false;
                document.querySelector('#boton_generar_'+id_product).disabled = false;

                //el contenido de la descripción generada lo ponemos en el textarea_descripcion_actual_producto, pero de hecho no está guardado en Prestashop hasta que no se pulse revisado, de modo que si vovlemos cargar el panel del producto aparecerá lo que haya en Prestashop
                document.querySelector("#textarea_descripcion_actual_producto_"+id_product).value = data.descripcion_api;
                //02/01/2025 Si hemos utilizado la api OpenAI puede que recibamos también el nombre para el producto, en ese caso lo metermos en el input de title
                if (data.titulo_producto !== undefined && data.titulo_producto != 0) {
                    document.querySelector("#input_nombre_api_"+id_product).value = data.titulo_producto;
                }

                //08/03/2024 Cambiamos value de input hidden redactado_hidden_idproduct que indica que se acaba de genrar la descripción con la api
                document.querySelector("#redactado_hidden_"+id_product).value = 1;

                //modificamos el texto sobre el textarea para indicar que no es la descripción actual del producto sino el retorno de la API y hay que revisar (guardar) para que se conserve. El texto se mete dentro del span id contenido_textarea
                document.querySelector("#contenido_textarea_"+id_product).innerHTML = `
                <span title="Descripción generada por la API para el producto, debes Revisar para guardarla" data-toggle="tooltip" class="label-tooltip" data-html="true">
                    Descripción generada por API - Revisa para guardar
                </span>
                `;

                showSuccessMessage(data.message);
        
                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()
            }
            else
            {              
                //el panel de  procesos lo dejamos con Error Generando
                var panel_info_procesos = `
                <div class="panel panel_producto panel_info_procesos">
                    <span id="revisado_badge" class="badge badge-danger" title="Error generando descripción con la API">Error generando descripción</span> 
                    <br>                                            
                </div>
                `;
                document.querySelector('#info_procesos').innerHTML = panel_info_procesos; 

                //habilitamos botones 
                document.querySelector('#boton_revisar_'+id_product).disabled = false;
                document.querySelector('#boton_generar_'+id_product).disabled = false;                

                showErrorMessage(data.message);

                //Insertamos el mensaje de respuesta de la API, sea el que sea, al comienzo de la descripción del textarea
                document.querySelector("#textarea_descripcion_actual_producto_"+id_product).value = data.error_message + "<br><br><br>" + document.querySelector("#textarea_descripcion_actual_producto_"+id_product).value;

                //eliminamos spinner
                spinnerOff();

                //escondemos panel procesando
                hidePanelProcesando()
            }

        },
        error: function (jqXHR, textStatus, errorThrown)
        {
            showErrorMessage('ERRORS: ' + textStatus);
        }
    });  //fin ajax
}

//cuenta en tiempo real el número de caracteres en Descripción y nombre para enviar a la api
//es llamada con "this" como argumento, this es el elemento que ha recibido el keyup, de modo que podemos sacar su id como su atributo para saber a qué elemento nos referimos
function cuentaCaracteres(arg) {
    // var num_caracteres = document.querySelector('#textarea_descripcion_api').value.length;
    var num_caracteres = arg.value.length;
    // +console.log('num_caracteres '+num_caracteres);

    var element_id = arg.getAttribute('id');
    // console.log('element_id '+element_id);

    //sacamos el id de producto de element_id buscando la última posición de _ y cortando desde ahí más 1
    var id_product = element_id.substring(element_id.lastIndexOf("_") + 1);

    var numero = "";

    //13/03/2024 Al añadir el id_product a los ids de todos los inputs y textareas ya no se puede buscar como 
    // if (element_id == 'textarea_descripcion_api') { o if (element_id == 'input_nombre_api') {
    // usamos función de javascript str.startsWith() AL FINAL HE TENIDO QUE SACAR EL id_product de element_id
    //if (element_id.startsWith('textarea_descripcion_api')) {
    //if (element_id.startsWith('input_nombre_api')) {
    //18/09/2024 redacta.me ha ampliado el límite de caracteres de la descripción de 500 a 5000

    if (element_id == 'textarea_descripcion_api_'+id_product) {
        if (num_caracteres < 4000) {
            numero = `<span class="badge badge-success">${num_caracteres}</span>`;
        } else if (num_caracteres > 5000) {
            numero = `<span class="badge badge-danger">${num_caracteres}</span>`;
        } else {
            numero = `<span class="badge badge-warning">${num_caracteres}</span>`;
        }
    
        document.querySelector('#caracteres_descripcion_api_'+id_product).innerHTML =  numero;
    }

    
    if (element_id == 'input_nombre_api_'+id_product) {
        if (num_caracteres < 42) {
            numero = `<span class="badge badge-success">${num_caracteres}</span>`;
        } else if (num_caracteres > 50) {
            numero = `<span class="badge badge-danger">${num_caracteres}</span>`;
        } else {
            numero = `<span class="badge badge-warning">${num_caracteres}</span>`;
        }
    
        document.querySelector('#caracteres_nombre_api_'+id_product).innerHTML = numero;
    }    
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

//función que muestra el div oculto para impedir al usuario tocar nada mientras en proceso
function showPanelProcesando() {
    document.getElementById("panel_procesando").style.display = "block";
}

//función que oculta el div oculto para impedir al usuario tocar nada mientras en proceso
function hidePanelProcesando() {
    document.getElementById("panel_procesando").style.display = "none";
}