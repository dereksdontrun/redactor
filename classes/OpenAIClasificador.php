<?php
//30/06/2025 Vamos a añadir el proceso de categorización de los productos. Los productos se crearán sin categorías ni tipo de producto, salvo lo necesario para no obtener error, y se añadirán a lista y se sacará su descripción con ayuda de la foto, con idiomas, el SEO, etc. Después, ya con la posibilidad de utilizar la descripción, se pasa a las categorías. Primero obtenemos la lista de categorías principales de producto y la lista de tipos de producto. Con esas listas y mediante el análisis del nombre y descripción del producto en prestashop (opcionalmente podríamos añadir la imagen) la IA asignará una categoría principal y un tipo de producto, así como una categoría de precio en función del precio del producto. Después, en función de la categoría principal se obtendrán el grupo de subcategorías de dicha categoría principal y el agente deberá asignarle las que encuentre más adecuadas de dicho subgrupo. Después se obtienen otras categorías principales de Regalar es fácil y el agente asignará una adecuada al producto. Finalmente se obtendrán las subcategorías de dicha categoría de regalar es fácil y el agente de nuevo asignará las que se adecúen al producto. Como a veces llegarán pedidos con alguna categoría asignada (Cerdá), lo que hacemos es dejarlas y si la IA saca más mejor.

//tendré que sacar la categoría principal para luego poder hacer la descripción larga que la necesita, de modo que ese proceso hay que sacarlo de el de descripción.
//La idea es que obtener la descripción de producto, con sus idiomas, y la parte del SEO (salvo descripción larga, que necesita de la categoría principal) sea el proceso de redacción, y que una vez terminado, el proceso añada al producto a la cola de categorización, de modo que este sea un proceso continuo pero separado, y exista la posibilidad de solo categorizar un producto. Lo suyo sería poder hacer solo redacción también

require_once _PS_MODULE_DIR_ . 'openaiprompts/classes/PromptManager.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';

class OpenAIClasificador
{
    private static $base_url = 'https://api.openai.com/v1/chat/completions';

    private static $logger = null;

    /**
     * Asigna un logger global para registrar errores desde las llamadas de OpenAI.
     *
     * @param LoggerFrik $logger
     */
    public static function setLogger(LoggerFrik $logger)
    {
        self::$logger = $logger;
    }

    private static function log(string $mensaje, string $tipo = 'INFO')
    {
        if (self::$logger) {
            self::$logger->log($mensaje, $tipo);
        }
    }

    public static function getApiKey()
    {
        $secrets_json = file_get_contents(dirname(__FILE__) . '/../secrets/api_openai.json');
        $secrets = json_decode($secrets_json, true);
        return $secrets['api_key'];
    }

    /**
     * Realiza una llamada a la API de OpenAI utilizando un prompt personalizado almacenado en la base de datos,
     * con posibilidad de sustituir variables y registrar logs de error.
     *
     * @param string $grupo Grupo del prompt (ej. 'productos').
     * @param string $nombre Nombre del prompt dentro del grupo (ej. 'clasificacion_cat_principal_precio_tipo_target').
     * @param string $user_context Contenido del mensaje 'user' que acompaña al prompt.
     * @param array $variables Variables a reemplazar en el prompt del sistema (clave => valor).
     *
     * @return array Resultado interpretado del modelo o array con 'error'.
     */
    private static function apiCallDesdePrompt($grupo, $nombre, $user_context, $variables = [])
    {
        // 1. Obtener prompt guardado
        $prompt_data = PromptManager::obtenerPrompt($grupo, $nombre);

        if (!$prompt_data || empty($prompt_data['prompt'])) {
            self::log("Prompt '{$nombre}' no encontrado en el grupo '{$grupo}'", 'ERROR');
            return ['error' => "No se encontró el prompt '{$nombre}' en el grupo '{$grupo}'"];
        }

        // 2. Sustituir variables dinámicas
        $system_prompt = $prompt_data['prompt'];
        foreach ($variables as $key => $value) {
            $system_prompt = str_replace('{{' . $key . '}}', $value, $system_prompt);
        }

        // 3. Preparar headers y datos
        $headers = [
            "Authorization: Bearer " . self::getApiKey(),
            "Content-Type: application/json"
        ];

        $isNewModel = strpos($prompt_data['modelo'], 'gpt-5') === 0;

        $postData = [
            "model" => $prompt_data['modelo'],
            "temperature" => (float) $prompt_data['temperature'],
            "messages" => [
                ["role" => "system", "content" => $system_prompt],
                ["role" => "user", "content" => $user_context]
            ]
        ];

        if ($isNewModel) {
            $postData["max_completion_tokens"] = (int) $prompt_data['max_tokens'];
        } else {
            $postData["max_tokens"] = (int) $prompt_data['max_tokens'];
        }

        // $postData = [
        //     "model" => $prompt_data['modelo'],
        //     "temperature" => (float)$prompt_data['temperature'],
        //     "max_tokens" => (int)$prompt_data['max_tokens'],
        //     "messages" => [
        //         ["role" => "system", "content" => $system_prompt],
        //         ["role" => "user", "content" => $user_context]
        //     ]
        // ];

        // self::log("Enviando a OpenAI (prompt: $nombre): " . json_encode($postData), 'DEBUG');

        // 4. Ejecutar llamada cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::$base_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // timeout de seguridad

        $response = curl_exec($ch);

        // self::log("Respuesta de OpenAI (prompt: $nombre): " . $response, 'DEBUG');

        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            self::log("cURL error en '{$nombre}': {$error_msg}", 'ERROR');
            return ['error' => "Error de cURL: {$error_msg}"];
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            self::log("Respuesta HTTP {$http_code} en '{$nombre}': {$response}", 'ERROR');
            return ['error' => "Error HTTP {$http_code}. Respuesta: {$response}"];
        }

