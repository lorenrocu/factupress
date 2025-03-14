<?php
/**
 * Obtiene la configuración de FactusPress desde la base de datos.
 *
 * @return object|false Objeto de configuración o false si no se encuentra.
 */
function factuspress_get_config() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'factuspress_config';
	return $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );
}

/**
 * Verifica si el token ha expirado y, en ese caso, lo refresca.
 *
 * @param object &$config Objeto de configuración (se actualiza si es necesario).
 * @return bool True si el token es válido o se pudo refrescar, false en caso de error.
 */
function factuspress_verify_token( &$config ) {
	if ( ! $config ) {
		error_log( 'FactusPress: Configuración no encontrada.' );
		return false;
	}
	$now = current_time( 'timestamp' );
	$token_expiry = strtotime( $config->token_expiry );
	if ( $now >= $token_expiry ) {
		if ( ! factuspress_refresh_access_token() ) {
			error_log( 'FactusPress: Error al refrescar token.' );
			return false;
		}
		// Recargar la configuración actualizada tras el refresco
		$config = factuspress_get_config();
		if ( ! $config ) {
			error_log( 'FactusPress: No se pudo obtener configuración actualizada tras refresco.' );
			return false;
		}
	}
	return true;
}

/**
 * Consulta la API de Factus para obtener la lista de municipios y la cachea en un transient.
 *
 * @return array Lista de municipios o array vacío en caso de error.
 */
function factuspress_get_municipios_data() {
	$transient_key  = 'factuspress_municipios';
	$municipios_data = get_transient( $transient_key );
	if ( false !== $municipios_data ) {
		return $municipios_data;
	}

	$config = factuspress_get_config();
	if ( ! factuspress_verify_token( $config ) ) {
		return array();
	}

	// Seleccionar el endpoint según el modo (producción o sandbox)
	$url = ( $config->env_mode === 'production' )
		? 'https://api.factus.com.co/v1/municipalities'
		: 'https://api-sandbox.factus.com.co/v1/municipalities';

	$args = array(
		'headers' => array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $config->access_token,
		),
		'timeout' => 30,
	);

	$response = wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) ) {
		error_log( 'FactusPress: Error obteniendo municipios: ' . $response->get_error_message() );
		return array();
	}

	$body    = wp_remote_retrieve_body( $response );
	$decoded = json_decode( $body, true );
	if ( isset( $decoded['status'] ) && 'OK' === $decoded['status'] && isset( $decoded['data'] ) ) {
		$municipios_data = $decoded['data'];
		// Cachear la respuesta por 12 horas (43200 segundos)
		set_transient( $transient_key, $municipios_data, 43200 );
	} else {
		error_log( 'FactusPress: Respuesta inesperada en municipios: ' . $body );
		$municipios_data = array();
	}
	return $municipios_data;
}
