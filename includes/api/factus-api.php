<?php
// Función para refrescar el token usando el refresh_token
function factuspress_refresh_access_token() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'factuspress_config';
    $config = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );

    if ( ! $config ) {
        error_log('FactuPress: No se encontraron datos de configuración para refrescar el token.');
        return false;
    }

    // 1) Obtener el modo (sandbox o production)
    $env_mode      = isset($config->env_mode) ? $config->env_mode : 'sandbox';
    $refresh_token = $config->refresh_token;
    $client_id     = $config->client_id;
    $client_secret = $config->client_secret;

    // 2) Escoger URL según modo
    if ( $env_mode === 'production' ) {
        // URL para producción
        $url = 'https://api.factus.com.co/oauth/token';
    } else {
        // Por defecto sandbox
        $url = 'https://api-sandbox.factus.com.co/oauth/token';
    }

    // 3) Preparar la solicitud
    $body = array(
        'grant_type'    => 'refresh_token',
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
    );

    $response = wp_remote_post( $url, array(
        'method'  => 'POST',
        'body'    => $body,
        'headers' => array( 'Accept' => 'application/json' ),
    ));

    if ( is_wp_error( $response ) ) {
        error_log('FactuPress: Error de conexión al refrescar token: ' . $response->get_error_message());
        return false;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    // 4) Guardar el nuevo token
    if ( isset( $data['access_token'] ) ) {
        $access_token   = sanitize_text_field( $data['access_token'] );
        $new_ref_token  = sanitize_text_field( $data['refresh_token'] );
        $token_type     = sanitize_text_field( $data['token_type'] );
        $expires_in     = (int) $data['expires_in'];
        $token_expiry   = date('Y-m-d H:i:s', time() + $expires_in);

        $wpdb->update(
            $table_name,
            array(
                'access_token'  => $access_token,
                'refresh_token' => $new_ref_token,
                'token_type'    => $token_type,
                'expires_in'    => $expires_in,
                'token_expiry'  => $token_expiry,
            ),
            array( 'id' => 1 ),
            array( '%s', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );

        error_log('FactuPress: Token refrescado correctamente');
        return true;
    } else {
        error_log('FactuPress: Error de autenticación al refrescar token');
        return false;
    }
}

// Función para obtener las facturas desde la API
function factuspress_get_invoices( $args = array(), $retry = true ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'factuspress_config';
    $config = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );

    if ( ! $config ) {
        error_log('FactuPress: No se encontraron datos de configuración en la base de datos personalizada.');
        return;
    }

    // 1) ¿Token vencido?
    $now          = time();
    $token_expiry = isset($config->token_expiry) ? strtotime($config->token_expiry) : 0;

    // Si la fecha de expiración es anterior al momento actual, refrescar
    if ( $now >= $token_expiry ) {
        error_log('FactuPress: El token ha expirado. Intentando refrescar...');
        $refreshed = factuspress_refresh_access_token();

        // Vuelve a leer la BD (por si se actualizó exitosamente)
        if ( $refreshed ) {
            $config = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );
        } else {
            // Si no se pudo refrescar, devolvemos (o podrías retornar un mensaje de error)
            return;
        }
    }

    // 2) Nuevo access_token
    $access_token = $config->access_token;

    // 2.1) Determinar si estamos en producción o sandbox
    $env_mode = isset($config->env_mode) ? $config->env_mode : 'sandbox';

    // 3) Valor por defecto de los argumentos
    $defaults = array(
        'page'             => 1,
        'identification'   => '',
        'names'            => '',
        'number'           => '',
        'prefix'           => '',
        'reference_code'   => '',
        'status'           => '',
    );
    $args = wp_parse_args( $args, $defaults );

    // 4) Construye la URL base según env_mode
    if ( $env_mode === 'production' ) {
        $base_url = 'https://api.factus.com.co/v1/bills';
    } else {
        $base_url = 'https://api-sandbox.factus.com.co/v1/bills';
    }

    // 5) Prepara un array con los parámetros de query
    $query = array(
        'page' => absint($args['page']),
    );
    if ( ! empty( $args['identification'] ) ) {
        $query['filter[identification]'] = $args['identification'];
    }
    if ( ! empty( $args['names'] ) ) {
        $query['filter[names]'] = $args['names'];
    }
    if ( ! empty( $args['number'] ) ) {
        $query['filter[number]'] = $args['number'];
    }
    if ( ! empty( $args['prefix'] ) ) {
        $query['filter[prefix]'] = $args['prefix'];
    }
    if ( ! empty( $args['reference_code'] ) ) {
        $query['filter[reference_code]'] = $args['reference_code'];
    }
    if ( $args['status'] !== '' ) {
        $query['filter[status]'] = $args['status'];
    }

    // 6) Generamos la URL final
    $url = add_query_arg( $query, $base_url );

    // 7) Llamada
    error_log('FactuPress: Enviando solicitud a la API - URL: ' . $url);

    $response = wp_remote_get( $url, array(
        'headers' => array(
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
        ),
    ));

    if ( is_wp_error( $response ) ) {
        error_log('FactuPress: Error en la solicitud a la API: ' . $response->get_error_message());
        return;
    }

    // 8) Chequeamos código de estado
    $status_code = wp_remote_retrieve_response_code( $response );
    $body        = wp_remote_retrieve_body( $response );
    error_log('FactuPress: Respuesta de la API: ' . $body);

    // Si es "401 Unauthorized" o "403 forbidden", intentamos refrescar token solo 1 vez
    if ( in_array( $status_code, array(401, 403), true ) ) {
        error_log('FactuPress: Respuesta Unauthorized/Forbidden. Intentando refrescar el token y reintentar...');
        if ( $retry ) {
            $refreshed = factuspress_refresh_access_token();
            if ( $refreshed ) {
                // Al refrescar, volvemos a intentarlo una sola vez
                return factuspress_get_invoices( $args, false ); 
            }
        }
        // Si ya refrescamos una vez y sigue fallando, o no se pudo refrescar:
        return;
    }

    // 9) Decodificar
    $data = json_decode( $body, true );
    if ( ! isset($data['data']['data']) ) {
        error_log('FactuPress: No se encontraron facturas o datos incorrectos.');
        return;
    }

    return $data;
}