        if (!$response) {
            self::log("Respuesta vacía de OpenAI para prompt '{$nombre}'", 'ERROR');
            return ['error' => "Respuesta vacía de la API"];
        }

        // 5. Interpretar respuesta
        return self::interpretar($response);
    }

    /**
     * Interpreta la respuesta JSON devuelta por OpenAI.
     *
     * @param string $json Respuesta JSON cruda devuelta por curl_exec.
     *
     * @return array Array con los datos extraídos del mensaje o con 'error' si falló.
     */
    private static function interpretar($json)
    {
        $data = json_decode($json, true);

        if (isset($data['choices'][0]['message']['content'])) {
            $content = trim($data['choices'][0]['message']['content']);

            // Limpiar bloques tipo ```json ... ```
            if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $content, $matches)) {
                $content = $matches[1];
            }

            $decodificado = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodificado)) {
                // Decodificar entidades HTML en cada valor del array (recursivamente) (<a> lo enviaba a veces escapado como &lt;a&gt; lo que hace que se visualice <> pero sin ser html y el enlace no funciona)
                array_walk_recursive($decodificado, function (&$value) {
                    if (is_string($value)) {
                        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                });

                return $decodificado;
            }

            self::log("Respuesta malformada al interpretar JSON de contenido: {$content}", 'ERROR');
            return ['error' => 'Respuesta malformada en el contenido de la IA'];
        }

        self::log("No se encontró 'choices[0].message.content' en la respuesta", 'ERROR');
        return ['error' => 'No se pudo interpretar la respuesta'];
    }


    /**
     * Llama al modelo para obtener la categoría principal, tipo de producto, categoría precio y target a partir de datos del producto.
     *
     * @param string $nombre Nombre del producto.
     * @param string $descripcion Descripción del producto.
     * @param float|string $precio Precio con IVA.
     * @param array $categorias Lista de categorías principales (con id y name).
     * @param array $tipos Lista de tipos de producto (con id y nombre).
     *
     * @return array Resultado interpretado por el modelo, o array con clave 'error'.
     */
    public static function obtenerCategoriaPrincipalTipoPrecioTarget($nombre, $descripcion, $precio, $categorias, $tipos)
    {
        //estructuramos las variables que se añadirán al prompt de sitema con id - nombre
        $categorias_str = implode(', ', array_map(function ($cat) {
            return "{$cat['id_category']} - {$cat['name']}";
        }, $categorias));

        $tipos_str = implode(', ', array_map(function ($tipo) {
            return "{$tipo['id_feature_value']} - {$tipo['value']}";
        }, $tipos));

        $user_context = "Título del producto: {$nombre}\nPrecio del producto: {$precio} €\nDescripción del producto: {$descripcion}";

        return self::apiCallDesdePrompt(
            'productos',
            'clasificacion_cat_principal_precio_tipo_target',
            $user_context,
            [
                'categorias' => $categorias_str,
                'tipos' => $tipos_str
            ]
        );
    }

    /**
     * Obtiene las subcategorías más adecuadas (hijas de la categoría principal) para un producto dado,
     * utilizando el prompt guardado en la base de datos y la API de OpenAI.
     *
     * @param string $nombre Nombre del producto.
     * @param string $descripcion Descripción del producto.
     * @param array $subcategorias Array de subcategorías posibles (de la categoría principal), con claves 'id' y 'name'.
     *     
     */
    public static function obtenerSubcategoriasPrincipal($nombre, $descripcion, $subcategorias)
    {
        // self::log("Subcategorías principal crudas: " . print_r($subcategorias, true), 'DEBUG');

        // Construir listado tipo "12 - Camisetas, 14 - Sudaderas"
        $subcategorias_str = implode(', ', array_map(function ($cat) {
            return "{$cat['id_category']} - {$cat['name']}";
        }, $subcategorias));

        // self::log("subcategorias_str: " . $subcategorias_str, 'DEBUG');

        // Contexto del usuario para el modelo
        $user_context = "Título del producto: {$nombre}\nDescripción del producto: {$descripcion}";

        return self::apiCallDesdePrompt(
            'productos',
            'clasificacion_subcategorias_principal',
            $user_context,
            [
                'subcategorias_principal' => $subcategorias_str
            ]
        );
    }

    /**
     * Obtiene las categorías funcionales más adecuadas para regalar un producto.
     * Utiliza un prompt personalizado desde la tabla 'lafrips_openai_prompts' (grupo 'productos') dependiendo del target llama a nombre 'clasificacion_categorias_regalar_adulto' o a 'clasificacion_categorias_regalar_kids_cosplay'.
     *
     * @param string $nombre               Nombre del producto.
     * @param string $descripcion          Descripción del producto.
     * @param array  $categorias_regalar   Array de categorías posibles (cada una con 'id' y 'name').   
     * @param string $target               Target (ADULTO, KIDS, COSPLAY)  
     */
    public static function obtenerCategoriasRegalar($nombre, $descripcion, $target, $categorias_regalar = null)
    {
        // 1. Formatear lista de categorías en formato "ID - Nombre".
        if ($categorias_regalar) {
            $categorias_str = implode(', ', array_map(function ($cat) {
                return "{$cat['id_category']} - {$cat['name']}";
            }, $categorias_regalar));
        }

        // 2. Preparar mensaje del usuario (input del producto)
        $user_context = "Título del producto: {$nombre}\nDescripción del producto: {$descripcion}";

        // 3. Llamar a la API usando el prompt adecuado según el target
        if ($target == 'ADULTO') {
            return self::apiCallDesdePrompt(
                'productos',
                'clasificacion_categorias_regalar_adulto',
                $user_context,
                []
            );
        } else {
            return self::apiCallDesdePrompt(
                'productos',
                'clasificacion_categorias_regalar_kids_cosplay',
                $user_context,
                [
                    'categorias_regalar' => $categorias_str
                ]
            );
        }
    }


    /**
     * Obtiene las subcategorías funcionales más adecuadas para regalar un producto.
     * Utiliza un prompt personalizado desde la tabla 'lafrips_openai_prompts' (grupo 'productos', nombre 'clasificacion_subcategorias_regalar').
     *
     * @param string $nombre               Nombre del producto.
     * @param string $descripcion          Descripción del producto.
     * @param array  $categorias_regalar   Array de categorías posibles (cada una con 'id' y 'name').     
     */
    public static function obtenerSubCategoriasRegalar($nombre, $descripcion, $subcategorias_regalar)
    {
        // self::log("Subcategorías regalar crudas: " . print_r($subcategorias_regalar, true), 'DEBUG');

        $subcats_regalar_str = implode(', ', array_map(function ($subcat) {
            return "{$subcat['id_category']} - {$subcat['name']}";
        }, $subcategorias_regalar));

        // self::log("subcats_regalar_str: " . $subcats_regalar_str, 'DEBUG');

        $user_context = "Título del producto: {$nombre}\nDescripción del producto: {$descripcion}";

        return self::apiCallDesdePrompt(
            'productos',
            'clasificacion_subcategorias_regalar',
            $user_context,
            ['subcategorias_regalar' => $subcats_regalar_str]
        );
    }

    public static function obtenerCategoriasAmazon($info, $cats_amazon)
    {
        // self::log("Categorías Amazon crudas: " . print_r($cats_amazon, true), 'DEBUG');

        // Construir listado tipo "2374 - No Subir Amazon, 2356 - Nuevos Amazon"
        $cats_amazon_str = implode(', ', array_map(function ($cat) {
            return "{$cat['id_category']} - {$cat['name']}";
        }, $cats_amazon));

        // self::log("cats_amazon: " . $subcategorias_str, 'DEBUG');

        $nombre = $info['nombre'];
        $descripcion = $info['descripcion'];
        $categoria_principal = $info['categoria_principal'];
        $proveedor = $info['proveedor'];
        $fabricante = $info['fabricante'];
        $tipo = $info['tipo'];
        $otras_categorias = implode(', ', array_filter([
            $info['subcategorias_principal'],
            $info['categorias_regalar'],
            $info['subcategorias_regalar']
        ]));

        // Contexto del usuario para el modelo
        $user_context = "Título del producto: {$nombre}\nDescripción del producto: {$descripcion}\nCategoría principal: {$categoria_principal}\nProveedor: {$proveedor}\nFabricante: {$fabricante}\nTipo de producto: {$tipo}\nOtras categorías: {$otras_categorias}";

        return self::apiCallDesdePrompt(
            'productos',
            'clasificacion_categorias_amazon',
            $user_context,
            [
                'categorias_amazon' => $cats_amazon_str 
            ]
        );
    }

    public static function obtenerTarget($info)
    {              
        // self::log("cats_amazon: " . $subcategorias_str, 'DEBUG');

        $nombre = $info['nombre'];
        $descripcion = $info['description_short'];
        $categoria_principal = $info['categoria_principal'];
        $combinaciones = $info['combinaciones'];
        $marca = $info['marca'];
        $tipo = $info['tipo_producto'];
        $otras_categorias = $info['otras_categorias'];
        $precio = $info['precio'];

        // Contexto del usuario para el modelo
        $user_context = "Detalles del producto:\n Título del producto: {$nombre}\nDescripción del producto: {$descripcion}\nCategoría principal: {$categoria_principal}\nOtras categorías: {$otras_categorias}\nMarca: {$marca}\nTipo de producto: {$tipo}\nPrecio: {$precio}";

        if (is_string($combinaciones) && trim($combinaciones) !== '') {
            $user_context .= "\nCombinaciones o atributos: {$combinaciones}";
        }

        return self::apiCallDesdePrompt(
            'productos',
            'clasificacion_target',
            $user_context,
            []
        );
    }

    public static function obtenerTextoSeoCategoria($json_links_y_nombres_categoria)
    {
        $variables = [
            'json_links_y_nombres_categoria' => $json_links_y_nombres_categoria
        ];

        $user_context = ''; // Este prompt no necesita user prompt

        return self::apiCallDesdePrompt(
            'productos',
            'clasificacion_seo_descripcion_larga',
            $user_context,
            $variables
        );

    }

    //esta función devolverá textos para meta títulos y meta descripciones si es necesario y atributo alt para todos. Se llama desde MetaSeoOptimizer y la usamos para revisar todos los productos viejos que tenían precio en el meta título y sus imágenes que ninguna tenía bien el atributo alt. Si el producto en análisis tiene algún precio en el meta título, procesamos las tres cosas, sino solo el atributo alt
    public static function optimizarMetaSeo($nombre, $descripcion, $conPrecio = false)
    {
        // Construir el contexto que se enviará como mensaje de usuario
        $user_context = "Nombre del producto: {$nombre}\nDescripción del producto: {$descripcion}";

        // self::log("Llamada optimizarMetaSeo con contexto (user_context):\n{$user_context}", 'DEBUG');

        // Determinar el nombre del prompt según si hay precio en el meta título
        $prompt_nombre = $conPrecio ? 'optimizar_metadatos_y_atributos_alt' : 'optimizar_atributos_alt';

        // Llamada a OpenAI mediante el sistema unificado
        $resultado = self::apiCallDesdePrompt(
            'productos',            // grupo
            $prompt_nombre,         // nombre del prompt
            $user_context,          // input del usuario
            []                      // sin variables dinámicas
        );

        // Registrar el resultado bruto
        // self::log("Resultado optimizarMetaSeo (conPrecio={$conPrecio}): " . json_encode($resultado), 'DEBUG');

        return $resultado;
    }

}