<?php
/**
* 2007-2020 PrestaShop
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
*  @copyright 2007-2020 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

require_once(dirname(__FILE__).'/RedactorTools.php');
//07/07/2025 Añadimos Promptmanager del módulo openaiprompts para poder acceder a los prompts
require_once _PS_MODULE_DIR_.'openaiprompts/classes/PromptManager.php';


//30/12/2024 

//todas las funciones para trabajar con peticiones a la API de OpenAI (ChatGPT)
//30/06/2025 USAMOS CLASE OpenAIClasificador.php. Vamos a añadir el proceso de categorización de los productos. Los productos se crearán sin categorías ni tipo de producto, salvo lo necesario para no obtener error, y se añadirán a lista y se sacará su descripción con ayuda de la foto, con idiomas, el SEO, etc. Después, ya con la posibilidad de utilizar la descripción, se pasa a las categorías. Primero obtenemos la lista de categorías principales de producto y la lista de tipos de producto. Con esas listas y mediante el análisis del nombre y descripción del producto en prestashop (opcionalmente podríamos añadir la imagen) la IA asignará una categoría principal y un tipo de producto, así como una categoría de precio en función del precio del producto. Después, en función de la categoría principal se obtendrán el grupo de subcategorías de dicha categoría principal y el agente deberá asignarle las que encuentre más adecuadas de dicho subgrupo. Después se obtienen otras categorías principales de Regalar es fácil y el agente asignará una adecuada al producto. Finalmente se obtendrán las subcategorías de dicha categoría de regalar es fácil y el agente de nuevo asignará las que se adecúen al producto. Como a veces llegarán pedidos con alguna categoría asignada (Cerdá), lo que hacemos es dejarlas y si la IA saca más mejor.
class OpenAIRedactor
{   
    //función que recibe los parámetros para enviar a la API de OpenAI y llama a la función que ejecuta la petición con el json preparado.
    //en principio llamamos a esta función desde el controlador AdminRedactorDescripciones.php y desde ColaDescripciones.php. Añadimos el parámetro opcional $imagenes, que sería un array con urls, para cuando utilicemos esta función desde el creador de productos, que pueda traer las imágenes de los proveedores, aunque para poder utilizar el id_product el producto ya debe existir. Se podrá utilizar de otra manera
    //07/01/2025 Como por ahora no puedo llamar a la api con urls sino con base64, pongo un parámetro en la función $base64 = true por defecto, para quitarlo facilmente si lo soluciono 
    //22/05/2025 Vamos a dejar de usar Deepl para traducciones y pedimos a OpenAI la traducción en la propia petición de la descripción.
    public static function apiOpenAISolicitudDescripcion($parametros, $imagenes = null, $base64 = true) {
        $id_product = $parametros["id_product"];
        $api_title = $parametros["title"];
        $api_description = $parametros["description"]; 
        //23/05/2025 buscaremos la categoría principal del producto y su url
        //15/07/2025 Esta parte se hace al clasificar el producto y obtener la categoría principal
        // $api_categoria = OpenAIRedactor::getCategoria($id_product);     
        // if (!$api_categoria || $api_categoria == '') {
        //     $error_message = 'Error, no obtenido categoría principal de producto ni url de categoría.';

        //     RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
            
        //     return array(
        //         "result" => 0,
        //         "message" => pSQL($error_message)
        //     );
        // }           

        //hemos guardado con la configuración del módulo el system role context en una variable llamada REDACTOR_OPENAI_SYSTEM_ROLE_CONTEXT
        // $system_role_context = "Eres un redactor experto en SEO especializado en productos de merchandising. Tu tarea es escribir descripciones persuasivas y atractivas para regalos coleccionables y productos geek, evitando términos que puedan tener connotaciones negativas en España, como 'fanático' o 'friki'. También describirás productos como camisetas, zapatillas, bolsos y otros complementos, a menudo relacionados con elementos populares como superheroes, dibujos animados, personajes de televisión y cine, etc. Prioriza destacar detalles clave del producto, como materiales, uso, características especiales y lo que lo hace único. Siempre optimiza los textos para buscadores, empleando palabras clave relevantes y generando títulos llamativos y efectivos para SEO. Puedes usar imágenes del producto y cualquier dato adicional proporcionado para crear contenido perfectamente adaptado a la marca y al público objetivo. Sé profesional, creativo y enfócate en captar la atención del lector. Por favor no metas en el texto emojis para evitar errores. Ofrecerás la respuesta con formato html válido para insertar en una base de datos, sin usar saltos de línea (n). Las etiquetas html permitidas son strong, h1, h2, br, p, ul, li, i. Aplicarás la etiqueta de negrita a las palabras clave, las cuales no incluirás por separado en tu descripción. Separarás en los párrafos necesarios para facilitar la lectura. Muy importante, lo primero que harás será analizar las imágenes recibidas y generar una descripción muy detallada del producto que muestran, mencionando formas, colores y otros rasgos relevantes. A dicha descripción puedes añadirle los datos adicionales que recibirás del rol de usuario y que pueden consistir en marcas, tamaños, fabricantes, materiales o cualquier otra información interesante, pero lo más importante es describir las imágenes recibidas como si el cliente no pudiera ver. Otro punto a destacar es que deberás orientar la descripción del producto al cliente potencial. Por ejemplo, si el producto a describir es un producto orientado a los niños, como juguetes o ropa de niño o bebé, la descripción y el SEO ira orientado a convencer a las madres de los niños. Si el producto es una figura de colección la descripción deberá ir dirigida a un cliente coleccionista. No confundas juguetes con figuras de colección. Si el producto tiene matices de índole erótica o sexual nunca dirijas la descripción a un cliente potencial infantil ya que se trata de un producto para adultos o coleccionistas. Puedes hablar de como el producto mejorará la vida del cliente. **Importante: La descripción deberá tener entre 300 y 500 palabras**. El título o nombre de producto tendrá una longitud de máximo alrededor de 90 caracteres y será una optimización SEO de los datos disponibles que describa con claridad el tipo de producto, personaje, serie, material etc para poder identificarlo facilmente. Una vez generada la descripción y con la información de que dispones generarás un metatítulo y una metadescripción adecuadas al producto, sin excesos en su longitud. Por último, recogerás el enlace y el nombre de categoría administrado en el segundo parámetro 'text' recibido y generarás otra descripción que incite al cliente a visitar toda la categoría  con un enlace incluído. Dicho enlace se abrirá en una nueva pestaña del navegador, no en la actual. Importante: una vez creada la descripción y el resto de campos, prepararás los mismos textos con el mismo formato, manteniendo párrafos, estructura y html, traducido al inglés, al portugués y al francés. La respuesta debe estar en formato JSON válido, sin encerrarlo en bloques de código ni añadir backticks, y contener 4 objetos, uno por cada idioma: Cada idioma debe incluir un objeto con 'language' ( 'es', 'en', 'fr' y 'pt'. Para cada traducción), 'title' (el nombre del producto), 'description_short' (el texto descriptivo en HTML), 'description' (la descripción para la categoría del producto), 'meta_title' (el metatítulo) y 'meta_description' (la metadescripción). No repitas el título dentro de la descripción. Usa etiquetas HTML solo en la descripción del producto y en la descripción para la categoría y no utilices markdown, no insertes asteriscos (*), almohadillas, especialmente en el nombre (#). No envuelvas la salida en bloques de código ni uses comillas escapadas.";

        // 30/05/2025 otra versión
        // $system_role_context = "Eres un redactor experto en SEO especializado en productos de merchandising y coleccionismo. Tu tarea es crear descripciones persuasivas, detalladas y atractivas para productos como figuras de colección, camisetas, zapatillas, bolsos, accesorios, juguetes, ropa para niños o bebés, y otros artículos geek o de cultura pop. Estas descripciones obligatoriamente tendrán un mínimo de 2000-2400 caracteres. Siempre analiza primero las imágenes recibidas y genera una descripción detallada, explicando formas, colores, materiales, texturas, tamaño y cualquier detalle visual relevante, como si el cliente no pudiera verlas. La descripción de las imágenes es la parte más importante de tus funciones. A esa descripción le añadirás los datos adicionales proporcionados (nombre del producto, marca, tamaño, fabricante, materiales, etc).  La descripción debe tener un mínimo obligatorio de 2000-2400 caracteres. Es fundamental que cumplas este requisito. Si no puedes generar 2000-2400 caracteres, genera el máximo contenido posible, pero intenta siempre llegar a 2000 caracteres como mínimo. La descripción estará escrita en HTML válido para insertar en una base de datos, y debe estar optimizada para SEO. Usa palabras clave relevantes y negrita (<strong>) en ellas, pero no incluyas una lista de palabras clave separada. Evita términos con connotaciones negativas en España, como 'fanático' o 'friki'. Si el producto es para niños (ropa, juguetes, accesorios...), orienta la descripción a las madres o padres. Si es una figura coleccionable, orienta la descripción al coleccionista. Si es un producto para adultos, como artículos eróticos, indícalo claramente y no lo orientes a un público infantil. Además de la descripción principal, crea un título SEO de máximo 90 caracteres, que incluya el tipo de producto, personaje, serie, material, etc. No incluyas de nuevo el título en la descripción.; un metatítulo de máximo 60 caracteres, descriptivo y claro; una metadescripción de máximo 160 caracteres, atractiva y clara; y una descripción para la categoría basada en el enlace y la categoría recibida, que invite al cliente a explorar más productos de esa categoría e incluya un enlace <a href='url' target='_blank'> que se abra en una nueva pestaña. Devuelve todo el contenido en JSON válido, con los idiomas: es (español), en (inglés), pt (portugués) y fr (francés). Cada idioma tendrá un objeto con: language, title, description_short (en HTML, con etiquetas <strong>, <h1>, <h2>, <p>, <br>, <ul>, <li>, <i> según sea necesario), description (la descripción de la categoría, también en HTML), meta_title y meta_description. No envuelvas la salida en bloques de código ni uses comillas escapadas. No incluyas asteriscos, almohadillas ni markdown. Muy importante: La descripción del producto debe ser visual, detallada y atractiva, adaptada al cliente objetivo, destacando cómo el producto mejora su vida. Antes de terminar, comprueba de nuevo la longitud del texto. La descripción principal debe tener al menos 2000 caracteres: es obligatorio. Si no puedes alcanzar esa longitud, explica más detalles, profundiza en las características, ejemplos de uso, ventajas, o cualquier información que ayude a ampliar el contenido. Recuerda: la descripción debe tener al menos 2000 caracteres.";

        // $system_role_context = Configuration::get('REDACTOR_OPENAI_SYSTEM_ROLE_CONTEXT');
        //07/07/2025 accedemos al prompt para descripciones almacenado en módulo openaiprompts
        $prompt_data = PromptManager::obtenerPrompt('productos', 'descripcion');

        $system_role_context = $prompt_data['prompt'];
        $model = $prompt_data['modelo'];
        $temperature = (float)$prompt_data['temperature'];
        $max_tokens = (int)$prompt_data['max_tokens'];

        if (!$system_role_context || $system_role_context == '') {
            $error_message = 'Error, no obtenido contexto para rol de sistema para OpenAI';

            RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
            
            return array(
                "result" => 0,
                "message" => pSQL($error_message)
            );
        }

        // $user_prompt = "Describe el producto de las fotos para un comercio online. Más información del producto: ".$api_description;
        $user_prompt = $api_title.' '.$api_description;

        //preparamos parámetros POST en json para la api       
        $array_user = array();

        $array_user[] = array(
            "type" => "text",
            "text" => $user_prompt
        );

        //22/05/2025 preparamos un segundo "text" donde metemos el nombre y categoría principal del producto  
        //15/07/2025 Esta parte se hace al clasificar el producto y obtener la categoría principal
        // $array_user[] = array(
        //     "type" => "text",
        //     "text" => $api_categoria
        // );

        //para guardar la url en el log lo que hacemos es almacenarla en un duplicado de $array_user, $array_user_duplicado, utilizaremos el original para la api, y el otro para el insert a base de datos. En el duplicado guardaremos la url, en el original irá base64 (o la url si se configurara así)
        $array_user_duplicado = array();

        $array_user_duplicado[] = array(
            "type" => "text",
            "text" => $user_prompt
        );

        // $array_user_duplicado[] = array(
        //     "type" => "text",
        //     "text" => $api_categoria
        // );
      
        //obtenemos hasta 6 imágenes del producto
        //05/01/2025 Por algún motivo las url no las puede leer gpt directamente de nuestro servidor (son públicas) mientras averiguamos si es un problema de permisos o CDN, etc ya que en test si que puede, lo que hacemos es utilizar las url para descargarlas con file_get_content y luego convertirlas a base64, lo cual si que lee. Se envía como si fuera la url
        if ($imagenes !== null) {
            foreach ($imagenes AS $imagen) {

                if ($base64) {
                    if (($imagen_api = OpenAIRedactor::urlToBase64($imagen)) == false) {
                        $error_message = 'Error convirtiendo imágenes a base64 para producto id_product '.$id_product;
    
                        RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
                        
                        return array(
                            "result" => 0,
                            "message" => pSQL($error_message)
                        );
                    }
                } else {
                    $imagen_api = $imagen;
                }
                

                $array_user[] = array(
                    "type" => "image_url",
                    "image_url" => array(
                        // "url" => "https://".$image_url
                        "url" => $imagen_api
                    ),
                );

                //aquí alamcenamos la url para el insert
                $array_user_duplicado[] = array(
                    "type" => "image_url",
                    "image_url" => array(                        
                        "url" => $imagen
                    ),
                );
            }
        } else {
            //no vienen imágenes en los parámetros, buscamos las del producto, hasta 6, para ello sacamos de lafrips_images hasta 6 id_image
            $sql_images = "SELECT id_image FROM lafrips_image WHERE id_product = $id_product ORDER BY cover DESC LIMIT 6";

            $images = Db::getInstance()->executeS($sql_images);   

            if (count($images) < 1) {
                $error_message = 'Error, no obtenidas imágenes para producto id_product '.$id_product;

                RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
                
                return array(
                    "result" => 0,
                    "message" => pSQL($error_message)
                );
            } else {
                foreach ($images AS $image) {
                    $id_image = $image['id_image'];                    							
						
                    $product = new Product((int)$id_product, false, 1, 1);
                                        
                    //imagen		
                    $image_link = new Link;//because getImageLInk is not static function
                    //el link obtenido no lleva http así que lo añadimos
                    $image_url = "https://".$image_link->getImageLink($product->link_rewrite, $id_image, 'thickbox_default');

                    //pasamos a base64 si es necesario. Para guardar la url en el log lo que hacemos es almacenarla en un duplicado de $array_user, $array_user_duplicado, utilizaremos el original para la api, y el otro para el insert a base de datos
                    if ($base64) {
                        if (($imagen_api = OpenAIRedactor::urlToBase64($image_url)) == false) {
                            $error_message = 'Error convirtiendo imágenes a base64 para producto id_product '.$id_product;

                            RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
                            
                            return array(
                                "result" => 0,
                                "message" => pSQL($error_message)
                            );
                        }
                    } else {
                        $imagen_api = $image_url;
                    }                    
    
                    $array_user[] = array(
                        "type" => "image_url",
                        "image_url" => array(
                            // "url" => "https://".$image_url
                            "url" => $imagen_api
                        ),
                    );    
                    
                    //aquí alamcenamos la url para el insert
                    $array_user_duplicado[] = array(
                        "type" => "image_url",
                        "image_url" => array(                        
                            "url" => $image_url
                        ),
                    );
                }
            }
        }     

        //obtenemos los valores de modelo, max_tokens y temperature de la tabla de configuración, con valores por defecto si no hubiera
        //YA NO, ahora se usa modulo openaiprompts
        // $model = Configuration::get('REDACTOR_OPENAI_MODEL', 'gpt-4o');
        // $max_tokens = (int)Configuration::get('REDACTOR_OPENAI_MAX_TOKENS', 2000);
        // $temperature = (float)Configuration::get('REDACTOR_OPENAI_TEMPERATURE', 0.7);

        $array = array(
            "model" => $model,
            "messages" => array(
                array(
                    "role" => "system",
                    "content" => $system_role_context
                ),
                array(
                    "role" => "user",
                    "content" => $array_user
                )
            ),
            "max_tokens" => $max_tokens,
            "temperature" => $temperature
        ); 

        $array_json = json_encode($array);

        //para guardar el json en la base de datos, si hemos convertido a base64 no queremos guardar ese código, pondremos "imagen convertida base64" antes de codificar a json
        // if ($base64) {
        //     if (isset($array['messages'])) {
        //         foreach ($array['messages'] as &$message) {
        //             if ($message['role'] === 'user' && isset($message['content'])) {
        //                 foreach ($message['content'] as &$content) {
        //                     if ($content['type'] === 'image_url' && isset($content['image_url']['url'])) {
        //                         // Reemplazar el contenido por un texto más simple
        //                         $content['image_url']['url'] = 'imagen convertida a base64';
        //                     }
        //                 }
        //             }
        //         }
        //     }

        //     $array_json_base64 = json_encode($array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        //     $array_json_insert = pSQL($array_json_base64);
        // } else {
        //     $array_json_insert = pSQL($array_json);
        // }        

        //para insertar en tabla con la url usamos el array user duplicado
        $array_duplicado = array(
            "model" => $model,
            "messages" => array(
                array(
                    "role" => "system",
                    "content" => $system_role_context
                ),
                array(
                    "role" => "user",
                    "content" => $array_user_duplicado
                )
            ),
            "max_tokens" => $max_tokens,
            "temperature" => $temperature
        ); 

        $array_json_insert = pSQL(json_encode($array_duplicado));

        //marcamos la tabla redactor_descripcion como procesando
        if (!$id_employee = Context::getContext()->employee->id) {
            $id_employee = 44;
        }
        //insertamos fecha y empleado de redactar y el json de envío post a la API en lafrips_redactor_descripcion
        //11/03/2024 Solo guardaremos el json si api_json en lafrips_redactor_descripcion está vacío, es decir, la primera vez, dado que a partir de ahí, una vez generada una descripción, el producto en su campo description_short puede tener un texto grande que pisará (cortado a 500 caracteres) lo que haya en el campo        
        //28/05/2025 a partir de ahora guardamos de nuevo api_json para cada llamada, pero tenemos otro campo, info_para_api donde irá el texto que pasamos para la api
        // $sql_redactando = "UPDATE lafrips_redactor_descripcion
        // SET 
        // api = 'openai',                
        // procesando = 1,
        // inicio_proceso = NOW(),
        // id_employee_redactado = $id_employee,                            
        // date_upd = NOW(),
        // api_json = 
        //     CASE
        //         WHEN api_json IS NULL OR api_json = '' THEN '$array_json_insert'
        //         ELSE api_json
        //     END 
        // WHERE id_product = $id_product";

        $api_description = pSQL($api_description);

        $sql_redactando = "UPDATE lafrips_redactor_descripcion
        SET 
        api = 'openai',                
        procesando = 1,
        inicio_proceso = NOW(),
        id_employee_redactado = $id_employee,                            
        date_upd = NOW(),
        api_json = '$array_json_insert',
        info_para_api = '$api_description'
        WHERE id_product = $id_product";

        // echo $sql_redactando;

        Db::getInstance()->execute($sql_redactando);               
        
        $description = OpenAIRedactor::apiCallDescription($array_json, $id_product);
        //si hubo error generando descripción devolvemos el error a donde hallamos llamado a esta función
        if (!$description["result"]) {
            return $description;
        }

        //22/05/2025 Vamos a modificar todo para que OpenAI nos devuelva la descripción del producto, titulo, metatitulo, metadescripcion, descripción larga para invitar a navegar categoría y además las traducciones a inglés, francés y portugués. En un formato tal que:
            /*

            {
            "es": {
                "title": "Título del producto en español",
                "description_short": "Descripción completa en español en HTML"
                "description": "Descripción para categoría de producto"
                "meta_title": "Metatítulo en español"
                "meta_description": "Meta Descripción en español"
            },
            "en": {
                "title": "Product title in English",
                "description_short": "Full product description in English in HTML",
                "description": "Descripción para categoría de producto"
                "meta_title": "Metatítulo en inglés"
                "meta_description": "Meta Descripción en inglés"
            },
            "fr": {
                "title": "Titre du produit en français",
                "description_short": "Description complète en français en HTML",
                "description": "Descripción para categoría de producto"
                "meta_title": "Metatítulo en français"
                "meta_description": "Meta Descripción en français"
            },
            "pt": {
                "title": "Título do produto em português",
                "description_short": "Descrição completa em português em HTML",
                "description": "Descripción para categoría de producto"
                "meta_title": "Metatítulo en português"
                "meta_description": "Meta Descripción en português"
            }
            }

        */

        ////la API devuelve el texto generado en párrafos con saltos de línea de tipo \n
        // $description["message"] = OpenAIRedactor::limpiaTexto($description["message"]);        
        
        //la api genera la descripción con un título entre <h1> que podríamos utilizar para nuestro producto como nombre. Comprobamos si existe dicho título y si es así lo devolveremos como parámetro
        // if (($titulo = OpenAIRedactor::sacaTitulo($description["message"])) != null) {
        //     //sacaTitulo() devuelve un array con el título primero y el resto de la descripción después.
        //     $description['titulo'] = $titulo[0];
        //     $description["message"] = $titulo[1];
        // }

        //devolvemos $description, que puede o no llevar 'titulo'
        return $description;
    }

    //23/05/2025 por ahora, devuelve la categoría principal y su enlace en la web para el id_product, en formato Categoría: Manga. Enlace: https://lafrikileria.com/es/5-regalos-manga
    public static function getCategoria($id_product) {
        $sql_categoria = "SELECT cla.name AS categoria, CONCAT('https://lafrikileria.com/es/', pro.id_category_default, '-', cla.link_rewrite) AS url
            FROM lafrips_category_lang cla
            JOIN lafrips_product pro ON pro.id_category_default = cla.id_category
            WHERE cla.id_lang = 1
            AND pro.id_product = $id_product";

        $categoria = Db::getInstance()->getRow($sql_categoria); 

        if ($categoria) {
            return 'Categoria: '.$categoria['categoria'].' . Url: '.$categoria['url'];
        } else {
            return false;
        }
    }

    //función para convertir imágenes en base64
    public static function urlToBase64($url) {
        $imageData = file_get_contents($url);  // Descarga la imagen
        if ($imageData === false) {
            return false;
        }
        $base64 = base64_encode($imageData);  // Codifica a base64
        $mimeType = getimagesizefromstring($imageData)['mime'];  // Obtiene el tipo MIME
        return "data:$mimeType;base64,$base64";  // Formato final
    }

    public static function apiCallDescription ($post_fields, $id_product) {
        $api_key = OpenAIRedactor::getApiKey();

        // $array = array(
        //     "model" => "gpt-4o",
        //     "messages" => array(
        //         array(
        //             "role" => "system",
        //             "content" => "Eres un redactor experto en SEO especializado en productos de merchandising. Tu tarea es escribir descripciones persuasivas y atractivas para regalos coleccionables y productos geek, evitando términos que puedan tener connotaciones negativas en España, como 'fanático' o 'friki'. También describirás productos como camisetas, zapatillas, bolsos y otros complementos, a menudo relacionados con elementos populares como superheroes, dibujos animados, personajes de televisión y cine, etc. Prioriza destacar detalles clave del producto, como materiales, uso, características especiales y lo que lo hace único. Siempre optimiza los textos para buscadores, empleando palabras clave relevantes y generando títulos llamativos y efectivos para SEO. Puedes usar imágenes del producto y cualquier dato adicional proporcionado para crear contenido perfectamente adaptado a la marca y al público objetivo. Sé profesional, creativo y enfócate en captar la atención del lector. Por favor no metas en el texto emojis para evitar errores. Ofrecerás la respuesta con formato html válido para insertar en una base de datos, sin usar saltos de línea (n). Las etiquetas html permitidas son strong, h1, h2, br, p, ul, li, i. Aplicarás la etiqueta de negrita a las palabras clave, las cuales no incluirás por separado en tu descripción. Muy importante, lo primero que harás será analizar las imágenes recibidas y generar una descripción muy detallada del producto que muestran, mencionando formas, colores y otros rasgos relevantes. A dicha descripción puedes añadirle los datos adicionales que recibirás del rol de usuario y que pueden consistir en marcas, tamaños, fabricantes, materiales o cualquier otra información interesante, pero lo más importante es describir las imágenes recibidas como si el cliente no pudiera ver. Otro punto a destacar es que deberás orientar la descripción del producto al cliente potencial. Por ejemplo, si el producto a describir es un producto orientado a los niños, como juguetes o ropa de niño o bebé, la descripción y el SEO ira orientado a convencer a las madres de los niños. Si el producto es una figura de colección la descripción deberá ir dirigida a un cliente coleccionista. No confundas juguetes con figuras de colección. Si el producto tiene matices de índole erótica o sexual nunca dirijas la descripción a un cliente potencial infantil ya que se trata de un producto para adultos o coleccionistas. Muy importante, recuerda usar siempre las etiquetas HTML permitidas, no markdown. Estamos detectando que quedan restos de markdown visibles en el texto y eso es muy molesto."
        //         ),
        //         array(
        //             "role" => "user",
        //             "content" => array(
        //                 array(
        //                     "type" => "text",
        //                     "text" => "fabricante The Noble Collection, basada en película El Señor de los Anillos., personaje Sauron.
        //                         Serie de figuras BendyFigs,unos 19cm de altura, artículo de colección o de regalo"
        //                  ),
                        // {
                        //     "type": "text",
                        //     "text": "Categoría: Manga. Enlace: 'https://lafrikileria.com/es/5-regalos-manga'"
                        // },
        //                  array(
        //                     "type" => "image_url",
        //                     "image_url" => array(
        //                         "url" => "https://lafrikileria.com/test/83905-thickbox_default/figura-bendyfigs-sauron-el-senor-de-los-anillos-19-cm.jpg"
        //                     ),
        //                  )
        //             )
        //         )
        //     ),
        //     "max_tokens" => 1000,
        //     "temperature" => 0.7
        // );

        // $post_fields = json_encode($array);

    
        $curl = curl_init();
    
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 100, //subimos de 70
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer '.$api_key
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

            $error_message = 'Error haciendo petición a API OpenAI para Descripción - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));        
            
            return array(
                "result" => 0,
                "message" => pSQL($error_message)
            );
            
        }
        
        if ($response) {

            // $curl_info = curl_getinfo($curl);

            // $connect_time = $curl_info['connect_time'];
            // $total_time = $curl_info['total_time'];

            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);     

            //pasamos el JSON de respuesta a un array PHP. 
            $response_decode = json_decode($response, true); 
        
            // print_r($response_decode);

            //si el código http no es 200 lo damos por error, la respuesta debe contener un array "error" con 4 posibles parámetros, "message" , "type", "param" y "code"
            if ($http_code != 200) {                
                $error_message = "Error: http code = $http_code - ";

                if ($response_decode['error']['message']) {
                    $error_message .= " - ".$response_decode['error']['message'];
                }

                if ($response_decode['error']['type']) {
                    $error_message .= " - ".$response_decode['error']['type'];
                }

                if ($response_decode['error']['param']) {
                    $error_message .= " - ".$response_decode['error']['param'];
                }

                if ($response_decode['error']['code']) {
                    $error_message .= " - ".$response_decode['error']['code'];
                }

                RedactorTools::updateTablaRedactorRedactado(0, $id_product, pSQL($error_message));
                
                return array(
                    "result" => 0,
                    "message" => pSQL($error_message)
                    // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );
            }
        
            //la respuesta debería ser correcta, tiene formato blabla que contiene "choices" (solo pedimos un texto luego será el primero), dentro message, y el role assistan tiene como content el texto resultado
            //22/05/2025 Al pedir traducción y otros parámetros, recibimos un json anidado en content, por tanto hay que hacer un segundo jsondecode
            $content_json = $response_decode['choices'][0]['message']['content'];

            // file_put_contents(__DIR__ . "/../log/content_json.txt", print_r($content_json, true), FILE_APPEND);
            file_put_contents(__DIR__ . "/../log/response_decode.txt", date("Y-m-d H:i:s")." Producto $id_product", FILE_APPEND);
            file_put_contents(__DIR__ . "/../log/response_decode.txt", print_r($response_decode, true), FILE_APPEND);
    
            if ($content_json && !is_null($content_json) && !empty($content_json)) {
                // Redactame::updateTablaRedactor(1, $id_product); Mientras hagamos negritas, aún no podemos considerarlo redactado                

                // Limpiar bloques tipo ```json ... ```
                if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $content_json, $matches)) {
                    $content_json = $matches[1];
                }

                $content = json_decode($content_json, true);                   
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($content)) {
                    return array(
                        "result" => 1,
                        "message" => $content                    
                    );
                } else {
                    $error_message = "Error al decodificar el contenido interno JSON.";

                    RedactorTools::updateTablaRedactorRedactado(0, $id_product, $error_message.' : '.pSQL($content_json));
                    
                    return array(
                        "result" => 0,
                        "message" => $error_message
                        // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                    );
                }

            } else {
                $error_message = "Error, respuesta API de Descripción vacía";

                RedactorTools::updateTablaRedactorRedactado(0, $id_product, $error_message);
                
                return array(
                    "result" => 0,
                    "message" => $error_message
                    // "curl_info" => "Connect time= ".$connect_time." - Total time= ".$total_time
                );
            }
        
        } else {
            //no hay respuesta pero cerramos igualmente
            curl_close($curl);

            $error_message = "Error, la API no responde a la petición de Descripción";

            RedactorTools::updateTablaRedactorRedactado(0, $id_product, $error_message);    
            
            return array(
                "result" => 0,
                "message" => $error_message
            );
        }  
    }
        

    //función que elimina los caracteres que vayamos poniendo en un array $eliminar    
    public static function limpiaTexto($description) {
        $array_eliminar = array('\n', '`', 'html');

        foreach ($array_eliminar AS $eliminar) {
            $description = str_replace($eliminar, "", $description);
        }

        return $description;
    }

    //función que comprueba si la primera línea del texto recibido es un titular y si es así lo separa del resto devolviendo un array con título y descripción, o null
    //YA NO SE USA
    public static function sacaTitulo($description) {
        // Expresión regular para buscar el <h1> al principio del texto, no busca más allá de la segunda línea
        $pattern = '/^\s*<h1>(.*?)<\/h1>\s*(?:\r?\n){0,2}/i';
        
        if (preg_match($pattern, $description, $matches)) {
            // $matches[1] contiene el texto dentro del <h1>
            $titular = strip_tags($matches[1]);
            
            // Eliminar el <h1> y la línea en blanco del texto original. Solo elimina la primera ocurrencia de <h1> 
            $resto_texto = preg_replace($pattern, '', $description, 1);

            return array($titular, $resto_texto);                
        }
        
        // Si no hay <h1> devolver null
        return null;
    }

    public static function getApiKey() {
        //Obtenemos la key leyendo el archivo api_openai.json donde hemos almacenado la contraseña para la API
        $secrets_json = file_get_contents(dirname(__FILE__).'/../secrets/api_openai.json');
        
        $secrets = json_decode($secrets_json, true);

        //devolvemos la Api Key
        return $secrets['api_key'];
    }        
}