// Función para descargar PDF
add_action('admin_post_factuspress_download_pdf', 'factuspress_handle_download_pdf');
add_action('admin_post_nopriv_factuspress_download_pdf', 'factuspress_handle_download_pdf');
/*
 * admin_post_ HOOK:
 *  - 'admin_post_...' se ejecuta si estás logueado en wp-admin
 *  - 'admin_post_nopriv_...' se ejecuta si NO estás logueado (opcional, por si lo necesitas en front-end)
 */

 function factuspress_handle_download_pdf() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos para esto.');
    }

    if ( empty($_GET['invoice_number']) ) {
        wp_die('No se definió el número de factura.');
    }

    $invoice_number = sanitize_text_field( $_GET['invoice_number'] );

    global $wpdb;
    $table_name = $wpdb->prefix . 'factuspress_config';
    $config = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );
    if ( ! $config ) {
        wp_die('No se encontraron credenciales FactusPress.');
    }

    $env_mode = isset($config->env_mode) ? $config->env_mode : 'sandbox';
    if ( $env_mode === 'production' ) {
        $url = 'https://api.factus.com.co/v1/bills/download-pdf/' . $invoice_number;
    } else {
        $url = 'https://api-sandbox.factus.com.co/v1/bills/download-pdf/' . $invoice_number;
    }

    $access_token = $config->access_token;

    // Aquí registramos TODA la información
    error_log('FactuPress: Iniciando descarga PDF para invoice_number: ' . $invoice_number);
    error_log('FactuPress: URL => ' . $url);
    error_log('FactuPress: Access Token => ' . $access_token);

    // Preparamos los headers para el request
    $headers = array(
        'Accept'        => 'application/json',
        'Authorization' => 'Bearer ' . $access_token,
    );

    // Registramos los headers
    error_log('FactuPress: Headers => ' . print_r($headers, true));

    // Realizamos la solicitud (POST con body vacío, tal y como indica tu cURL)
    $response = wp_remote_get( $url, array(
        'headers' => $headers,
        'body'    => '', // o array()
    ));

    // Verificar si wp_remote_get devolvió un error de conexión
    if ( is_wp_error($response) ) {
        error_log('FactuPress: Error de conexión => ' . $response->get_error_message());
        wp_die('Error al conectar con la API: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    // Registramos el código HTTP y el body devuelto
    error_log('FactuPress: Respuesta HTTP code => ' . $code);
    error_log('FactuPress: Respuesta Body => ' . $body);

    if ( $code !== 200 ) {
        // Error devuelto por la API
        wp_die('Error al descargar PDF. Respuesta HTTP: ' . $code . ' => ' . $body);
    }

    // Parseamos JSON
    $data = json_decode($body, true);
    if ( ! isset($data['data']['pdf_base_64_encoded']) ) {
        wp_die('No se encontró el PDF en la respuesta de la API.');
    }

    $pdf_base64 = $data['data']['pdf_base_64_encoded'];
    $pdf_binary = base64_decode($pdf_base64);
    $filename   = isset($data['data']['file_name']) ? $data['data']['file_name'] . '.pdf' : 'factura_'.$invoice_number.'.pdf';

    // Mostramos el PDF en el navegador (inline)
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');

    echo $pdf_binary;
    exit;
}

// Función para descargar XML
add_action('admin_post_factuspress_download_xml', 'factuspress_handle_download_xml');
add_action('admin_post_nopriv_factuspress_download_xml', 'factuspress_handle_download_xml');
// La segunda línea es si quisieras que usuarios no logueados también puedan descargar (opcional).

function factuspress_handle_download_xml() {
    // Verificar permisos (opcional)
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos para esto.');
    }

    // Verificar invoice_number
    if ( empty($_GET['invoice_number']) ) {
        wp_die('No se definió el número de factura (invoice_number).');
    }
    $invoice_number = sanitize_text_field( $_GET['invoice_number'] );

    global $wpdb;
    $table_name = $wpdb->prefix . 'factuspress_config';
    $config     = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );

    if ( ! $config ) {
        wp_die('No se encontraron credenciales de FactuPress en la BD.');
    }

    // Leer env_mode
    $env_mode = isset($config->env_mode) ? $config->env_mode : 'sandbox';
    if ( $env_mode === 'production' ) {
        $url = 'https://api.factus.com.co/v1/bills/download-xml/' . $invoice_number;
    } else {
        $url = 'https://api-sandbox.factus.com.co/v1/bills/download-xml/' . $invoice_number;
    }

    // Access token
    $access_token = $config->access_token;

    // Log de depuración
    error_log('FactuPress XML: Iniciando descarga XML para invoice_number=' . $invoice_number);
    error_log('FactuPress XML: URL => ' . $url);
    error_log('FactuPress XML: Access Token => ' . $access_token);

    // Importante: Hacemos GET (la API requiere GET, no POST).
    $response = wp_remote_get( $url, array(
        'headers' => array(
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
        ),
    ));

    // Error de conexión
    if ( is_wp_error($response) ) {
        error_log('FactuPress XML: Error de conexión => ' . $response->get_error_message());
        wp_die('Error de conexión con la API Factus: ' . $response->get_error_message());
    }

    // Respuesta HTTP
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    error_log('FactuPress XML: HTTP code => ' . $code);
    error_log('FactuPress XML: Body => ' . $body);

    if ( $code !== 200 ) {
        wp_die('Error al descargar XML. Respuesta HTTP=' . $code . ' => ' . $body);
    }

    // Parsear JSON
    $data = json_decode($body, true);
    if ( ! isset($data['data']['xml_base_64_encoded']) ) {
        wp_die('No se encontró el XML en la respuesta (xml_base_64_encoded).');
    }

    // Decodificar
    $xml_base64 = $data['data']['xml_base_64_encoded'];
    $xml_string = base64_decode($xml_base64);

    // Nombre de archivo (opcional). A veces la API trae un "file_name" genérico
    $filename = isset($data['data']['file_name']) ? $data['data']['file_name'] . '.xml' : 'factura_'.$invoice_number.'.xml';

    // Enviar headers
    //  - Si quieres forzar descarga => 'attachment'
    //  - Si quieres ver en el browser => 'inline'
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');

    // Enviamos el contenido del XML
    echo $xml_string;
    exit;
}

// Función para obtener eliminar una factura
add_action('admin_post_factuspress_delete_invoice', 'factuspress_handle_delete_invoice');
add_action('admin_post_nopriv_factuspress_delete_invoice', 'factuspress_handle_delete_invoice');
// (nopriv solo si quisieras permitirlo sin login, normalmente no)

function factuspress_handle_delete_invoice() {
    // Verificamos permisos (opcional)
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos para eliminar facturas.');
    }

    // Verificar reference_code
    if ( empty($_GET['reference_code']) ) {
        wp_die('No se definió el reference_code de la factura.');
    }
    $reference_code = sanitize_text_field( $_GET['reference_code'] );

    // Tomar credenciales de tu BD
    global $wpdb;
    $table_name = $wpdb->prefix . 'factuspress_config';
    $config = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );
    if ( ! $config ) {
        wp_die('No se encontraron credenciales FactuPress en la BD.');
    }

    $env_mode = isset($config->env_mode) ? $config->env_mode : 'sandbox';
    if ( $env_mode === 'production' ) {
        $url = 'https://api.factus.com.co/v1/bills/destroy/reference/' . $reference_code;
    } else {
        $url = 'https://api-sandbox.factus.com.co/v1/bills/destroy/reference/' . $reference_code;
    }

    $access_token = $config->access_token;

    // Hacer solicitud DELETE
    $response = wp_remote_request( $url, array(
        'method'  => 'DELETE',
        'headers' => array(
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
        ),
        'body' => '',
    ));

    if ( is_wp_error($response) ) {
        // Error de conexión
        $error = $response->get_error_message();
        wp_redirect( add_query_arg('factuspress_msg', urlencode("Error de conexión: $error"), admin_url('admin.php?page=factuspress_invoices')) );
        exit;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body        = wp_remote_retrieve_body($response);
    $data        = json_decode($body, true);

    if ( $status_code === 200 ) {
        // Si la API responde con éxito, se espera un JSON similar a:
        // {"status": "OK", "message": "Documento con código de referencia <reference_code> eliminado con éxito"}
        if (
            isset($data['status']) && $data['status'] === 'OK' &&
            isset($data['message']) &&
            preg_match('/^Documento con código de referencia .+ eliminado con éxito$/', $data['message'])
        ) {
            // Redirigimos sin mensaje personalizado para que se muestre el aviso estándar de WordPress.
            wp_redirect( admin_url('admin.php?page=factuspress_invoices') );
            exit;
        } else {
            // Si la respuesta es diferente, mostramos el mensaje de la API.
            $msg = isset($data['message']) ? $data['message'] : "La factura se eliminó, pero la respuesta no fue la esperada.";
            wp_redirect( add_query_arg('factuspress_msg', urlencode($msg), admin_url('admin.php?page=factuspress_invoices')) );
            exit;
        }
    } else {
        // En el caso de error en la eliminación
        $msg = isset($data['message']) ? $data['message'] : "Error al eliminar factura. HTTP $status_code => $body";
        wp_redirect( add_query_arg( array(
            'factuspress_msg'    => urlencode($msg),
            'factuspress_notice' => 'error'
        ), admin_url('admin.php?page=factuspress_invoices') ) );
        exit;
    }
}
/**
 * Función para enviar la factura a la API de Factus.
 *
 * @param array $body El arreglo con la estructura de la factura, siguiendo la documentación de Factus.
 */
function factuspress_call_api_to_create_invoice( $body ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'factuspress_config';

    // Obtener la configuración (se asume que la fila con id=1 contiene los datos)
    $config = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );
    if ( ! $config ) {
        error_log('FactuPress: No se encontró configuración en la base de datos.');
        return;
    }

    // Verificar si el token ha expirado
    $now = current_time('timestamp');
    $token_expiry = strtotime( $config->token_expiry );
    if ( $now >= $token_expiry ) {
        if ( ! factuspress_refresh_access_token() ) {
            error_log('FactuPress: Error al refrescar token.');
            return;
        }
        // Recargar la configuración después de refrescar el token
        $config = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );
        if ( ! $config ) {
            error_log('FactuPress: No se pudo obtener configuración actualizada tras refresco.');
            return;
        }
    }

    // Seleccionar el endpoint según el modo (sandbox o producción)
    $url = ( $config->env_mode === 'production' )
        ? 'https://api.factus.com.co/v1/bills/validate'
        : 'https://api-sandbox.factus.com.co/v1/bills/validate';

    // Registro de debug: Loguear el body que se envía
    error_log('FactuPress - Enviando a Factus API: ' . wp_json_encode($body));

    // Realizar la solicitud POST a la API
    $response = wp_remote_post( $url, array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $config->access_token,
            'Accept'        => 'application/json',
        ),
        'body'    => wp_json_encode( $body ),
        'timeout' => 60,
    ));

    // Registro de debug: Verificar si hay errores en la conexión
    if ( is_wp_error( $response ) ) {
        error_log('FactuPress ERROR: ' . $response->get_error_message());
        return;
    }

    // Obtener el código y cuerpo de la respuesta
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    // Registro de debug: Loguear la respuesta completa
    error_log('FactuPress - Respuesta API (Código ' . $response_code . '): ' . $response_body);

    if ( $response_code === 200 || $response_code === 201 ) {
        error_log('FactuPress ÉXITO: ' . $response_body);
    } else {
        error_log('FactuPress ERROR CODE: ' . $response_code);
        error_log('FactuPress ERROR BODY: ' . $response_body);
    }
}